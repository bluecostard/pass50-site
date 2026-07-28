<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'configLoaded' => false,
        'diagnostic' => 'config_missing',
        'message' => 'Le fichier api/config.php est introuvable.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $config = require $configFile;
} catch (Throwable $e) {
    error_log('PASS50 config: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'configLoaded' => false,
        'diagnostic' => 'config_invalid',
        'message' => 'Le fichier api/config.php contient une erreur de syntaxe ou de structure.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!is_array($config)) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'configLoaded' => false,
        'diagnostic' => 'config_not_array',
        'message' => 'Le fichier api/config.php ne retourne pas un tableau PHP.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$requiredSections = ['app', 'db', 'brevo', 'upload'];
$missingSections = array_values(array_filter(
    $requiredSections,
    static fn(string $section): bool => !isset($config[$section]) || !is_array($config[$section])
));

if ($missingSections) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'configLoaded' => true,
        'diagnostic' => 'config_incomplete',
        'missingSections' => $missingSections,
        'message' => 'Une section obligatoire manque dans api/config.php.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$dbOk = false;
$dbMessage = null;
try {
    $d = $config['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        (string)($d['host'] ?? ''),
        (int)($d['port'] ?? 3306),
        (string)($d['name'] ?? ''),
        (string)($d['charset'] ?? 'utf8mb4')
    );
    $pdo = new PDO($dsn, (string)($d['user'] ?? ''), (string)($d['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->query('SELECT 1');
    $dbOk = true;
} catch (Throwable $e) {
    error_log('PASS50 health DB: ' . $e->getMessage());
    $dbMessage = 'Connexion MySQL impossible.';
}

$oauth = is_array($config['google_oauth'] ?? null) ? $config['google_oauth'] : [];
$oauthConfigured = trim((string)($oauth['client_id'] ?? '')) !== ''
    && trim((string)($oauth['client_secret'] ?? '')) !== ''
    && trim((string)($oauth['redirect_uri'] ?? '')) !== ''
    && trim((string)($oauth['token_encryption_key'] ?? '')) !== '';

http_response_code($dbOk ? 200 : 503);
echo json_encode([
    'ok' => $dbOk,
    'configLoaded' => true,
    'databaseConnected' => $dbOk,
    'googleOauthConfigured' => $oauthConfigured,
    'diagnostic' => $dbOk ? 'ok' : 'database_unavailable',
    'message' => $dbOk ? 'Configuration et base de données opérationnelles.' : $dbMessage,
    'phpVersion' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
