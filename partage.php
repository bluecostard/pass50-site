<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=120');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

function p50_share_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function p50_share_clean(string $value, int $max=190): string {
    $value=trim(preg_replace('/[\x00-\x1F\x7F]/u',' ', $value) ?? '');
    return mb_substr($value,0,$max);
}
function p50_share_state(): array {
    $configFile=__DIR__.'/api/config.php';
    if(!is_file($configFile))return [];
    try{
        $config=require $configFile;
        $d=$config['db']??[];
        $dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string)($d['host']??'localhost'),(int)($d['port']??3306),
            (string)($d['name']??''),(string)($d['charset']??'utf8mb4'));
        $pdo=new PDO($dsn,(string)($d['user']??''),(string)($d['password']??''),[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
        $pdo->exec("SET SESSION time_zone = '+00:00'");
        $raw=$pdo->query("SELECT data FROM app_state WHERE id='public' LIMIT 1")->fetchColumn();
        $state=is_string($raw)?json_decode($raw,true):[];
        return is_array($state)?$state:[];
    }catch(Throwable $e){
        error_log('PASS50 partage: '.$e->getMessage());
        return [];
    }
}
function p50_share_profile(array $state,string $id): ?array {
    foreach((array)($state['profiles']??[]) as $profile){
        if(is_array($profile)&&(string)($profile['id']??'')===$id)return $profile;
    }
    return null;
}
function p50_share_base(): string {
    $configFile=__DIR__.'/api/config.php';
    if(is_file($configFile)){
        try{
            $config=require $configFile;
            $base=rtrim((string)($config['app']['base_url']??''),'/');
            if(preg_match('#^https://#i',$base))return $base;
        }catch(Throwable){}
    }
    return 'https://www.pass50.store';
}

$type=p50_share_clean((string)($_GET['type']??'site'),30);
$allowed=['site','profile','live','coules','coules-audio'];
if(!in_array($type,$allowed,true))$type='site';
$id=p50_share_clean((string)($_GET['id']??''),100);
$choice=p50_share_clean((string)($_GET['choice']??$id),100);
$platform=p50_share_clean((string)($_GET['platform']??''),32);
$state=$type==='site'?[]:p50_share_state();
$profile=$id!==''?p50_share_profile($state,$id):null;
$name=p50_share_clean((string)($profile['name']??'PASS50'),90);
$handle=p50_share_clean((string)($profile['handle']??''),90);
$base=p50_share_base();

$themes=[
    'site'=>['color'=>'#1ee5ff','label'=>'LE SITE','title'=>'PASS50 — Qui fait le buzz maintenant ?','description'=>'Découvre le classement du buzz et des influenceurs ivoiriens.','cta'=>'Découvrir PASS50'],
    'profile'=>['color'=>'#b7ff00','label'=>'FICHE INFLUENCEUR','title'=>$name.' — Fiche influenceur PASS50','description'=>trim($handle.' · Découvre sa fiche officielle, ses réseaux et son actualité.'),'cta'=>'Voir la fiche'],
    'live'=>['color'=>'#ff4b4b','label'=>'EN DIRECT','title'=>$name.' est en direct — PASS50','description'=>'Regarde ce LIVE'.($platform!==''?' sur '.$platform:'').' depuis PASS50.','cta'=>'Regarder maintenant'],
    'coules'=>['color'=>'#ff9d1d','label'=>'LES COULÉS','title'=>'Les Coulés — Mon vote PASS50','description'=>$name!=='PASS50'?'Mon choix : '.$name.'. À toi de voter.':'Découvre le duel et vote sur PASS50.','cta'=>'Voir le duel'],
    'coules-audio'=>['color'=>'#a66cff','label'=>'LES COULÉS + AUDIO','title'=>'Les Coulés — Mon vote commenté sur PASS50','description'=>'Découvre mon choix et mon commentaire audio.','cta'=>'Écouter et voir'],
];
$theme=$themes[$type];

$query=['source'=>'share_'.$type];
if($type==='profile'){
    $query['profile']=$id;
}elseif($type==='live'){
    $query['live']=$id;
    if($platform!=='')$query['platform']=$platform;
}elseif($type==='coules'||$type==='coules-audio'){
    $query['section']='coules';
    if($choice!=='')$query['choice']=$choice;
}
$destination=$base.'/'.($query?'?'.http_build_query($query):'');
$canonical=$base.'/partage.php?'.http_build_query(array_filter(['type'=>$type,'id'=>$id,'choice'=>$choice,'platform'=>$platform],static fn($v)=>$v!==''));
$image=$base.'/partage-image.php?'.http_build_query(['type'=>$type,'label'=>$name,'platform'=>$platform]);
$title=(string)$theme['title'];
$description=(string)$theme['description'];
$color=(string)$theme['color'];
$label=(string)$theme['label'];
$cta=(string)$theme['cta'];
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="<?=p50_share_h($color)?>">
<title><?=p50_share_h($title)?></title>
<meta name="description" content="<?=p50_share_h($description)?>">
<link rel="canonical" href="<?=p50_share_h($canonical)?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="PASS50">
<meta property="og:title" content="<?=p50_share_h($title)?>">
<meta property="og:description" content="<?=p50_share_h($description)?>">
<meta property="og:url" content="<?=p50_share_h($canonical)?>">
<meta property="og:image" content="<?=p50_share_h($image)?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="<?=p50_share_h($label.' · '.$name)?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?=p50_share_h($title)?>">
<meta name="twitter:description" content="<?=p50_share_h($description)?>">
<meta name="twitter:image" content="<?=p50_share_h($image)?>">
<meta http-equiv="refresh" content="1;url=<?=p50_share_h($destination)?>">
<style>
:root{--accent:<?=p50_share_h($color)?>}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:18px;background:#050705;color:#fff;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.card{position:relative;width:min(480px,100%);min-height:560px;padding:34px;border:1px solid var(--accent);border-radius:28px;overflow:hidden;background:radial-gradient(circle at 95% 0,color-mix(in srgb,var(--accent) 24%,transparent),transparent 38%),linear-gradient(150deg,#151b15,#050705 72%);box-shadow:0 32px 100px rgba(0,0,0,.7);display:flex;flex-direction:column}.card:before{content:"";position:absolute;inset:0 auto 0 0;width:9px;background:var(--accent)}.brand{font-size:32px;font-weight:1000;letter-spacing:-1.7px}.brand span{color:var(--accent)}.kicker{margin-top:56px;color:var(--accent);font-size:12px;font-weight:1000;letter-spacing:1.8px}.pill{align-self:flex-start;margin-top:14px;padding:9px 14px;border:1px solid var(--accent);border-radius:999px;color:var(--accent);font-size:12px;font-weight:1000}.title{margin:42px 0 0;font-size:40px;line-height:1.02;letter-spacing:-1.8px}.desc{margin-top:14px;color:#aeb8aa;font-weight:800;line-height:1.45}.cta{display:block;margin-top:auto;padding:17px;border-radius:16px;background:var(--accent);color:#050705;text-align:center;text-decoration:none;font-weight:1000}.small{margin-top:12px;text-align:center;color:#879184;font-size:12px}
</style>
</head>
<body>
<main class="card">
<div class="brand">PASS<span>50</span></div>
<div class="kicker"><?=p50_share_h($label)?></div>
<div class="pill"><?=p50_share_h($type==='live'?'● EN DIRECT':($type==='profile'?'★ FICHE OFFICIELLE':($type==='site'?'↗ PASS50':($type==='coules-audio'?'🎙 VOTE COMMENTÉ':'⚔ MON VOTE'))))?></div>
<h1 class="title"><?=p50_share_h($title)?></h1>
<p class="desc"><?=p50_share_h($description)?></p>
<a class="cta" href="<?=p50_share_h($destination)?>"><?=p50_share_h($cta)?></a>
<div class="small">Redirection vers PASS50…</div>
</main>
<script>window.setTimeout(function(){location.replace(<?=json_encode($destination,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>)},120);</script>
</body>
</html>
