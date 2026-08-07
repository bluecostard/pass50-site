<?php
declare(strict_types=1);

require_once __DIR__.'/api/share-photo-core.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=90');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

function p50_audio_short_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function p50_audio_short_base(): string {
    $config = p50_share_photo_config();
    $base = rtrim((string)($config['app']['base_url'] ?? ''), '/');
    return preg_match('#^https://#i', $base) ? preg_replace('#^https://www\.pass50\.store#i', 'https://pass50.store', $base) : 'https://pass50.store';
}

$key = strtolower(trim((string)($_GET['k'] ?? '')));
if (!preg_match('/^[a-f0-9]{12}$/', $key)) {
    http_response_code(404);
    exit('Partage introuvable');
}

$pdo = p50_share_photo_pdo();
if (!$pdo) {
    http_response_code(503);
    exit('Partage temporairement indisponible');
}

try {
    $stmt = $pdo->prepare("SELECT p.*, u.display_name AS author_display_name
        FROM p50_duel_audio_posts p
        JOIN users u ON u.id=p.user_id AND u.deleted_at IS NULL
        WHERE LEFT(p.file_name,12)=? AND p.status='published' AND p.expires_at>UTC_TIMESTAMP()
        ORDER BY p.created_at DESC
        LIMIT 2");
    $stmt->execute([$key]);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('PASS50 audio short: '.$e->getMessage());
    $rows = [];
}

if (count($rows) !== 1) {
    http_response_code(404);
    exit('Partage introuvable');
}

$audio = $rows[0];
$base = p50_audio_short_base();
$author = trim((string)($audio['author_display_name'] ?? 'Membre PASS50')) ?: 'Membre PASS50';
$a = trim((string)($audio['candidate_a_name'] ?? 'Influenceur A')) ?: 'Influenceur A';
$b = trim((string)($audio['candidate_b_name'] ?? 'Influenceur B')) ?: 'Influenceur B';
$selectedId = (string)($audio['selected_profile_id'] ?? '');
$selected = $selectedId !== '' && $selectedId === (string)($audio['candidate_a_id'] ?? '') ? $a : $b;
$fileName = basename((string)($audio['file_name'] ?? ''));

$title = $author.' commente son vote pour '.$selected;
$description = 'Écoutez le commentaire audio du duel '.$a.' VS '.$b.' sur PASS50.';
$canonical = $base.'/a.php?k='.rawurlencode($key);
$image = $base.'/duel-audio-og-v1.php?k='.rawurlencode($key);
$destination = $base.'/?'.http_build_query([
    'source' => 'share_duel_audio',
    'section' => 'coules',
    'audio' => $fileName,
]);
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0b0f0b">
<title><?=p50_audio_short_h($title)?></title>
<meta name="description" content="<?=p50_audio_short_h($description)?>">
<link rel="canonical" href="<?=p50_audio_short_h($canonical)?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="PASS50">
<meta property="og:title" content="<?=p50_audio_short_h($title)?>">
<meta property="og:description" content="<?=p50_audio_short_h($description)?>">
<meta property="og:url" content="<?=p50_audio_short_h($canonical)?>">
<meta property="og:image" content="<?=p50_audio_short_h($image)?>">
<meta property="og:image:secure_url" content="<?=p50_audio_short_h($image)?>">
<meta property="og:image:type" content="image/png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Carte audio PASS50 Les Coulés">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?=p50_audio_short_h($title)?>">
<meta name="twitter:description" content="<?=p50_audio_short_h($description)?>">
<meta name="twitter:image" content="<?=p50_audio_short_h($image)?>">
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;background:#050705;color:#f6f8f4;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.card{width:min(620px,100%);padding:28px;border:1px solid #293129;border-radius:24px;background:#0d110d;box-shadow:0 24px 80px rgba(0,0,0,.45)}.brand{font-size:28px;font-weight:1000}.brand b{color:#b7ff00}.kicker{margin-top:24px;color:#b7ff00;font-size:12px;font-weight:1000;letter-spacing:.12em}.card h1{margin:10px 0 8px;font-size:clamp(32px,8vw,52px);line-height:.98;letter-spacing:-2px}.card p{color:#b8c1b5;font-size:16px;line-height:1.5}.cta{display:block;margin-top:26px;padding:17px 20px;border-radius:14px;background:#b7ff00;color:#050705;text-align:center;text-decoration:none;font-weight:1000;font-size:18px}
</style>
</head>
<body>
<main class="card"><div class="brand">PASS<b>50</b></div><div class="kicker">LES COULÉS · AUDIO</div><h1><?=p50_audio_short_h($a)?> VS <?=p50_audio_short_h($b)?></h1><p><?=p50_audio_short_h($author)?> commente son vote pour <strong><?=p50_audio_short_h($selected)?></strong>.</p><a class="cta" href="<?=p50_audio_short_h($destination)?>">▶ ÉCOUTER L’AUDIO</a></main>
<script>window.setTimeout(function(){location.replace(<?=json_encode($destination, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>)},700);</script>
</body>
</html>
