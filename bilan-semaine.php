<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=120');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

function p50_bilan_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$week = trim((string)($_GET['week'] ?? ''));
$preview = isset($_GET['preview']) && in_array(strtolower((string)$_GET['preview']), ['1', 'true', 'yes'], true);
$title = 'Bilan de la semaine PASS50';
$description = 'Live le plus suivi, N°1 du classement et influenceur le plus pronostiqué — carte téléchargeable.';
$canonical = 'https://pass50.store/bilan-semaine.php' . ($week !== '' ? '?week=' . rawurlencode($week) : '');
if ($preview) {
    $canonical .= (str_contains($canonical, '?') ? '&' : '?') . 'preview=1';
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title><?= p50_bilan_h($title) ?></title>
  <meta name="description" content="<?= p50_bilan_h($description) ?>">
  <link rel="canonical" href="<?= p50_bilan_h($canonical) ?>">
  <meta property="og:type" content="website">
  <meta property="og:title" content="<?= p50_bilan_h($title) ?>">
  <meta property="og:description" content="<?= p50_bilan_h($description) ?>">
  <meta property="og:url" content="<?= p50_bilan_h($canonical) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="theme-color" content="#0a0f0b">
  <style>
    :root{--lime:#b7ff00;--ink:#0b0f0b;--muted:#8a968a}
    *{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:system-ui,sans-serif;background:radial-gradient(circle at top,#172019,#0a0f0b 58%);color:#eef1ec}
    .wrap{max-width:920px;margin:0 auto;padding:20px 16px 40px}
    .hero{text-align:center;margin-bottom:18px}
    .hero .eyebrow{color:var(--lime);font-size:12px;font-weight:900;letter-spacing:.14em}
    h1{margin:8px 0 6px;font-size:clamp(28px,6vw,42px);letter-spacing:-.04em}
    .muted{color:var(--muted)}
    .card-shell{border:1px solid rgba(183,255,0,.35);border-radius:22px;padding:10px;background:rgba(8,11,8,.9);box-shadow:0 24px 80px rgba(0,0,0,.35)}
  </style>
</head>
<body>
  <div class="wrap">
    <header class="hero">
      <div class="eyebrow">BILAN DU VENDREDI SOIR</div>
      <h1><?= p50_bilan_h($title) ?></h1>
      <p class="muted">Carte cliquable · téléchargeable en PNG ou PDF · partage externe</p>
    </header>
    <div class="card-shell" id="bilanCardHost"></div>
    <p class="muted" style="text-align:center;margin-top:16px;font-size:13px"><a href="https://pass50.store/" style="color:var(--lime)">pass50.store</a></p>
  </div>
  <script src="./weekly-digest-share-v1.js?v=1.0"></script>
  <script>
  (async function(){
    const params=new URLSearchParams(location.search);
    const week=params.get('week')||'';
    const preview=params.get('preview')==='1';
    let stats=null;
    try{
      const q=new URLSearchParams();
      if(week)q.set('week',week);
      if(preview)q.set('preview','1');
      const r=await fetch('./api/weekly-digest-card.php?'+q.toString(),{cache:'no-store'});
      const data=await r.json();
      if(data?.ok)stats=data.stats;
    }catch{}
    if(!stats&&window.PASS50_WEEKLY_DIGEST_DEMO)stats=window.PASS50_WEEKLY_DIGEST_DEMO();
    if(typeof openWeeklyDigestCard==='function')openWeeklyDigestCard(stats);
    document.getElementById('weeklyDigestModal')?.classList.add('show');
    const modal=document.getElementById('weeklyDigestModal');
    if(modal){
      modal.style.background='transparent';
      modal.style.backdropFilter='none';
      modal.style.position='static';
      modal.style.display='block';
      modal.style.padding='0';
      const box=modal.querySelector('.wd-box');
      if(box){box.maxHeight='none';box.border='0';box.background='transparent';box.padding='0';}
      const close=modal.querySelector('[data-close="weeklyDigestModal"]');
      if(close)close.style.display='none';
    }
  })();
  </script>
</body>
</html>
