<?php
declare(strict_types=1);

require dirname(__DIR__) . '/api/weekly-digest-render-core.php';

function must(bool $value, string $message): void {
    if (!$value) {
        throw new RuntimeException($message);
    }
}

$view = p50_weekly_digest_view_model(p50_weekly_digest_preview_stats());
must(count($view['sections']) === 3, 'Le bilan doit contenir 3 sections.');
$html = p50_weekly_digest_render_html($view, true);
must(str_contains($html, 'Live le plus suivi'), 'HTML : live manquant.');
must(str_contains($html, 'N°1 du classement le plus souvent'), 'HTML : classement manquant.');
must(str_contains($html, 'Influenceur le plus pronostiqué'), 'HTML : pronos manquant.');
must(str_contains($html, 'bilan-page'), 'HTML : une seule page A4 attendue.');

$pdf = p50_weekly_digest_pdf_bytes($view);
must(str_starts_with($pdf, '%PDF'), 'Le PDF doit commencer par %PDF.');
must(strlen($pdf) > 400, 'Le PDF semble trop court.');
must(str_contains($pdf, 'Live le plus suivi'), 'PDF : live manquant.');
must(str_contains($pdf, 'Samuella Kouassi'), 'PDF : nom live manquant.');
must(str_contains($pdf, 'Roseline Layo'), 'PDF : nom classement manquant.');
must(str_contains($pdf, 'Jordan Evraa'), 'PDF : nom prono manquant.');

echo json_encode(['ok' => true, 'pdfBytes' => strlen($pdf), 'sections' => 3], JSON_UNESCAPED_SLASHES) . PHP_EOL;
