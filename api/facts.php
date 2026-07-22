<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/data-engine-core.php';
$user=auth_user();
require_role($user,'owner','admin');
p50_de_ensure_schema();
p50_de_sync_registry_from_state();


function p50_birth_normalize_input(string $value): string {
    $value=trim($value);
    if($value==='')return '';
    $value=preg_replace('/[.\\]/','/',$value)??$value;
    $value=str_replace('-','/',$value);
    $year=$month=$day=0;
    if(preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/',$value,$m)){
        $year=(int)$m[1];$month=(int)$m[2];$day=(int)$m[3];
    }elseif(preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/',$value,$m)){
        $day=(int)$m[1];$month=(int)$m[2];$year=(int)$m[3];
    }else return '';
    if(!checkdate($month,$day,$year))return '';
    return sprintf('%04d-%02d-%02d',$year,$month,$day);
}

function p50_fact_payload(string $profileId): array {
    $factsStmt=db()->prepare('SELECT profile_id,fact_key,normalized_value,confidence,evidence_count,source_types,status,first_seen_at,last_seen_at,verified_at FROM p50_facts WHERE profile_id=? ORDER BY fact_key,confidence DESC');
    $factsStmt->execute([$profileId]);
    $facts=$factsStmt->fetchAll();
    foreach($facts as &$fact)$fact['sourceTypes']=decode_json_column($fact['source_types']??null,[]);
    unset($fact);
    $eStmt=db()->prepare('SELECT fact_key,normalized_value,source_type,source_name,source_url,source_weight,fetched_at FROM p50_fact_evidence WHERE profile_id=? ORDER BY fetched_at DESC');
    $eStmt->execute([$profileId]);
    return ['facts'=>$facts,'evidence'=>$eStmt->fetchAll()];
}

if($_SERVER['REQUEST_METHOD']==='GET'){
    $profileId=trim((string)($_GET['profileId']??''));
    if($profileId==='')json_response(['error'=>'Profil manquant.'],422);
    $profiles=p50_de_registry_profiles($profileId,1,0);
    if(!$profiles)json_response(['error'=>'Profil introuvable.'],404);
    json_response(['ok'=>true,'profile'=>$profiles[0],'threshold'=>p50_de_threshold()]+p50_fact_payload($profileId));
}

require_method('POST');
$in=json_input();
$profileId=trim((string)($in['profileId']??''));
$factKey=(string)($in['factKey']??'birth_date');
$value=p50_birth_normalize_input((string)($in['value']??''));
$sourceName=trim((string)($in['sourceName']??''));
$sourceUrl=trim((string)($in['sourceUrl']??''));
$confirmedSource=filter_var($in['confirmedSource']??false,FILTER_VALIDATE_BOOLEAN);
if($profileId===''||$factKey!=='birth_date'||$value==='')json_response(['error'=>'Date invalide. Formats acceptés : JJ/MM/AAAA ou AAAA-MM-JJ.'],422);
if(!$confirmedSource)json_response(['error'=>'La confirmation de la source est obligatoire.'],422);
if(!filter_var($sourceUrl,FILTER_VALIDATE_URL)||!p50_public_http_url($sourceUrl))json_response(['error'=>'Source publique invalide.'],422);
$profiles=p50_de_registry_profiles($profileId,1,0);
if(!$profiles)json_response(['error'=>'Profil introuvable.'],404);
$fetch=p50_http_fetch($sourceUrl,14,'text/html,*/*;q=0.7');
$finalUrl=$fetch['ok']&&$fetch['finalUrl']!==''?$fetch['finalUrl']:$sourceUrl;
$dates=$fetch['ok']?p50_de_parse_dates($fetch['body']):[];
$autoMatched=in_array($value,$dates,true);
$host=strtolower((string)(parse_url($finalUrl,PHP_URL_HOST)?:''));
$host=preg_replace('/^www\./','',$host)?:$host;
$canonical=p50_de_normalize_source_url($finalUrl);
if($canonical==='')$canonical=$finalUrl;
$sourceType='manual_source_'.substr(hash('sha256',$canonical),0,20);
$weight=$autoMatched?97:95;
p50_de_add_fact_evidence($profileId,$factKey,$value,$value,$sourceType,$sourceName!==''?$sourceName:$host,$finalUrl,$weight);
p50_de_publish_profile($profileId,$user['id']);
json_response(['ok'=>true,'normalizedValue'=>$value,'automaticMatch'=>$autoMatched,'message'=>'Source ajoutée. La date sera publiée lorsque deux sources distinctes concordent.']+p50_fact_payload($profileId));
