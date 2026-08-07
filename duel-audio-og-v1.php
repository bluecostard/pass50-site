<?php
declare(strict_types=1);

require_once __DIR__.'/api/share-photo-core.php';

$key = strtolower(trim((string)($_GET['k'] ?? '')));
if (!preg_match('/^[a-f0-9]{12}$/', $key)) {
    http_response_code(404);
    exit;
}

$pdo = p50_share_photo_pdo();
if (!$pdo) {
    header('Location: assets/pass50-og.png', true, 302);
    exit;
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
    error_log('PASS50 audio OG: '.$e->getMessage());
    $rows = [];
}

if (count($rows) !== 1 || !extension_loaded('gd')) {
    header('Location: assets/pass50-og.png', true, 302);
    exit;
}

$row = $rows[0];
$author = trim((string)($row['author_display_name'] ?? 'Membre PASS50')) ?: 'Membre PASS50';
$a = trim((string)($row['candidate_a_name'] ?? 'Influenceur A')) ?: 'Influenceur A';
$b = trim((string)($row['candidate_b_name'] ?? 'Influenceur B')) ?: 'Influenceur B';
$selectedId = (string)($row['selected_profile_id'] ?? '');
$selected = $selectedId !== '' && $selectedId === (string)($row['candidate_a_id'] ?? '') ? $a : $b;

function p50_audio_og_color(GdImage $image, string $hex): int {
    $hex = ltrim($hex, '#');
    return imagecolorallocate($image, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
}
function p50_audio_og_font(bool $bold = true): string {
    $path = $bold ? '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf' : '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
    return is_file($path) ? $path : '';
}
function p50_audio_og_text(GdImage $image, int $size, int $x, int $y, int $color, string $text, bool $bold = true): void {
    $font = p50_audio_og_font($bold);
    if ($font !== '' && function_exists('imagettftext')) imagettftext($image, $size, 0, $x, $y, $color, $font, $text);
    else imagestring($image, 5, $x, max(0, $y - $size), mb_substr($text, 0, 70), $color);
}
function p50_audio_og_fit(string $text, int $maxChars, int $maxLines = 2): array {
    $words = preg_split('/\s+/u', trim($text)) ?: [];
    $lines = [];
    $line = '';
    foreach ($words as $word) {
        $next = $line === '' ? $word : $line.' '.$word;
        if (mb_strlen($next) <= $maxChars) $line = $next;
        else {
            if ($line !== '') $lines[] = $line;
            $line = $word;
            if (count($lines) >= $maxLines - 1) break;
        }
    }
    if ($line !== '' && count($lines) < $maxLines) $lines[] = $line;
    return array_slice($lines, 0, $maxLines);
}
function p50_audio_og_initials(string $name): string {
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $value = '';
    foreach (array_slice($parts, 0, 2) as $part) $value .= mb_substr($part, 0, 1);
    return mb_strtoupper($value !== '' ? $value : 'P50', 'UTF-8');
}

$image = imagecreatetruecolor(1200, 630);
$bg = p50_audio_og_color($image, '#050705');
$panel = p50_audio_og_color($image, '#0d110d');
$line = p50_audio_og_color($image, '#293129');
$white = p50_audio_og_color($image, '#f6f8f4');
$muted = p50_audio_og_color($image, '#9da79b');
$lime = p50_audio_og_color($image, '#b7ff00');
$dark = p50_audio_og_color($image, '#0b0f0b');

imagefill($image, 0, 0, $bg);
imagefilledrectangle($image, 0, 0, 1200, 18, $lime);
imagefilledrectangle($image, 56, 46, 1144, 584, $panel);
imagesetthickness($image, 2);
imagerectangle($image, 56, 46, 1144, 584, $line);

p50_audio_og_text($image, 31, 82, 108, $white, 'PASS50');
p50_audio_og_text($image, 16, 82, 152, $lime, 'LES COULÉS · AUDIO');

$circleA = [220, 305];
$circleB = [470, 305];
imagefilledellipse($image, $circleA[0], $circleA[1], 176, 176, $dark);
imagefilledellipse($image, $circleB[0], $circleB[1], 176, 176, $dark);
imagesetthickness($image, 5);
imageellipse($image, $circleA[0], $circleA[1], 176, 176, $lime);
imageellipse($image, $circleB[0], $circleB[1], 176, 176, $lime);
p50_audio_og_text($image, 38, 180, 320, $white, p50_audio_og_initials($a));
p50_audio_og_text($image, 38, 430, 320, $white, p50_audio_og_initials($b));
p50_audio_og_text($image, 20, 325, 312, $lime, 'VS');

foreach (p50_audio_og_fit($a, 20, 2) as $i => $lineText) p50_audio_og_text($image, 18, 126, 425 + $i * 28, $white, $lineText);
foreach (p50_audio_og_fit($b, 20, 2) as $i => $lineText) p50_audio_og_text($image, 376, 425 + $i * 28, $white, $lineText);

p50_audio_og_text($image, 18, 625, 205, $lime, mb_strtoupper(mb_substr($author, 0, 28), 'UTF-8'));
foreach (p50_audio_og_fit('Commente son vote pour '.$selected, 26, 4) as $i => $lineText) {
    p50_audio_og_text($image, 29, 625, 262 + $i * 42, $white, $lineText);
}

imagefilledrectangle($image, 625, 450, 1050, 528, $lime);
p50_audio_og_text($image, 24, 735, 500, $dark, '▶ ÉCOUTER');
p50_audio_og_text($image, 15, 625, 558, $muted, 'pass50.store', false);

header('Content-Type: image/png');
header('Cache-Control: public, max-age=21600, stale-while-revalidate=86400');
header('X-Content-Type-Options: nosniff');
imagepng($image, null, 6);
imagedestroy($image);
