<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=90');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

function p50_context_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function p50_context_clean(string $value, int $max=190): string {
    $value=trim(preg_replace('/[\x00-\x1F\x7F]/u',' ', $value) ?? '');
    return mb_substr($value,0,$max);
}

function p50_context_base(): string {
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

function p50_context_pdo(): ?PDO {
    $configFile=__DIR__.'/api/config.php';
    if(!is_file($configFile))return null;
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
        return $pdo;
    }catch(Throwable $e){
        error_log('PASS50 partage contexte DB: '.$e->getMessage());
        return null;
    }
}

function p50_context_state(?PDO $pdo): array {
    if(!$pdo)return [];
    try{
        $raw=$pdo->query("SELECT data FROM app_state WHERE id='public' LIMIT 1")->fetchColumn();
        $state=is_string($raw)?json_decode($raw,true):[];
        return is_array($state)?$state:[];
    }catch(Throwable $e){
        error_log('PASS50 partage contexte state: '.$e->getMessage());
        return [];
    }
}

function p50_context_profile(array $state,string $id): ?array {
    foreach((array)($state['profiles']??[]) as $profile){
        if(is_array($profile)&&(string)($profile['id']??'')===$id)return $profile;
    }
    return null;
}

function p50_context_period_label(string $period): string {
    return ['2H'=>'2 h','24H'=>'24 h','48H'=>'48 h','7J'=>'7 jours','15J'=>'15 jours'][$period]??$period;
}

function p50_context_region_label(string $region): string {
    return ['ALL'=>'Côte d’Ivoire + diaspora','CI'=>'Côte d’Ivoire','DIASPORA'=>'Diaspora'][$region]??$region;
}

function p50_context_score(array $profile,string $period): float {
    $scores=is_array($profile['scores']??null)?$profile['scores']:[];
    $value=(float)($scores[$period]??$scores['24H']??0);
    return max(0,min(100,$value));
}

function p50_context_ranking(array $state,string $period,string $region,int $limit): array {
    $profiles=[];
    foreach((array)($state['profiles']??[]) as $profile){
        if(!is_array($profile))continue;
        if(($profile['alive']??true)===false)continue;
        if(($profile['eligible']??true)===false)continue;
        if(($profile['classable']??true)===false)continue;
        $profileRegion=(string)($profile['region']??'ALL');
        if($region!=='ALL'&&$profileRegion!==$region&&$profileRegion!=='BOTH')continue;
        $profile['_context_score']=p50_context_score($profile,$period);
        $profiles[]=$profile;
    }
    usort($profiles,static function(array $a,array $b): int {
        $score=((float)$b['_context_score'])<=>((float)$a['_context_score']);
        return $score!==0?$score:strnatcasecmp((string)($a['name']??''),(string)($b['name']??''));
    });
    return array_slice($profiles,0,max(1,min(50,$limit)));
}

function p50_context_audio(?PDO $pdo,string $fileName): ?array {
    if(!$pdo||!preg_match('/^[A-Za-z0-9._-]{1,180}$/',$fileName))return null;
    try{
        $stmt=$pdo->prepare("SELECT p.*,u.display_name author_display_name
          FROM p50_duel_audio_posts p
          JOIN users u ON u.id=p.user_id AND u.deleted_at IS NULL
          WHERE p.file_name=? AND p.status='published' AND p.expires_at>UTC_TIMESTAMP()
          LIMIT 1");
        $stmt->execute([$fileName]);
        $row=$stmt->fetch();
        return is_array($row)?$row:null;
    }catch(Throwable $e){
        error_log('PASS50 partage contexte audio: '.$e->getMessage());
        return null;
    }
}

$type=p50_context_clean((string)($_GET['type']??'ranking-top3'),32);
$allowed=['ranking-top3','ranking-top10','ranking-top50','feed-post','duel-audio'];
if(!in_array($type,$allowed,true))$type='ranking-top3';
$period=p50_context_clean((string)($_GET['period']??'24H'),8);
if(!in_array($period,['2H','24H','48H','7J','15J'],true))$period='24H';
$region=p50_context_clean((string)($_GET['region']??'ALL'),12);
if(!in_array($region,['ALL','CI','DIASPORA'],true))$region='ALL';
$id=p50_context_clean((string)($_GET['id']??''),100);
$postTitle=p50_context_clean((string)($_GET['title']??''),150);
$platform=p50_context_clean((string)($_GET['platform']??''),32);
$audioToken=p50_context_clean((string)($_GET['audio']??''),180);
if($audioToken!==''&&!preg_match('/^[A-Za-z0-9._-]{1,180}$/',$audioToken))$audioToken='';

$base=p50_context_base();
$pdo=p50_context_pdo();
$state=p50_context_state($pdo);
$title='PASS50 — Partage';
$description='Découvrez ce contenu sur PASS50.';
$accent='#b7ff00';
$kicker='PARTAGE PASS50';
$badge='↗ PASS50';
$cta='Voir sur PASS50';
$destination=$base.'/';
$imageLabel='PASS50';
$imageSubtitle='Qui dit quoi, qui va où ?';
$audioPublicUrl='';

if(str_starts_with($type,'ranking-top')){
    $size=(int)substr($type,strlen('ranking-top'));
    if(!in_array($size,[3,10,50],true))$size=3;
    $rows=p50_context_ranking($state,$period,$region,$size);
    $leader=$rows[0]??null;
    $leaderName=p50_context_clean((string)($leader['name']??''),80);
    $leaderScore=$leader?round((float)($leader['_context_score']??0)):null;
    $periodText=p50_context_period_label($period);
    $regionText=p50_context_region_label($region);
    $title="Top {$size} PASS50 — {$periodText}";
    $description="Le classement PASS50 {$regionText} sur {$periodText}.";
    if($leaderName!=='')$description.=" Numéro 1 : {$leaderName}".($leaderScore!==null?" ({$leaderScore}/100).":'.');
    $accent='#b7ff00';
    $kicker='CLASSEMENT OFFICIEL';
    $badge="📊 TOP {$size}";
    $cta=$size===50?'Voir le classement complet':"Voir le Top {$size}";
    $section=$size===3?'buzz':($size===10?'top10':'top10');
    $query=['source'=>'share_ranking','ranking'=>"top{$size}",'period'=>$period,'region'=>$region,'section'=>$section];
    if($size===50)$query['open']='top50';
    $destination=$base.'/?'.http_build_query($query);
    $imageLabel="TOP {$size} PASS50";
    $imageSubtitle="{$periodText} · {$regionText}";
}elseif($type==='feed-post'){
    $profile=$id!==''?p50_context_profile($state,$id):null;
    $name=p50_context_clean((string)($profile['name']??'Influenceur PASS50'),80);
    if($postTitle==='')$postTitle='Actualité récente';
    $title="{$name} — {$postTitle}";
    $description=($platform!==''?$platform.' · ':'')."Découvrez cette actualité et la position de {$name} dans le classement PASS50.";
    $accent='#1ee5ff';
    $kicker='POST DE MON FIL';
    $badge='📰 ACTUALITÉ';
    $cta='Voir la fiche et le contenu';
    $query=['source'=>'share_feed','profile'=>$id];
    $destination=$base.'/?'.http_build_query(array_filter($query,static fn($value)=>$value!==''));
    $imageLabel=$name;
    $imageSubtitle=$postTitle;
}elseif($type==='duel-audio'){
    $audio=p50_context_audio($pdo,$audioToken);
    $author=p50_context_clean((string)($audio['author_display_name']??'Membre PASS50'),60);
    $a=p50_context_clean((string)($audio['candidate_a_name']??'Influenceur A'),70);
    $b=p50_context_clean((string)($audio['candidate_b_name']??'Influenceur B'),70);
    $selectedId=(string)($audio['selected_profile_id']??'');
    $selected=$selectedId!==''&&$selectedId===(string)($audio['candidate_a_id']??'')?$a:$b;
    $title="{$author} commente son vote pour {$selected}";
    $description="Écoutez le commentaire audio public de {$author} pour le duel {$a} VS {$b} sur PASS50.";
    $accent='#a66cff';
    $kicker='AUDIO PUBLIC · LES COULÉS';
    $badge='🎙 AUDIO DU DUEL';
    $cta='Écouter l’audio et voir le duel';
    $query=['source'=>'share_duel_audio','section'=>'coules','audio'=>$audioToken];
    $destination=$base.'/?'.http_build_query(array_filter($query,static fn($value)=>$value!==''));
    $imageLabel=$author;
    $imageSubtitle="{$a} VS {$b}";
    if($audio&&$audioToken!=='')$audioPublicUrl=$base.'/uploads/duel-audio/'.rawurlencode($audioToken);
}

$canonicalParams=['type'=>$type];
foreach(['period'=>$period,'region'=>$region,'id'=>$id,'title'=>$postTitle,'platform'=>$platform,'audio'=>$audioToken] as $key=>$value){
    if($value!=='')$canonicalParams[$key]=$value;
}
$canonical=$base.'/partage-contexte.php?'.http_build_query($canonicalParams);
$image=$base.'/partage-contexte-image.php?'.http_build_query([
    'type'=>$type,
    'label'=>$imageLabel,
    'subtitle'=>$imageSubtitle,
]);
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="<?=p50_context_h($accent)?>">
<title><?=p50_context_h($title)?></title>
<meta name="description" content="<?=p50_context_h($description)?>">
<link rel="canonical" href="<?=p50_context_h($canonical)?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="PASS50">
<meta property="og:title" content="<?=p50_context_h($title)?>">
<meta property="og:description" content="<?=p50_context_h($description)?>">
<meta property="og:url" content="<?=p50_context_h($canonical)?>">
<meta property="og:image" content="<?=p50_context_h($image)?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="<?=p50_context_h($imageLabel)?>">
<?php if($audioPublicUrl!==''): ?>
<meta property="og:audio" content="<?=p50_context_h($audioPublicUrl)?>">
<meta property="og:audio:type" content="audio/webm">
<?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?=p50_context_h($title)?>">
<meta name="twitter:description" content="<?=p50_context_h($description)?>">
<meta name="twitter:image" content="<?=p50_context_h($image)?>">
<meta http-equiv="refresh" content="1;url=<?=p50_context_h($destination)?>">
<style>
:root{--accent:<?=p50_context_h($accent)?>}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:18px;background:#050705;color:#fff;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.card{position:relative;width:min(500px,100%);min-height:570px;padding:34px;border:1px solid var(--accent);border-radius:28px;overflow:hidden;background:radial-gradient(circle at 95% 0,color-mix(in srgb,var(--accent) 24%,transparent),transparent 38%),linear-gradient(150deg,#151b15,#050705 72%);box-shadow:0 32px 100px rgba(0,0,0,.7);display:flex;flex-direction:column}.card:before{content:"";position:absolute;inset:0 auto 0 0;width:9px;background:var(--accent)}.brand{font-size:32px;font-weight:1000;letter-spacing:-1.7px}.brand span{color:var(--accent)}.kicker{margin-top:52px;color:var(--accent);font-size:12px;font-weight:1000;letter-spacing:1.8px}.pill{align-self:flex-start;margin-top:14px;padding:9px 14px;border:1px solid var(--accent);border-radius:999px;color:var(--accent);font-size:12px;font-weight:1000}.title{margin:38px 0 0;font-size:39px;line-height:1.05;letter-spacing:-1.7px}.desc{margin-top:14px;color:#aeb8aa;font-weight:800;line-height:1.48}.cta{display:block;margin-top:auto;padding:17px;border-radius:16px;background:var(--accent);color:#050705;text-align:center;text-decoration:none;font-weight:1000}.small{margin-top:12px;text-align:center;color:#879184;font-size:12px}
</style>
</head>
<body>
<main class="card">
<div class="brand">PASS<span>50</span></div>
<div class="kicker"><?=p50_context_h($kicker)?></div>
<div class="pill"><?=p50_context_h($badge)?></div>
<h1 class="title"><?=p50_context_h($title)?></h1>
<p class="desc"><?=p50_context_h($description)?></p>
<a class="cta" href="<?=p50_context_h($destination)?>"><?=p50_context_h($cta)?></a>
<div class="small">Redirection vers PASS50…</div>
</main>
<script>window.setTimeout(function(){location.replace(<?=json_encode($destination,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>)},120);</script>
</body>
</html>
