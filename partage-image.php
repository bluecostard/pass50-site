<?php
declare(strict_types=1);

$type=trim((string)($_GET['type']??'site'));
$allowed=['site','profile','live','coules','coules-audio'];
if(!in_array($type,$allowed,true))$type='site';
$label=trim(preg_replace('/[\x00-\x1F\x7F]/u',' ',(string)($_GET['label']??'PASS50'))??'PASS50');
$label=mb_substr($label,0,54);
$platform=trim(preg_replace('/[^A-Za-z0-9 ._-]/','',(string)($_GET['platform']??''))??'');
$themes=[
  'site'=>['#1ee5ff','LE SITE','QUI FAIT LE BUZZ ?','DECOUVRIR PASS50'],
  'profile'=>['#b7ff00','FICHE INFLUENCEUR',strtoupper($label),'VOIR LA FICHE'],
  'live'=>['#ff4b4b','EN DIRECT',strtoupper($label),'REGARDER MAINTENANT'],
  'coules'=>['#ff9d1d','LES COULES','MON VOTE PASS50','VOIR LE DUEL'],
  'coules-audio'=>['#a66cff','LES COULES + AUDIO','MON VOTE COMMENTE','ECOUTER ET VOIR'],
];
[$accent,$kicker,$title,$cta]=$themes[$type];

if(!extension_loaded('gd')){
    header('Location: assets/pass50-og.png',true,302);
    exit;
}

function p50_img_color(GdImage $image,string $hex): int {
    $hex=ltrim($hex,'#');
    return imagecolorallocate($image,hexdec(substr($hex,0,2)),hexdec(substr($hex,2,2)),hexdec(substr($hex,4,2)));
}
function p50_img_fit(string $text,int $max=38): array {
    $words=preg_split('/\s+/u',trim($text))?:[];
    $lines=[];$line='';
    foreach($words as $word){
        $next=$line===''?$word:$line.' '.$word;
        if(mb_strlen($next)<=18)$line=$next;
        else{if($line!=='')$lines[]=$line;$line=$word;if(count($lines)>=2)break;}
    }
    if($line!==''&&count($lines)<3)$lines[]=$line;
    return array_slice($lines,0,3);
}
$image=imagecreatetruecolor(1200,630);
$bg=p50_img_color($image,'#050705');
$panel=p50_img_color($image,'#111711');
$white=p50_img_color($image,'#ffffff');
$muted=p50_img_color($image,'#aeb8aa');
$accentColor=p50_img_color($image,$accent);
imagefill($image,0,0,$bg);
imagefilledrectangle($image,22,22,1178,608,$panel);
imagefilledrectangle($image,22,22,38,608,$accentColor);
imagefilledellipse($image,1090,40,430,430,$accentColor);

$font='/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
$fontRegular='/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
$ttf=is_file($font)&&function_exists('imagettftext');

if($ttf){
    imagettftext($image,42,0,78,82,$white,$font,'PASS');
    imagettftext($image,42,0,205,82,$accentColor,$font,'50');
    imagettftext($image,18,0,78,148,$accentColor,$font,$kicker);
    imagefilledrectangle($image,78,178,590,232,$bg);
    imagerectangle($image,78,178,590,232,$accentColor);
    imagettftext($image,18,0,100,215,$accentColor,$font,$type==='live'?'● '.$kicker:$kicker);
    $lines=p50_img_fit($title);
    foreach($lines as $i=>$line)imagettftext($image,48,0,78,330+$i*63,$white,$font,$line);
    $subtitle=$type==='live'&&$platform!==''?'SUR '.strtoupper($platform):'PASS50.STORE';
    imagettftext($image,20,0,78,510,$muted,$fontRegular,$subtitle);
    imagefilledrectangle($image,78,536,1122,590,$accentColor);
    imagettftext($image,24,0,420,574,$bg,$font,$cta);
}else{
    imagestring($image,5,78,62,'PASS',$white);
    imagestring($image,5,142,62,'50',$accentColor);
    imagestring($image,4,78,128,$kicker,$accentColor);
    imagestring($image,5,78,260,substr($title,0,42),$white);
    imagestring($image,4,78,500,'PASS50.STORE',$muted);
    imagefilledrectangle($image,78,536,1122,590,$accentColor);
    imagestring($image,5,470,556,$cta,$bg);
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
imagepng($image,null,6);
imagedestroy($image);
