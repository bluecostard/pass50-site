<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$file = __DIR__ . '/data/live-status.json';
if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
if (!file_exists($file)) file_put_contents($file, json_encode(['liveStreams'=>[]], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

function readData(string $file): array {
    $raw = @file_get_contents($file);
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : ['liveStreams'=>[]];
}
function cleanStreams(array $streams): array {
    $now = time();
    return array_values(array_filter($streams, function($s) use ($now) {
        if (!is_array($s) || ($s['status'] ?? '') !== 'live') return false;
        if (!empty($s['endsAt'])) {
            $end = strtotime((string)$s['endsAt']);
            if ($end !== false && $end <= $now) return false;
        }
        return !empty($s['profileId']) && !empty($s['url']);
    }));
}

$data = readData($file);
$data['liveStreams'] = cleanStreams($data['liveStreams'] ?? []);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error'=>'Méthode non autorisée']);
    exit;
}

$expected = getenv('PASS50_LIVE_ADMIN_TOKEN') ?: '';
$provided = $_SERVER['HTTP_X_PASS50_TOKEN'] ?? '';
if ($expected === '' || !hash_equals($expected, $provided)) {
    http_response_code(403);
    echo json_encode(['error'=>'Accès refusé']);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body) || !isset($body['liveStreams']) || !is_array($body['liveStreams'])) {
    http_response_code(422);
    echo json_encode(['error'=>'Payload invalide']);
    exit;
}
$data = ['liveStreams'=>cleanStreams($body['liveStreams']), 'updatedAt'=>gmdate('c')];
$tmp = $file . '.tmp';
file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), LOCK_EX);
rename($tmp, $file);
echo json_encode(['ok'=>true,'count'=>count($data['liveStreams'])]);
