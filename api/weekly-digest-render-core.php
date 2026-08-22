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
            ['num' => '1', 'title' => 'Live le plus suivi', 'name' => $liveName !== '' ? $liveName : '—', 'detail' => $liveDetail, 'profileId' => trim((string)($live['profileId'] ?? ''))],
            ['num' => '2', 'title' => 'N°1 du classement le plus souvent', 'name' => $rankName !== '' ? $rankName : '—', 'detail' => $rankDetail, 'profileId' => trim((string)($rank['profileId'] ?? ''))],
            ['num' => '3', 'title' => 'Influenceur le plus pronostiqué', 'name' => $pronoName !== '' ? $pronoName : '—', 'detail' => $pronoDetail, 'profileId' => trim((string)($prono['profileId'] ?? ''))],
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

function p50_pdf_rgb_fill(int $r, int $g, int $b): string {
    return sprintf("%.3F %.3F %.3F rg\n", $r / 255, $g / 255, $b / 255);
}

function p50_pdf_rgb_stroke(int $r, int $g, int $b): string {
    return sprintf("%.3F %.3F %.3F RG\n", $r / 255, $g / 255, $b / 255);
}

function p50_pdf_fill_rect(float $x, float $y, float $w, float $h, int $r, int $g, int $b): string {
    return p50_pdf_rgb_fill($r, $g, $b) . sprintf("%.1F %.1F %.1F %.1F re f\n", $x, $y, $w, $h);
}

function p50_pdf_stroke_rect(float $x, float $y, float $w, float $h, int $r, int $g, int $b, float $width = 1.0): string {
    return p50_pdf_rgb_stroke($r, $g, $b) . sprintf("%.2F w\n", $width) . sprintf("%.1F %.1F %.1F %.1F re S\n", $x, $y, $w, $h);
}

function p50_pdf_text(float $x, float $y, float $size, string $text, int $r = 11, int $g = 15, int $b = 11): string {
    if ($text === '') {
        return '';
    }
    return p50_pdf_rgb_fill($r, $g, $b)
        . sprintf("BT /F1 %.1F Tf 1 0 0 1 %.1F %.1F Tm (%s) Tj ET\n", $size, $x, $y, p50_pdf_escape($text));
}

function p50_weekly_digest_pdf_bytes(array $view): string {
    $pageW = 595.0;
    $pageH = 842.0;
    $margin = 45.0;
    $contentW = $pageW - (2 * $margin);
    $stream = '';

    // Fond page A4 (papier clair).
    $stream .= p50_pdf_fill_rect(0, 0, $pageW, $pageH, 238, 241, 236);

    $top = static function (float $fromTop) use ($pageH): float {
        return $pageH - $fromTop;
    };

    // En-tête.
    $stream .= p50_pdf_fill_rect($margin, $top(58), 10, 10, 183, 255, 0);
    $stream .= p50_pdf_text($margin + 16, $top(52), 22, 'PASS50');
    $stream .= p50_pdf_text($margin, $top(82), 10, 'BILAN DU VENDREDI SOIR', 14, 124, 124);
    $stream .= p50_pdf_text($margin, $top(108), 24, 'Bilan de la semaine');
    $stream .= p50_pdf_text($margin, $top(132), 12, 'Semaine ' . (string)$view['weekLabel'], 92, 102, 92);

    $cardTop = 158.0;
    $cardH = 148.0;
    $cardGap = 14.0;
    foreach ($view['sections'] as $section) {
        $cardBottom = $pageH - $cardTop - $cardH;
        $stream .= p50_pdf_fill_rect($margin, $cardBottom, $contentW, $cardH, 255, 255, 255);
        $stream .= p50_pdf_stroke_rect($margin, $cardBottom, $contentW, $cardH, 213, 219, 210, 0.8);

        $badgeX = $margin + 12;
        $badgeY = $cardBottom + $cardH - 43;
        $stream .= p50_pdf_fill_rect($badgeX, $badgeY, 31, 31, 14, 124, 124);
        $stream .= p50_pdf_text($badgeX + 11, $badgeY + 10, 16, (string)$section['num'], 255, 255, 255);

        $textX = $margin + 56;
        $stream .= p50_pdf_text($textX, $cardBottom + $cardH - 28, 9, (string)$section['title'], 92, 102, 92);
        $stream .= p50_pdf_text($textX, $cardBottom + $cardH - 54, 20, (string)$section['name']);
        $stream .= p50_pdf_text($textX, $cardBottom + $cardH - 74, 11, (string)$section['detail'], 92, 102, 92);

        $cardTop += $cardH + $cardGap;
    }

    $stream .= p50_pdf_stroke_rect($margin, 52, $contentW, 0.5, 213, 219, 210, 0.8);
    $stream .= p50_pdf_text($margin, 36, 10, 'pass50.store', 92, 102, 92);
    $stream .= p50_pdf_text($pageW - $margin - 108, 36, 10, '3 indicateurs · 1 page', 92, 102, 92);

    $objects = [];
    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
    $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>';
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
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
