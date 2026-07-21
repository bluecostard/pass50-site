<?php
declare(strict_types=1);
$configPath = __DIR__ . '/api/config.php';
$schemaPath = __DIR__ . '/schema.sql';
$installed = is_file($configPath);
$error = '';
$success = false;

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
    $baseUrl = rtrim(trim((string)($_POST['base_url'] ?? '')), '/');
    $dbHost = trim((string)($_POST['db_host'] ?? ''));
    $dbPort = (int)($_POST['db_port'] ?? 3306);
    $dbName = trim((string)($_POST['db_name'] ?? ''));
    $dbUser = trim((string)($_POST['db_user'] ?? ''));
    $dbPassword = (string)($_POST['db_password'] ?? '');
    $brevoKey = trim((string)($_POST['brevo_key'] ?? ''));
    $senderEmail = trim((string)($_POST['sender_email'] ?? ''));
    $senderName = trim((string)($_POST['sender_name'] ?? 'PASS50'));

    try {
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) throw new RuntimeException('Adresse du site invalide.');
        if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Adresse d’envoi invalide.');
        if ($dbHost === '' || $dbName === '' || $dbUser === '' || $dbPassword === '') throw new RuntimeException('Informations MySQL incomplètes.');
        if ($brevoKey === '') throw new RuntimeException('Clé API Brevo manquante.');
        if (!extension_loaded('pdo_mysql')) throw new RuntimeException('L’extension PHP pdo_mysql n’est pas active.');
        if (!function_exists('curl_init')) throw new RuntimeException('L’extension PHP cURL n’est pas active.');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
        $pdo = new PDO($dsn, $dbUser, $dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schema = file_get_contents($schemaPath);
        if ($schema === false) throw new RuntimeException('Fichier schema.sql introuvable.');
        $statements = preg_split('/;\s*(?:\r?\n|$)/', $schema);
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement !== '') $pdo->exec($statement);
        }

        $config = [
            'app' => [
                'base_url' => $baseUrl,
                'name' => 'PASS50',
                'session_days' => 30,
                'confirmation_hours' => 24,
        'reset_hours' => 1,
                'show_confirmation_link_in_response' => false,
            ],
            'db' => [
                'host' => $dbHost,
                'port' => $dbPort,
                'name' => $dbName,
                'user' => $dbUser,
                'password' => $dbPassword,
                'charset' => 'utf8mb4',
            ],
            'brevo' => [
                'api_key' => $brevoKey,
                'sender_email' => $senderEmail,
                'sender_name' => $senderName ?: 'PASS50',
            ],
            'upload' => [
                'max_bytes' => 5 * 1024 * 1024,
                'allowed_mime' => ['image/jpeg', 'image/png', 'image/webp'],
            ],
        ];
        $php = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents($configPath, $php, LOCK_EX) === false) throw new RuntimeException('Impossible de créer api/config.php. Vérifiez les droits du dossier api.');
        @chmod($configPath, 0640);
        $success = true;
        $installed = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$detectedUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'votre-domaine.fr') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Installation PASS50</title>
<style>body{margin:0;background:#050705;color:#f6f8f4;font-family:Arial,sans-serif}.wrap{max-width:720px;margin:35px auto;padding:20px}.box{background:#0d110d;border:1px solid #293129;border-radius:22px;padding:24px}.logo{font-size:34px;font-weight:900}.logo span{color:#b7ff00}h1{margin:7px 0 20px}label{display:block;font-weight:bold;margin:13px 0 6px}input{width:100%;box-sizing:border-box;padding:13px;border-radius:11px;border:1px solid #394239;background:#111611;color:white}.grid{display:grid;grid-template-columns:1fr 120px;gap:10px}.btn{margin-top:20px;width:100%;border:0;border-radius:12px;padding:14px;background:#b7ff00;color:#050705;font-weight:900;cursor:pointer}.note,.error,.ok{padding:13px;border-radius:12px;margin:12px 0}.note{background:#141914;color:#cbd3c8}.error{background:#351111;color:#ffb1b1}.ok{background:#132b12;color:#cfff9c}a{color:#b7ff00}</style></head><body><div class="wrap"><div class="box"><div class="logo"><span>3</span>JOURS</div><h1>Installation IONOS</h1>
<?php if ($success): ?><div class="ok"><strong>Installation terminée.</strong><br>La base MySQL et la connexion Brevo sont configurées.</div><p><a href="./">Ouvrir PASS50</a></p><div class="note">Supprime maintenant le fichier <strong>install.php</strong> depuis ton espace IONOS.</div>
<?php elseif ($installed): ?><div class="ok"><strong>Le site est déjà configuré.</strong></div><p><a href="./">Ouvrir PASS50</a></p><div class="note">Par sécurité, supprime le fichier <strong>install.php</strong>.</div>
<?php else: ?>
<?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
<div class="note">Renseigne les informations affichées dans IONOS et Brevo. Cette page crée automatiquement la structure MySQL et le fichier de configuration.</div>
<form method="post">
<label>Adresse publique du site</label><input name="base_url" value="<?= h($_POST['base_url'] ?? $detectedUrl) ?>" required>
<label>Serveur MySQL IONOS</label><div class="grid"><input name="db_host" placeholder="dbXXXX.hosting-data.io" value="<?= h($_POST['db_host'] ?? '') ?>" required><input name="db_port" type="number" value="<?= h($_POST['db_port'] ?? '3306') ?>" required></div>
<label>Nom de la base</label><input name="db_name" placeholder="dbsXXXXXXXX" value="<?= h($_POST['db_name'] ?? '') ?>" required>
<label>Utilisateur de la base</label><input name="db_user" placeholder="dbuXXXXXXXX" value="<?= h($_POST['db_user'] ?? '') ?>" required>
<label>Mot de passe MySQL</label><input name="db_password" type="password" required>
<label>Clé API Brevo</label><input name="brevo_key" type="password" placeholder="xkeysib-..." required>
<label>Adresse d’envoi confirmée dans Brevo</label><input name="sender_email" type="email" placeholder="contact@votre-domaine.fr" value="<?= h($_POST['sender_email'] ?? '') ?>" required>
<label>Nom de l’expéditeur</label><input name="sender_name" value="<?= h($_POST['sender_name'] ?? 'PASS50') ?>" required>
<button class="btn" type="submit">Installer PASS50</button>
</form><?php endif; ?></div></div></body></html>
