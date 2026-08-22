<?php
declare(strict_types=1);

require_once __DIR__ . '/weekly-digest-core.php';

function p50_weekly_digest_preview_stats(): array {
    return [
        'weekKey' => p50_weekly_digest_week_key(),
        'window' => p50_weekly_digest_window(),
        'topLive' => ['profileId' => 'census-samuella-kouassi', 'name' => 'Samuella Kouassi', 'viewers' => 12840, 'platform' => 'TikTok'],
        'topRankOne' => ['profileId' => 'census-roseline-layo', 'name' => 'Roseline Layo', 'timesFirst' => 5, 'periodKey' => '24H'],
        'topProno' => ['profileId' => 'census-jordan-evraa', 'name' => 'Jordan Evraa', 'voteCount' => 312, 'uniqueVoters' => 186],
    ];
}

function p50_weekly_digest_load_stats(PDO $pdo, string $week = '', bool $preview = false): array {
    if ($preview) {
        return p50_weekly_digest_preview_stats();
    }
    $stats = p50_weekly_digest_compute_stats($pdo);
    if ($week !== '' && $week !== (string)$stats['weekKey']) {
        p50_weekly_digest_ensure_schema($pdo);
        $stmt = $pdo->prepare('SELECT stats_json FROM p50_weekly_digest_runs WHERE week_key=? LIMIT 1');
        $stmt->execute([$week]);
        $raw = $stmt->fetchColumn();
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $stats = $decoded;
        }
    }
    return $stats;
}

/** @return array{weekKey:string,weekLabel:string,sections:list<array{num:string,title:string,name:string,detail:string}>} */
function p50_weekly_digest_view_model(array $stats): array {
    $live = is_array($stats['topLive'] ?? null) ? $stats['topLive'] : null;
    $rank = is_array($stats['topRankOne'] ?? null) ? $stats['topRankOne'] : null;
    $prono = is_array($stats['topProno'] ?? null) ? $stats['topProno'] : null;

    $liveName = trim((string)($live['name'] ?? ''));
    $liveDetail = 'Données insuffisantes cette semaine';
    if ($liveName !== '') {
        $platform = trim((string)($live['platform'] ?? ''));
        $liveDetail = p50_weekly_digest_format_viewers((int)($live['viewers'] ?? 0)) . ' auditeurs';
        if ($platform !== '') {
            $liveDetail .= ' · ' . $platform;
        }
    }

    $rankName = trim((string)($rank['name'] ?? ''));
    $rankDetail = 'Données insuffisantes cette semaine';
    if ($rankName !== '') {
        $times = (int)($rank['timesFirst'] ?? 0);
        $period = trim((string)($rank['periodKey'] ?? '24H'));
        $rankDetail = $times . ' fois en tête' . ($period !== '' ? ' (' . $period . ')' : '');
    }

    $pronoName = trim((string)($prono['name'] ?? ''));
    $pronoDetail = 'Données insuffisantes cette semaine';
    if ($pronoName !== '') {
        $votes = (int)($prono['voteCount'] ?? 0);
        $voters = (int)($prono['uniqueVoters'] ?? 0);
        $pronoDetail = $votes . ' pronostic' . ($votes > 1 ? 's' : '') . ' · ' . $voters . ' votant' . ($voters > 1 ? 's' : '');
    }

    return [
        'weekKey' => (string)($stats['weekKey'] ?? ''),
        'weekLabel' => (string)($stats['window']['label'] ?? ''),
        'sections' => [
            ['num' => '1', 'title' => 'Live le plus suivi', 'name' => $liveName !== '' ? $liveName : '—', 'detail' => $liveDetail],
            ['num' => '2', 'title' => 'N°1 du classement le plus souvent', 'name' => $rankName !== '' ? $rankName : '—', 'detail' => $rankDetail],
            ['num' => '3', 'title' => 'Influenceur le plus pronostiqué', 'name' => $pronoName !== '' ? $pronoName : '—', 'detail' => $pronoDetail],
        ],
    ];
}

function p50_weekly_digest_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function p50_weekly_digest_render_html(array $view, bool $embed = false): string {
    $weekLabel = p50_weekly_digest_h($view['weekLabel']);
    $pdfUrl = p50_weekly_digest_h(p50_weekly_digest_pdf_url((string)$view['weekKey']));
    $sectionsHtml = '';
    foreach ($view['sections'] as $section) {
        $sectionsHtml .= '<section class="bilan-stat">'
            . '<div class="bilan-stat-num">' . p50_weekly_digest_h((string)$section['num']) . '</div>'
            . '<div class="bilan-stat-body">'
            . '<h2>' . p50_weekly_digest_h((string)$section['title']) . '</h2>'
            . '<p class="bilan-name">' . p50_weekly_digest_h((string)$section['name']) . '</p>'
            . '<p class="bilan-detail">' . p50_weekly_digest_h((string)$section['detail']) . '</p>'
            . '</div></section>';
    }
    $toolbar = $embed ? '' : '<div class="bilan-toolbar no-print">'
        . '<a class="bilan-btn primary" href="' . $pdfUrl . '">Télécharger le PDF</a>'
        . '<button class="bilan-btn" type="button" onclick="window.print()">Imprimer</button>'
        . '</div>';

    return '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Bilan de la semaine PASS50</title>'
        . '<style>'
        . ':root{--accent:#0e7c7c;--paper:#eef1ec;--ink:#0b0f0b;--muted:#5c665c;--lime:#b7ff00}'
        . '*{box-sizing:border-box}body{margin:0;background:#e8ebe4;color:var(--ink);font-family:Inter,system-ui,sans-serif}'
        . '.bilan-toolbar{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;padding:16px}'
        . '.bilan-btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 18px;border-radius:12px;border:1px solid #cfd6cb;background:#fff;color:var(--ink);font-weight:900;text-decoration:none;cursor:pointer}'
        . '.bilan-btn.primary{background:var(--lime);border-color:var(--lime)}'
        . '.bilan-page{width:210mm;min-height:297mm;margin:0 auto;padding:16mm;background:var(--paper);border:1px solid #d5dbd2;box-shadow:0 18px 60px rgba(0,0,0,.12);display:flex;flex-direction:column}'
        . '.bilan-brand{display:flex;align-items:center;gap:10px;font-size:24px;font-weight:1000;letter-spacing:-.8px}'
        . '.bilan-brand:before{content:"";width:14px;height:14px;background:var(--lime);flex:0 0 auto}'
        . '.bilan-kicker{margin-top:22px;color:var(--accent);font-size:12px;font-weight:900;letter-spacing:.12em;text-transform:uppercase}'
        . '.bilan-title{margin:10px 0 0;font-size:34px;line-height:1.05;letter-spacing:-1px}'
        . '.bilan-sub{margin-top:8px;color:var(--muted);font-weight:700}'
        . '.bilan-grid{margin-top:26px;display:grid;gap:16px;flex:1}'
        . '.bilan-stat{display:grid;grid-template-columns:42px 1fr;gap:14px;padding:16px;border:1px solid #d5dbd2;border-radius:14px;background:#fff}'
        . '.bilan-stat-num{width:42px;height:42px;border-radius:10px;background:var(--accent);color:#fff;display:grid;place-items:center;font-weight:1000;font-size:18px}'
        . '.bilan-stat h2{margin:0;font-size:13px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}'
        . '.bilan-name{margin:8px 0 0;font-size:28px;line-height:1.1;font-weight:1000;letter-spacing:-.6px}'
        . '.bilan-detail{margin:6px 0 0;color:var(--muted);font-size:16px;font-weight:700}'
        . '.bilan-foot{margin-top:auto;padding-top:18px;border-top:1px solid #d5dbd2;color:var(--muted);font-size:12px;font-weight:700;display:flex;justify-content:space-between;gap:12px}'
        . '@media print{body{background:#fff}.no-print{display:none!important}.bilan-page{width:auto;min-height:auto;margin:0;border:0;box-shadow:none;padding:14mm}}'
        . '@page{size:A4 portrait;margin:0}'
        . '</style></head><body>'
        . $toolbar
        . '<main class="bilan-page" id="bilanPage">'
        . '<div class="bilan-brand">PASS50</div>'
        . '<div class="bilan-kicker">Bilan du vendredi soir</div>'
        . '<h1 class="bilan-title">Bilan de la semaine</h1>'
        . '<p class="bilan-sub">Semaine ' . $weekLabel . '</p>'
        . '<div class="bilan-grid">' . $sectionsHtml . '</div>'
        . '<footer class="bilan-foot"><span>pass50.store</span><span>3 indicateurs · 1 page</span></footer>'
        . '</main></body></html>';
}

function p50_pdf_latin(string $text): string {
    $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);
    return is_string($converted) ? $converted : $text;
}

function p50_pdf_escape(string $text): string {
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], p50_pdf_latin($text));
}

function p50_weekly_digest_pdf_bytes(array $view): string {
    $lines = [
        ['size' => 22, 'text' => 'PASS50'],
        ['size' => 11, 'text' => 'Bilan du vendredi soir'],
        ['size' => 16, 'text' => 'Semaine ' . (string)$view['weekLabel']],
        ['size' => 10, 'text' => ''],
    ];
    foreach ($view['sections'] as $section) {
        $lines[] = ['size' => 10, 'text' => ''];
        $lines[] = ['size' => 11, 'text' => (string)$section['num'] . '. ' . (string)$section['title']];
        $lines[] = ['size' => 18, 'text' => (string)$section['name']];
        $lines[] = ['size' => 12, 'text' => (string)$section['detail']];
    }
    $lines[] = ['size' => 10, 'text' => ''];
    $lines[] = ['size' => 10, 'text' => 'pass50.store'];

    $y = 800.0;
    $stream = "BT\n";
    foreach ($lines as $line) {
        $size = (float)$line['size'];
        $text = p50_pdf_escape((string)$line['text']);
        if ($text === '') {
            $y -= 10;
            continue;
        }
        $stream .= sprintf("/F1 %.1F Tf\n1 0 0 1 72 %.1F Tm\n(%s) Tj\n", $size, $y, $text);
        $y -= $size + ($size >= 16 ? 14 : 10);
    }
    $stream .= "ET";

    $objects = [];
    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
    $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>';
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objects[] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xrefPos = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xrefPos . "\n%%EOF";
    return $pdf;
}
