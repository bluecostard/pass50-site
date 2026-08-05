<?php
declare(strict_types=1);

$type=trim((string)($_GET['type']??'site'));
$allowed=['site','profile','live','coules','coules-audio'];
if(!in_array($type,$allowed,true))$type='site';
$label=trim(preg_replace('/[\x00-\x1F\x7F]/u',' ',(string)($_GET['label']??''))??'');
$label=mb_substr($label!==''?$label:'Influenceur',0,54);
$platform=trim(preg_replace('/[^A-Za-z0-9 ._-]/','',(string)($_GET['platform']??''))??'');
$themes=[
  'site'=>['#0e7c7b','CLASSEMENT','Qui fait le buzz ?','Découvrir'],
  'profile'=>['#3d5a1f','FICHE',$label,'Voir la fiche'],
  'live'=>['#b42318','EN DIRECT',$label,'Regarder'],
  'coules'=>['#b45309','LES COULÉS','Mon vote','Voir le duel'],
  'coules-audio'=>['#1d4e89','LES COULÉS','Vote commenté','Écouter'],
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
function p50_img_fit(string $text): array {
    $words=preg_split('/\s+/u',trim($text))?:[];
    $lines=[];$line='';
    foreach($words as $word){
        $next=$line===''?$word:$line.' '.$word;
        if(mb_strlen($next)<=22)$line=$next;
        else{if($line!=='')$lines[]=$line;$line=$word;if(count($lines)>=2)break;}
    }
    if($line!==''&&count($lines)<3)$lines[]=$line;
    return array_slice($lines,0,3);
}
$image=imagecreatetruecolor(1200,630);
$paper=p50_img_color($image,'#eef1ec');
$ink=p50_img_color($image,'#0b0f0b');
$muted=p50_img_color($image,'#5c665c');
$lime=p50_img_color($image,'#b7ff00');
$accentColor=p50_img_color($image,$accent);
$line=p50_img_color($image,'#d5dbd2');
imagefill($image,0,0,$paper);
imagefilledrectangle($image,0,0,1200,16,$accentColor);
imagefilledrectangle($image,64,48,86,70,$lime);

$font='/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
$fontRegular='/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
$ttf=is_file($font)&&function_exists('imagettftext');

if($ttf){
    imagettftext($image,28,0,100,68,$ink,$font,'PASS50');
    imagettftext($image,15,0,64,118,$accentColor,$font,$kicker);
    $lines=p50_img_fit($title);
    foreach($lines as $i=>$lineText)imagettftext($image,40,0,64,220+$i*56,$ink,$font,$lineText);
    $subtitle=$type==='live'&&$platform!==''?'Sur '.strtoupper($platform):'pass50.store';
    imagettftext($image,18,0,64,420,$muted,$fontRegular,$subtitle);
    imagefilledrectangle($image,64,470,1136,472,$line);
    imagefilledrectangle($image,64,510,1136,580,$lime);
    $ctaBox=imagettfbbox(22,0,$font,$cta);
    $ctaW=abs(($ctaBox[2]??0)-($ctaBox[0]??0));
    imagettftext($image,22,0,(int)((1200-$ctaW)/2),556,$ink,$font,$cta);
}else{
    imagestring($image,5,100,50,'PASS50',$ink);
    imagestring($image,4,64,110,$kicker,$accentColor);
    imagestring($image,5,64,200,substr($title,0,42),$ink);
    imagefilledrectangle($image,64,510,1136,580,$lime);
    imagestring($image,5,500,540,$cta,$ink);
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
imagepng($image,null,6);
imagedestroy($image);
