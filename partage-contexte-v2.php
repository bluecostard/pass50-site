<?php
declare(strict_types=1);

require_once __DIR__.'/api/share-photo-core.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=90');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

function p50_share_v2_h(string $value): string {
    return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}
function p50_share_v2_clean(string $value,int $max=190): string {
    $value=trim(preg_replace('/[\x00-\x1F\x7F]/u',' ',$value)??'');
    return mb_substr($value,0,$max);
}
function p50_share_v2_base(): string {
    $config=p50_share_photo_config();
    $base=rtrim((string)($config['app']['base_url']??''),'/');
    return preg_match('#^https://#i',$base)?$base:'https://www.pass50.store';
}
function p50_share_v2_period(string $period): string {
    return ['2H'=>'2 h','24H'=>'24 h','48H'=>'48 h','7J'=>'7 jours','15J'=>'15 jours'][$period]??$period;
}
function p50_share_v2_region(string $region): string {
    return ['ALL'=>'Côte d’Ivoire + diaspora','CI'=>'Côte d’Ivoire','DIASPORA'=>'Diaspora'][$region]??$region;
}
function p50_share_v2_score(array $profile,string $period): float {
    $scores=is_array($profile['scores']??null)?$profile['scores']:[];
    return max(0,min(100,(float)($scores[$period]??$scores['24H']??0)));
}
function p50_share_v2_ranking(string $period,string $region,int $limit): array {
    $rows=[];
    foreach(p50_share_photo_profiles() as $profile){
        if(($profile['alive']??true)===false||($profile['eligible']??true)===false||($profile['classable']??true)===false)continue;
        $profileRegion=(string)($profile['region']??'ALL');
        if($region!=='ALL'&&$profileRegion!==$region&&$profileRegion!=='BOTH')continue;
        $profile['_share_score']=p50_share_v2_score($profile,$period);
        $rows[]=$profile;
    }
    usort($rows,static function(array $a,array $b): int {
        $score=((float)$b['_share_score'])<=>((float)$a['_share_score']);
        return $score!==0?$score:strnatcasecmp((string)($a['name']??''),(string)($b['name']??''));
    });
    return array_slice($rows,0,max(1,min(50,$limit)));
}
function p50_share_v2_audio(string $token): ?array {
    if(!preg_match('/^[A-Za-z0-9._-]{1,180}$/',$token))return null;
    $pdo=p50_share_photo_pdo();
    if(!$pdo)return null;
    try{
        $stmt=$pdo->prepare("SELECT p.*,u.display_name author_display_name
          FROM p50_duel_audio_posts p
          JOIN users u ON u.id=p.user_id AND u.deleted_at IS NULL
          WHERE p.file_name=? AND p.status='published' AND p.expires_at>UTC_TIMESTAMP()
          LIMIT 1");
        $stmt->execute([$token]);
        $row=$stmt->fetch();
        return is_array($row)?$row:null;
    }catch(Throwable $e){
        error_log('PASS50 partage v2 audio: '.$e->getMessage());
        return null;
    }
}

$type=p50_share_v2_clean((string)($_GET['type']??'ranking-top3'),32);
$allowed=['ranking-top3','ranking-top10','ranking-top50','feed-post','duel-audio'];
if(!in_array($type,$allowed,true))$type='ranking-top3';
$period=p50_share_v2_clean((string)($_GET['period']??'24H'),8);
if(!in_array($period,['2H','24H','48H','7J','15J'],true))$period='24H';
$region=p50_share_v2_clean((string)($_GET['region']??'ALL'),12);
if(!in_array($region,['ALL','CI','DIASPORA'],true))$region='ALL';
$id=p50_share_v2_clean((string)($_GET['id']??''),100);
$postTitle=p50_share_v2_clean((string)($_GET['title']??''),150);
$platform=p50_share_v2_clean((string)($_GET['platform']??''),32);
$audioToken=p50_share_v2_clean((string)($_GET['audio']??''),180);
if($audioToken!==''&&!preg_match('/^[A-Za-z0-9._-]{1,180}$/',$audioToken))$audioToken='';

$base=p50_share_v2_base();
$title='PASS50 — Partage';
$description='Découvrez ce contenu sur PASS50.';
$accent='#b7ff00';
$label='PARTAGE PASS50';
$badge='↗ PASS50';
$cta='Voir sur PASS50';
$destination=$base.'/';
$audioUrl='';

if(str_starts_with($type,'ranking-top')){
    $size=(int)substr($type,strlen('ranking-top'));
    if(!in_array($size,[3,10,50],true))$size=3;
    $ranking=p50_share_v2_ranking($period,$region,$size);
    $leader=$ranking[0]??null;
    $title="Top {$size} PASS50 — ".p50_share_v2_period($period);
    $description='Le classement PASS50 '.p50_share_v2_region($region).' sur '.p50_share_v2_period($period).'.';
    if($leader){
        $description.=' Numéro 1 : '.p50_share_v2_clean((string)($leader['name']??''),80).' ('.round((float)($leader['_share_score']??0)).'/100).';
    }
    $label='CLASSEMENT OFFICIEL';$badge="📊 TOP {$size}";$cta=$size===50?'Voir le classement complet':"Voir le Top {$size}";
    $query=['source'=>'share_ranking','ranking'=>"top{$size}",'period'=>$period,'region'=>$region,'section'=>$size===3?'buzz':'top10'];
    if($size===50)$query['open']='top50';
    $destination=$base.'/?'.http_build_query($query);
}elseif($type==='feed-post'){
    $profile=p50_share_photo_profile_by_id($id);
    $name=p50_share_v2_clean((string)($profile['name']??'Influenceur PASS50'),80);
    if($postTitle==='')$postTitle='Actualité récente';
    $title="{$name} — {$postTitle}";
    $description=($platform!==''?$platform.' · ':'')."Découvrez cette actualité et la position de {$name} dans le classement PASS50.";
    $accent='#1ee5ff';$label='POST DE MON FIL';$badge='📰 ACTUALITÉ';$cta='Voir la fiche et le contenu';
    $destination=$base.'/?'.http_build_query(array_filter(['source'=>'share_feed','profile'=>$id],static fn($value)=>$value!==''));
}elseif($type==='duel-audio'){
    $audio=p50_share_v2_audio($audioToken);
    $author=p50_share_v2_clean((string)($audio['author_display_name']??'Membre PASS50'),60);
    $a=p50_share_v2_clean((string)($audio['candidate_a_name']??'Influenceur A'),70);
    $b=p50_share_v2_clean((string)($audio['candidate_b_name']??'Influenceur B'),70);
    $selectedId=(string)($audio['selected_profile_id']??'');
    $selected=$selectedId!==''&&$selectedId===(string)($audio['candidate_a_id']??'')?$a:$b;
    $title="{$author} commente son vote pour {$selected}";
    $description="Écoutez le commentaire audio public de {$author} pour le duel {$a} VS {$b} sur PASS50.";
    $accent='#a66cff';$label='AUDIO PUBLIC · LES COULÉS';$badge='🎙 AUDIO DU DUEL';$cta='Écouter l’audio et voir le duel';
    $destination=$base.'/?'.http_build_query(array_filter(['source'=>'share_duel_audio','section'=>'coules','audio'=>$audioToken],static fn($value)=>$value!==''));
    if($audio&&$audioToken!=='')$audioUrl=$base.'/uploads/duel-audio/'.rawurlencode($audioToken);
}

$canonicalParams=['type'=>$type,'period'=>$period,'region'=>$region];
foreach(['id'=>$id,'title'=>$postTitle,'platform'=>$platform,'audio'=>$audioToken] as $key=>$value)if($value!=='')$canonicalParams[$key]=$value;
$canonical=$base.'/partage-contexte-v2.php?'.http_build_query($canonicalParams);
$image=$base.'/partage-contexte-image-v2.php?'.http_build_query($canonicalParams);
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="<?=p50_share_v2_h($accent)?>">
<title><?=p50_share_v2_h($title)?></title>
<meta name="description" content="<?=p50_share_v2_h($description)?>">
<link rel="canonical" href="<?=p50_share_v2_h($canonical)?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="PASS50">
<meta property="og:title" content="<?=p50_share_v2_h($title)?>">
<meta property="og:description" content="<?=p50_share_v2_h($description)?>">
<meta property="og:url" content="<?=p50_share_v2_h($canonical)?>">
<meta property="og:image" content="<?=p50_share_v2_h($image)?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<?php if($audioUrl!==''): ?>
<meta property="og:audio" content="<?=p50_share_v2_h($audioUrl)?>">
<meta property="og:audio:type" content="audio/webm">
<?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?=p50_share_v2_h($title)?>">
<meta name="twitter:description" content="<?=p50_share_v2_h($description)?>">
<meta name="twitter:image" content="<?=p50_share_v2_h($image)?>">
<meta http-equiv="refresh" content="1;url=<?=p50_share_v2_h($destination)?>">
<style>
:root{--accent:<?=p50_share_v2_h($accent)?>}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:18px;background:#050705;color:#fff;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.card{position:relative;width:min(500px,100%);min-height:560px;padding:34px;border:1px solid var(--accent);border-radius:28px;overflow:hidden;background:radial-gradient(circle at 95% 0,color-mix(in srgb,var(--accent) 24%,transparent),transparent 38%),linear-gradient(150deg,#151b15,#050705 72%);box-shadow:0 32px 100px rgba(0,0,0,.7);display:flex;flex-direction:column}.card:before{content:"";position:absolute;inset:0 auto 0 0;width:9px;background:var(--accent)}.brand{font-size:32px;font-weight:1000;letter-spacing:-1.7px}.brand span{color:var(--accent)}.kicker{margin-top:52px;color:var(--accent);font-size:12px;font-weight:1000;letter-spacing:1.8px}.pill{align-self:flex-start;margin-top:14px;padding:9px 14px;border:1px solid var(--accent);border-radius:999px;color:var(--accent);font-size:12px;font-weight:1000}.title{margin:38px 0 0;font-size:39px;line-height:1.05;letter-spacing:-1.7px}.desc{margin-top:14px;color:#aeb8aa;font-weight:800;line-height:1.48}.cta{display:block;margin-top:auto;padding:17px;border-radius:16px;background:var(--accent);color:#050705;text-align:center;text-decoration:none;font-weight:1000}.small{margin-top:12px;text-align:center;color:#879184;font-size:12px}
</style>
</head>
<body>
<main class="card"><div class="brand">PASS<span>50</span></div><div class="kicker"><?=p50_share_v2_h($label)?></div><div class="pill"><?=p50_share_v2_h($badge)?></div><h1 class="title"><?=p50_share_v2_h($title)?></h1><p class="desc"><?=p50_share_v2_h($description)?></p><a class="cta" href="<?=p50_share_v2_h($destination)?>"><?=p50_share_v2_h($cta)?></a><div class="small">Redirection vers PASS50…</div></main>
<script>window.setTimeout(function(){location.replace(<?=json_encode($destination,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>)},120);</script>
</body>
</html>
