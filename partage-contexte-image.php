<?php
declare(strict_types=1);

function p50_context_image_clean(string $value,int $max=90): string {
    $value=trim(preg_replace('/[\x00-\x1F\x7F]/u',' ', $value)??'');
    return mb_substr($value,0,$max);
}

$type=p50_context_image_clean((string)($_GET['type']??'ranking-top3'),32);
$allowed=['ranking-top3','ranking-top10','ranking-top50','feed-post','duel-audio'];
if(!in_array($type,$allowed,true))$type='ranking-top3';
$label=p50_context_image_clean((string)($_GET['label']??'PASS50'),70);
$subtitle=p50_context_image_clean((string)($_GET['subtitle']??'Qui dit quoi, qui va où ?'),110);

$themes=[
    'ranking-top3'=>['#b7ff00','CLASSEMENT OFFICIEL','TOP 3 PASS50','VOIR LE TOP 3'],
    'ranking-top10'=>['#b7ff00','CLASSEMENT OFFICIEL','TOP 10 PASS50','VOIR LE TOP 10'],
    'ranking-top50'=>['#b7ff00','CLASSEMENT OFFICIEL','TOP 50 PASS50','VOIR LES 50'],
    'feed-post'=>['#1ee5ff','POST DE MON FIL',strtoupper($label),'VOIR LA FICHE'],
    'duel-audio'=>['#a66cff','AUDIO PUBLIC · LES COULÉS',strtoupper($label),'ÉCOUTER L’AUDIO'],
];
[$accent,$kicker,$title,$cta]=$themes[$type];

if(!extension_loaded('gd')){
    header('Location: assets/pass50-og.png',true,302);
    exit;
}

function p50_context_image_color(GdImage $image,string $hex): int {
    $hex=ltrim($hex,'#');
    return imagecolorallocate($image,hexdec(substr($hex,0,2)),hexdec(substr($hex,2,2)),hexdec(substr($hex,4,2)));
}

function p50_context_image_lines(string $text,int $maxChars=24,int $maxLines=3): array {
    $words=preg_split('/\s+/u',trim($text))?:[];
    $lines=[];$line='';
    foreach($words as $word){
        $next=$line===''?$word:$line.' '.$word;
        if(mb_strlen($next)<=$maxChars)$line=$next;
        else{
            if($line!=='')$lines[]=$line;
            $line=$word;
            if(count($lines)>=$maxLines-1)break;
        }
    }
    if($line!==''&&count($lines)<$maxLines)$lines[]=$line;
    return array_slice($lines,0,$maxLines);
}

$image=imagecreatetruecolor(1200,630);
$bg=p50_context_image_color($image,'#050705');
$panel=p50_context_image_color($image,'#111711');
$white=p50_context_image_color($image,'#ffffff');
$muted=p50_context_image_color($image,'#aeb8aa');
$accentColor=p50_context_image_color($image,$accent);
imagefill($image,0,0,$bg);
imagefilledrectangle($image,22,22,1178,608,$panel);
imagefilledrectangle($image,22,22,38,608,$accentColor);
imagefilledellipse($image,1095,38,430,430,$accentColor);

$font='/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
$fontRegular='/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
$ttf=is_file($font)&&function_exists('imagettftext');

if($ttf){
    imagettftext($image,42,0,78,82,$white,$font,'PASS');
    imagettftext($image,42,0,205,82,$accentColor,$font,'50');
    imagettftext($image,17,0,78,145,$accentColor,$font,$kicker);
    imagefilledrectangle($image,78,174,680,230,$bg);
    imagerectangle($image,78,174,680,230,$accentColor);
    $badge=$type==='duel-audio'?'🎙 AUDIO PASS50':($type==='feed-post'?'📰 MON FIL':'📊 CLASSEMENT');
    imagettftext($image,17,0,101,211,$accentColor,$font,$badge);
    $lines=p50_context_image_lines($title,20,3);
    foreach($lines as $i=>$line)imagettftext($image,48,0,78,328+$i*62,$white,$font,$line);
    $subtitleLines=p50_context_image_lines($subtitle,44,2);
    foreach($subtitleLines as $i=>$line)imagettftext($image,20,0,78,505+$i*30,$muted,$fontRegular,$line);
    imagefilledrectangle($image,760,500,1122,574,$accentColor);
    imagettftext($image,20,0,800,548,$bg,$font,$cta);
    imagettftext($image,15,0,78,585,$muted,$fontRegular,'PASS50.STORE');
}else{
    imagestring($image,5,78,62,'PASS',$white);
    imagestring($image,5,142,62,'50',$accentColor);
    imagestring($image,4,78,128,$kicker,$accentColor);
    imagestring($image,5,78,260,substr($title,0,44),$white);
    imagestring($image,4,78,500,substr($subtitle,0,70),$muted);
    imagefilledrectangle($image,760,500,1122,574,$accentColor);
    imagestring($image,5,825,525,$cta,$bg);
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
imagepng($image,null,6);
imagedestroy($image);
