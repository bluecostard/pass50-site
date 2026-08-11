<?php
declare(strict_types=1);

require __DIR__.'/api/fi-public-core.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: public, max-age=300');

$id = trim((string)($_GET['id'] ?? ''));
$state = p50_fi_state();
$profile = p50_fi_profile_by_id($id, $state);
$base = p50_fi_base_url();

if ($profile === null || !p50_fi_indexable($profile)) {
    http_response_code(404);
    $title = 'Profil introuvable — PASS50';
    $description = 'Ce profil influenceur n’est pas disponible sur PASS50.';
    $canonical = $base.'/fi/'.rawurlencode($id !== '' ? $id : 'introuvable');
    ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, follow">
<title><?=p50_fi_h($title)?></title>
<meta name="description" content="<?=p50_fi_h($description)?>">
<link rel="canonical" href="<?=p50_fi_h($base.'/')?>">
<link rel="icon" type="image/svg+xml" href="/icon.svg">
<style>
:root{--bg:#050705;--text:#f6f8f4;--muted:#9da79b;--lime:#b7ff00;--line:#293129}
*{box-sizing:border-box}body{margin:0;min-height:100vh;background:radial-gradient(circle at 70% -10%,rgba(183,255,0,.1),transparent 40%),var(--bg);color:var(--text);font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;display:grid;place-items:center;padding:24px}
.box{max-width:420px;text-align:center}.brand{font-size:28px;font-weight:1000;letter-spacing:-1.6px}.brand span{color:var(--lime)}h1{font-size:28px;letter-spacing:-1px;margin:18px 0 8px}p{color:var(--muted);line-height:1.45}
a.btn{display:inline-block;margin-top:18px;padding:12px 18px;border-radius:14px;background:var(--lime);color:#050705;font-weight:900;text-decoration:none}
</style>
</head>
<body>
<main class="box">
  <div class="brand">PASS<span>50</span></div>
  <h1>Profil introuvable</h1>
  <p><?=p50_fi_h($description)?></p>
  <a class="btn" href="/">Retour au classement</a>
</main>
</body>
</html><?php
    exit;
}

$name = p50_fi_clean((string)($profile['name'] ?? 'Influenceur'), 90);
$handle = p50_fi_clean((string)($profile['handle'] ?? ''), 90);
$category = p50_fi_clean((string)($profile['category'] ?? ''), 80);
$regionRaw = (string)($profile['region'] ?? $profile['zone'] ?? '');
$region = p50_fi_region_label($regionRaw);
$score24 = p50_fi_score($profile, '24H');
$score7 = p50_fi_score($profile, '7J');
$rankInfo = p50_fi_rank_24h($profile, $state);
$rank = $rankInfo['rank'];
$links = p50_fi_official_links($profile);
$platforms = array_values(array_unique(array_filter(array_map(
    static fn($v) => is_string($v) ? trim($v) : '',
    (array)($profile['platforms'] ?? [])
))));
$photo = p50_fi_public_photo($profile);
if ($photo !== '' && str_starts_with($photo, '/')) {
    $photo = $base.$photo;
}
$canonical = p50_fi_canonical((string)$profile['id']);
$spaUrl = $base.'/?profile='.rawurlencode((string)$profile['id']);
$ogImage = $photo !== '' ? $photo : $base.'/assets/pass50-og.png';

$rankBit = $rank !== null ? 'Rang #'.$rank.' (24 h)' : 'Profil PASS50';
$descParts = array_filter([
    $name.' — influenceur'.($category !== '' ? ' · '.$category : ''),
    $region,
    $rankBit,
    $score24 > 0 ? 'Trend score 24 h : '.number_format($score24, 0, ',', ' ') : null,
    'Classement du buzz des influenceurs ivoiriens sur PASS50.',
]);
$description = p50_fi_clean(implode(' · ', $descParts), 220);
$title = $name.' — PASS50';
if ($rank !== null) {
    $title = $name.' · #'.$rank.' — PASS50';
}

$related = [];
foreach (p50_fi_profiles($state) as $candidate) {
    if ((string)($candidate['id'] ?? '') === (string)$profile['id']) {
        continue;
    }
    if (!p50_fi_indexable($candidate)) {
        continue;
    }
    if (array_key_exists('eligible', $candidate) && empty($candidate['eligible'])) {
        continue;
    }
    $related[] = $candidate;
}
usort($related, static fn(array $a, array $b): int => p50_fi_score($b, '24H') <=> p50_fi_score($a, '24H'));
$related = array_slice($related, 0, 8);

$sameAs = array_values(array_map(static fn(array $l): string => $l['url'], $links));
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'ProfilePage',
    'name' => $name.' sur PASS50',
    'url' => $canonical,
    'isPartOf' => [
        '@type' => 'WebSite',
        'name' => 'PASS50',
        'url' => $base.'/',
    ],
    'mainEntity' => [
        '@type' => 'Person',
        'name' => $name,
        'alternateName' => $handle !== '' ? $handle : null,
        'url' => $canonical,
        'image' => $photo !== '' ? $photo : null,
        'description' => $description,
        'sameAs' => $sameAs !== [] ? $sameAs : null,
        'jobTitle' => $category !== '' ? $category : 'Influenceur',
        'homeLocation' => $region !== '' ? ['@type' => 'Place', 'name' => $region] : null,
    ],
];
$jsonLd = array_filter($jsonLd, static fn($v) => $v !== null);
$jsonLd['mainEntity'] = array_filter($jsonLd['mainEntity'], static fn($v) => $v !== null);

$initials = '';
foreach (preg_split('/\s+/u', $name) ?: [] as $part) {
    if ($part === '') {
        continue;
    }
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    if (mb_strlen($initials) >= 2) {
        break;
    }
}
if ($initials === '') {
    $initials = 'FI';
}

?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#050705">
<title><?=p50_fi_h($title)?></title>
<meta name="description" content="<?=p50_fi_h($description)?>">
<meta name="robots" content="index, follow, max-image-preview:large">
<link rel="canonical" href="<?=p50_fi_h($canonical)?>">
<link rel="icon" type="image/svg+xml" href="/icon.svg">
<meta property="og:type" content="profile">
<meta property="og:site_name" content="PASS50">
<meta property="og:locale" content="fr_FR">
<meta property="og:title" content="<?=p50_fi_h($title)?>">
<meta property="og:description" content="<?=p50_fi_h($description)?>">
<meta property="og:url" content="<?=p50_fi_h($canonical)?>">
<meta property="og:image" content="<?=p50_fi_h($ogImage)?>">
<meta property="og:image:alt" content="<?=p50_fi_h($name.' sur PASS50')?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?=p50_fi_h($title)?>">
<meta name="twitter:description" content="<?=p50_fi_h($description)?>">
<meta name="twitter:image" content="<?=p50_fi_h($ogImage)?>">
<script type="application/ld+json"><?=json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)?></script>
<style>
:root{
  --bg:#050705;--panel:#0d110d;--line:#293129;--text:#f6f8f4;--muted:#9da79b;--lime:#b7ff00;
}
*{box-sizing:border-box}
html{background:var(--bg)}
body{
  margin:0;color:var(--text);
  font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
  background:radial-gradient(circle at 78% -8%,rgba(183,255,0,.12),transparent 34%),var(--bg);
  line-height:1.45;
}
a{color:inherit;text-decoration:none}
.wrap{max-width:760px;margin:0 auto;padding:0 20px 48px}
header{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 0;border-bottom:1px solid rgba(183,255,0,.14)}
.brand{font-size:28px;font-weight:1000;letter-spacing:-1.8px;line-height:1}
.brand span{color:var(--lime)}
.nav a{color:#cdd4ca;font-weight:800;font-size:14px}
.nav a:hover{color:var(--lime)}
.hero{margin-top:28px;display:grid;grid-template-columns:140px 1fr;gap:22px;align-items:start}
.avatar{
  width:140px;height:140px;border-radius:22px;overflow:hidden;
  display:grid;place-items:center;font-size:42px;font-weight:1000;
  background:linear-gradient(145deg,#273027,#0c0f0c);border:1px solid var(--line);
}
.avatar img{width:100%;height:100%;object-fit:cover;object-position:center 18%}
.eyebrow{color:var(--lime);font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}
h1{margin:8px 0 4px;font-size:clamp(28px,5vw,44px);letter-spacing:-1.8px;line-height:1.05;font-weight:1000}
.handle{color:var(--muted);font-weight:700}
.meta{margin-top:12px;color:#d7ddd4;font-weight:650}
.stats{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}
.stat{
  min-width:110px;padding:12px 14px;border:1px solid var(--line);border-radius:14px;
  background:linear-gradient(180deg,rgba(18,24,18,.98),rgba(8,11,8,.98));
}
.stat strong{display:block;font-size:22px;font-weight:1000;color:var(--lime)}
.stat span{font-size:11px;color:var(--muted);font-weight:800;letter-spacing:.04em;text-transform:uppercase}
.cta{display:flex;flex-wrap:wrap;gap:10px;margin-top:22px}
.btn{
  display:inline-flex;align-items:center;justify-content:center;
  padding:12px 16px;border-radius:14px;border:1px solid var(--line);
  background:#0a0d0a;font-weight:900;
}
.btn.primary{background:var(--lime);color:#050705;border-color:var(--lime)}
.btn:hover{border-color:var(--lime)}
section{margin-top:34px;padding-top:8px;border-top:1px solid var(--line)}
h2{margin:0 0 12px;font-size:18px;font-weight:1000;letter-spacing:-.4px}
.links,.related{display:grid;gap:8px}
.links a,.related a{
  display:flex;justify-content:space-between;gap:12px;align-items:center;
  padding:12px 14px;border:1px solid var(--line);border-radius:12px;background:rgba(255,255,255,.02);
}
.links a:hover,.related a:hover{border-color:var(--lime)}
.muted{color:var(--muted)}
footer{margin-top:40px;padding-top:18px;border-top:1px solid var(--line);color:var(--muted);font-size:13px}
footer a{color:#d7ddd4;font-weight:700}
footer a:hover{color:var(--lime)}
@media (max-width:640px){
  .hero{grid-template-columns:1fr;justify-items:start}
  .avatar{width:112px;height:112px;font-size:34px}
}
</style>
</head>
<body>
<div class="wrap">
  <header>
    <a class="brand" href="/" aria-label="PASS50 — accueil">PASS<span>50</span></a>
    <nav class="nav"><a href="/">Classement</a></nav>
  </header>

  <main>
    <article class="hero">
      <div class="avatar" aria-hidden="true">
        <?php if ($photo !== ''): ?>
          <img src="<?=p50_fi_h($photo)?>" alt="" width="140" height="140" loading="eager">
        <?php else: ?>
          <span><?=p50_fi_h($initials)?></span>
        <?php endif; ?>
      </div>
      <div>
        <div class="eyebrow">Fiche influenceur</div>
        <h1><?=p50_fi_h($name)?></h1>
        <?php if ($handle !== ''): ?><div class="handle"><?=p50_fi_h($handle)?></div><?php endif; ?>
        <p class="meta">
          <?=p50_fi_h(implode(' · ', array_filter([$category, $region, $rank !== null ? 'Rang #'.$rank.' sur 24 h' : null])))?>
        </p>
        <div class="stats" aria-label="Indicateurs">
          <?php if ($rank !== null): ?>
          <div class="stat"><strong>#<?=(int)$rank?></strong><span>Rang 24 h</span></div>
          <?php endif; ?>
          <div class="stat"><strong><?=p50_fi_h(number_format($score24, 0, ',', ' '))?></strong><span>Score 24 h</span></div>
          <div class="stat"><strong><?=p50_fi_h(number_format($score7, 0, ',', ' '))?></strong><span>Score 7 j</span></div>
        </div>
        <div class="cta">
          <a class="btn primary" href="<?=p50_fi_h($spaUrl)?>">Voir sur PASS50</a>
          <a class="btn" href="/">Tout le classement</a>
        </div>
      </div>
    </article>

    <?php if ($platforms !== [] || $links !== []): ?>
    <section>
      <h2>Présence en ligne</h2>
      <?php if ($platforms !== []): ?>
        <p class="muted">Plateformes : <?=p50_fi_h(implode(', ', $platforms))?></p>
      <?php endif; ?>
      <?php if ($links !== []): ?>
        <div class="links">
          <?php foreach ($links as $link): ?>
            <a href="<?=p50_fi_h($link['url'])?>" rel="noopener noreferrer me" target="_blank">
              <strong><?=p50_fi_h($link['platform'])?></strong>
              <span class="muted">Ouvrir ↗</span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <section>
      <h2>À propos</h2>
      <p>
        <?=p50_fi_h($name)?> est suivi sur PASS50, le classement du buzz des influenceurs ivoiriens
        <?= $region !== '' ? ' ('.p50_fi_h($region).')' : '' ?>.
        Consulte le trend score, le rang et l’actualité pour savoir qui fait le buzz.
      </p>
    </section>

    <?php if ($related !== []): ?>
    <section>
      <h2>Autres influenceurs du moment</h2>
      <div class="related">
        <?php foreach ($related as $other):
            $oid = (string)($other['id'] ?? '');
            $oname = p50_fi_clean((string)($other['name'] ?? 'Influenceur'), 80);
            $orank = p50_fi_rank_24h($other, $state)['rank'];
            ?>
          <a href="/fi/<?=p50_fi_h($oid)?>">
            <strong><?=p50_fi_h($oname)?></strong>
            <span class="muted"><?=$orank !== null ? '#'.(int)$orank : 'Voir la fiche'?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>
  </main>

  <footer>
    <p><a href="/">PASS50</a> · <a href="/pronostics.html">Pronostics</a> · <a href="/informations-legales.html">Informations légales</a></p>
    <p class="muted">Page publique indexable · données issues du classement PASS50</p>
  </footer>
</div>
</body>
</html>
