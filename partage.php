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
    'site'=>['color'=>'#0e7c7b','label'=>'Le buzz','title'=>'Qui fait le buzz ?','description'=>'Classement des influenceurs ivoiriens.','cta'=>'Découvrir'],
    'profile'=>['color'=>'#3d5a1f','label'=>'Fiche','title'=>$name,'description'=>trim($handle.' · Fiche influenceur'),'cta'=>'Voir la fiche'],
    'live'=>['color'=>'#b42318','label'=>'En direct','title'=>$name.' est en direct','description'=>'Regarde ce LIVE'.($platform!==''?' sur '.$platform:'').'.','cta'=>'Regarder'],
    'coules'=>['color'=>'#b45309','label'=>'Les Coulés','title'=>'Mon vote','description'=>$name!=='PASS50'?'Mon choix : '.$name.'. À toi de voter.':'Découvre le duel et vote.','cta'=>'Voir le duel'],
    'coules-audio'=>['color'=>'#1d4e89','label'=>'Les Coulés','title'=>'Vote commenté','description'=>'Découvre mon choix et mon commentaire audio.','cta'=>'Écouter'],
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
if(($type==='coules'||$type==='coules-audio')&&$id!==''){
    $canonical=$base.'/c/'.rawurlencode($id).($type==='coules-audio'?'/audio':'');
}else{
    $canonical=$base.'/partage.php?'.http_build_query(array_filter(['type'=>$type,'id'=>$id,'choice'=>$choice,'platform'=>$platform],static fn($v)=>$v!==''));
}
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
:root{--accent:<?=p50_share_h($color)?>}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:18px;background:#e8ebe4;color:#0b0f0b;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.card{position:relative;width:min(440px,100%);min-height:520px;padding:28px;border:1px solid #d5dbd2;border-radius:18px;overflow:hidden;background:#eef1ec;border-top:8px solid var(--accent);box-shadow:0 24px 80px rgba(0,0,0,.22);display:flex;flex-direction:column}.brand{display:flex;align-items:center;gap:10px;font-size:22px;font-weight:1000;letter-spacing:-.8px}.brand:before{content:"";width:14px;height:14px;background:#b7ff00;flex:0 0 auto}.kicker{margin-top:28px;color:var(--accent);font-size:12px;font-weight:900;letter-spacing:.5px}.title{margin:18px 0 0;font-size:34px;line-height:1.05;letter-spacing:-1.2px}.desc{margin-top:12px;color:#5c665c;font-weight:600;line-height:1.45}.cta{display:block;margin-top:auto;padding:15px;border-radius:10px;background:#b7ff00;color:#0b0f0b;text-align:center;text-decoration:none;font-weight:1000}.small{margin-top:12px;text-align:center;color:#5c665c;font-size:12px}
</style>
</head>
<body>
<main class="card">
<div class="brand">PASS50</div>
<div class="kicker"><?=p50_share_h($label)?></div>
<h1 class="title"><?=p50_share_h($title)?></h1>
<p class="desc"><?=p50_share_h($description)?></p>
<a class="cta" href="<?=p50_share_h($destination)?>"><?=p50_share_h($cta)?></a>
<div class="small">Redirection vers PASS50…</div>
</main>
<script>window.setTimeout(function(){location.replace(<?=json_encode($destination,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>)},120);</script>
</body>
</html>
