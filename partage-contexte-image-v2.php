<?php
declare(strict_types=1);

require_once __DIR__.'/api/share-photo-core.php';

$type=trim((string)($_GET['type']??'ranking-top3'));
$allowed=['ranking-top3','ranking-top10','ranking-top50','feed-post','duel-audio'];
if(!in_array($type,$allowed,true))$type='ranking-top3';
$period=trim((string)($_GET['period']??'24H'));
if(!in_array($period,['2H','24H','48H','7J','15J'],true))$period='24H';
$region=trim((string)($_GET['region']??'ALL'));
if(!in_array($region,['ALL','CI','DIASPORA'],true))$region='ALL';
$id=trim((string)($_GET['id']??''));
$title=trim(preg_replace('/[\x00-\x1F\x7F]/u',' ',(string)($_GET['title']??''))??'');
$title=mb_substr($title,0,150);
$audioToken=trim((string)($_GET['audio']??''));
if(!preg_match('/^[A-Za-z0-9._-]{1,180}$/',$audioToken))$audioToken='';

if(!extension_loaded('gd')){
    header('Location: assets/pass50-og.png',true,302);
    exit;
}

function p50_og_v2_color(GdImage $image,string $hex): int {
    $hex=ltrim($hex,'#');
    return imagecolorallocate($image,hexdec(substr($hex,0,2)),hexdec(substr($hex,2,2)),hexdec(substr($hex,4,2)));
}
function p50_og_v2_font(bool $bold=true): string {
    $path=$bold?'/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf':'/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
    return is_file($path)?$path:'';
}
function p50_og_v2_text(GdImage $image,int $size,int $x,int $y,int $color,string $text,bool $bold=true): void {
    $font=p50_og_v2_font($bold);
    if($font!==''&&function_exists('imagettftext'))imagettftext($image,$size,0,$x,$y,$color,$font,$text);
    else imagestring($image,5,$x,$y-max(12,$size),mb_substr($text,0,70),$color);
}
function p50_og_v2_fit(string $text,int $maxChars,int $maxLines=2): array {
    $words=preg_split('/\s+/u',trim($text))?:[];$lines=[];$line='';
    foreach($words as $word){
        $next=$line===''?$word:$line.' '.$word;
        if(mb_strlen($next)<=$maxChars)$line=$next;
        else{if($line!=='')$lines[]=$line;$line=$word;if(count($lines)>=$maxLines-1)break;}
    }
    if($line!==''&&count($lines)<$maxLines)$lines[]=$line;
    return array_slice($lines,0,$maxLines);
}
function p50_og_v2_score(array $profile,string $period): float {
    $scores=is_array($profile['scores']??null)?$profile['scores']:[];
    return max(0,min(100,(float)($scores[$period]??$scores['24H']??0)));
}
function p50_og_v2_ranking(string $period,string $region,int $limit): array {
    $rows=[];
    foreach(p50_share_photo_profiles() as $profile){
        if(($profile['alive']??true)===false||($profile['eligible']??true)===false||($profile['classable']??true)===false)continue;
        $profileRegion=(string)($profile['region']??'ALL');
        if($region!=='ALL'&&$profileRegion!==$region&&$profileRegion!=='BOTH')continue;
        $profile['_og_score']=p50_og_v2_score($profile,$period);$rows[]=$profile;
    }
    usort($rows,static function(array $a,array $b): int {
        $score=((float)$b['_og_score'])<=>((float)$a['_og_score']);
        return $score!==0?$score:strnatcasecmp((string)($a['name']??''),(string)($b['name']??''));
    });
    return array_slice($rows,0,max(1,min(50,$limit)));
}
function p50_og_v2_audio(string $token): ?array {
    if($token==='')return null;$pdo=p50_share_photo_pdo();if(!$pdo)return null;
    try{
        $stmt=$pdo->prepare("SELECT p.*,u.display_name author_display_name FROM p50_duel_audio_posts p JOIN users u ON u.id=p.user_id AND u.deleted_at IS NULL WHERE p.file_name=? AND p.status='published' AND p.expires_at>UTC_TIMESTAMP() LIMIT 1");
        $stmt->execute([$token]);$row=$stmt->fetch();return is_array($row)?$row:null;
    }catch(Throwable $e){error_log('PASS50 OG v2 audio: '.$e->getMessage());return null;}
}
function p50_og_v2_initials(string $name): string {
    $parts=preg_split('/\s+/u',trim($name))?:[];$value='';
    foreach(array_slice($parts,0,2) as $part)$value.=mb_substr($part,0,1);
    return mb_strtoupper($value!==''?$value:'P50','UTF-8');
}
function p50_og_v2_avatar(GdImage $canvas,?array $profile,int $x,int $y,int $size,int $accent,int $white): void {
    $source=null;
    if($profile){$asset=p50_share_photo_asset_for_profile($profile);if($asset)$source=@imagecreatefromstring((string)$asset['bytes']);}
    $mask=imagecreatetruecolor($size,$size);$transparent=imagecolorallocate($mask,1,2,1);imagefill($mask,0,0,$transparent);imagecolortransparent($mask,$transparent);
    if($source instanceof GdImage){
        $w=imagesx($source);$h=imagesy($source);$side=min($w,$h);$sx=(int)(($w-$side)/2);$sy=(int)(($h-$side)/2);
        imagecopyresampled($mask,$source,0,0,$sx,$sy,$size,$size,$side,$side);imagedestroy($source);
    }else{
        $bg=imagecolorallocate($mask,31,40,31);imagefilledrectangle($mask,0,0,$size,$size,$bg);
        p50_og_v2_text($mask,max(14,(int)($size*.25)),(int)($size*.23),(int)($size*.62),$accent,p50_og_v2_initials((string)($profile['name']??'P50')));
    }
    $diameter=$size;$radius=$diameter/2;
    for($py=0;$py<$diameter;$py++)for($px=0;$px<$diameter;$px++){
        $dx=$px-$radius;$dy=$py-$radius;if($dx*$dx+$dy*$dy>$radius*$radius)imagesetpixel($mask,$px,$py,$transparent);
    }
    imagecopy($canvas,$mask,$x,$y,0,0,$size,$size);imagedestroy($mask);
    imagesetthickness($canvas,max(2,(int)($size*.035)));imageellipse($canvas,$x+(int)($size/2),$y+(int)($size/2),$size-3,$size-3,$accent);
}

$image=imagecreatetruecolor(1200,630);
$bg=p50_og_v2_color($image,'#050705');$panel=p50_og_v2_color($image,'#111711');$white=p50_og_v2_color($image,'#ffffff');$muted=p50_og_v2_color($image,'#aeb8aa');$lime=p50_og_v2_color($image,'#b7ff00');$cyan=p50_og_v2_color($image,'#1ee5ff');$purple=p50_og_v2_color($image,'#a66cff');
imagefill($image,0,0,$bg);imagefilledrectangle($image,20,20,1180,610,$panel);
$accent=str_starts_with($type,'ranking-')?$lime:($type==='duel-audio'?$purple:$cyan);
imagefilledrectangle($image,20,20,38,610,$accent);imagefilledellipse($image,1130,35,420,420,$accent);
p50_og_v2_text($image,42,72,82,$white,'PASS');p50_og_v2_text($image,42,200,82,$accent,'50');

if(str_starts_with($type,'ranking-top')){
    $size=(int)substr($type,strlen('ranking-top'));if(!in_array($size,[3,10,50],true))$size=3;
    $rows=p50_og_v2_ranking($period,$region,$size);
    p50_og_v2_text($image,18,72,145,$accent,'CLASSEMENT OFFICIEL');p50_og_v2_text($image,38,72,205,$white,"TOP {$size} PASS50");
    $periodLabel=['2H'=>'2 h','24H'=>'24 h','48H'=>'48 h','7J'=>'7 jours','15J'=>'15 jours'][$period]??$period;
    $regionLabel=['ALL'=>'Côte d’Ivoire + diaspora','CI'=>'Côte d’Ivoire','DIASPORA'=>'Diaspora'][$region]??$region;
    p50_og_v2_text($image,17,72,242,$muted,$periodLabel.' · '.$regionLabel,false);
    if($size===3){
        foreach(array_slice($rows,0,3) as $index=>$profile){
            $x=80+$index*370;p50_og_v2_avatar($image,$profile,$x,285,150,$accent,$white);
            p50_og_v2_text($image,22,$x,475,$accent,'#'.($index+1));
            foreach(p50_og_v2_fit(mb_strtoupper((string)($profile['name']??'Influenceur'),'UTF-8'),15,2) as $lineIndex=>$line)p50_og_v2_text($image,20,$x+42,475+$lineIndex*28,$white,$line);
            p50_og_v2_text($image,18,$x+42,545,$accent,round((float)($profile['_og_score']??0)).'/100');
        }
    }else{
        $visible=array_slice($rows,0,10);
        foreach($visible as $index=>$profile){
            $column=$index<5?0:1;$local=$index%5;$x=$column===0?70:620;$y=285+$local*61;
            p50_og_v2_avatar($image,$profile,$x,$y-35,45,$accent,$white);
            p50_og_v2_text($image,18,$x+58,$y,$accent,'#'.($index+1));
            p50_og_v2_text($image,18,$x+100,$y,$white,mb_substr((string)($profile['name']??'Influenceur'),0,24));
            p50_og_v2_text($image,17,$x+420,$y,$accent,round((float)($profile['_og_score']??0)).'/100');
        }
        if($size===50)p50_og_v2_text($image,14,840,590,$muted,'APERÇU DU TOP 10 / 50',false);
    }
}elseif($type==='feed-post'){
    $profile=p50_share_photo_profile_by_id($id);$name=(string)($profile['name']??'Influenceur PASS50');
    p50_og_v2_text($image,18,72,145,$accent,'POST DE MON FIL');p50_og_v2_avatar($image,$profile,80,215,260,$accent,$white);
    p50_og_v2_text($image,31,390,260,$white,mb_strtoupper(mb_substr($name,0,28),'UTF-8'));
    $post=$title!==''?$title:'Actualité récente';foreach(p50_og_v2_fit($post,30,4) as $index=>$line)p50_og_v2_text($image,25,390,335+$index*40,$white,$line);
    p50_og_v2_text($image,17,390,540,$accent,'VOIR LA FICHE ET LE CONTENU');
}else{
    $audio=p50_og_v2_audio($audioToken);$a=(string)($audio['candidate_a_name']??'Influenceur A');$b=(string)($audio['candidate_b_name']??'Influenceur B');$profileA=p50_share_photo_profile_by_id((string)($audio['candidate_a_id']??''));$profileB=p50_share_photo_profile_by_id((string)($audio['candidate_b_id']??''));$author=(string)($audio['author_display_name']??'Membre PASS50');
    p50_og_v2_text($image,18,72,145,$accent,'AUDIO PUBLIC · LES COULÉS');p50_og_v2_avatar($image,$profileA,95,225,190,$accent,$white);p50_og_v2_avatar($image,$profileB,330,225,190,$accent,$white);
    p50_og_v2_text($image,24,270,445,$accent,'VS');p50_og_v2_text($image,26,575,260,$white,mb_strtoupper(mb_substr($author,0,26),'UTF-8'));
    foreach(p50_og_v2_fit("Commente son vote dans {$a} VS {$b}",28,4) as $index=>$line)p50_og_v2_text($image,23,575,330+$index*38,$white,$line);
    p50_og_v2_text($image,17,575,535,$accent,'ÉCOUTER L’AUDIO SUR PASS50');
}

header('Content-Type: image/png');header('Cache-Control: public, max-age=21600, stale-while-revalidate=86400');header('X-Content-Type-Options: nosniff');imagepng($image,null,6);imagedestroy($image);
