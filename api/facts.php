<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/data-engine-core.php';
$user=auth_user();
require_role($user,'owner','admin');
p50_de_ensure_schema();
p50_de_sync_registry_from_state();

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
$value=trim((string)($in['value']??''));
$sourceName=trim((string)($in['sourceName']??''));
$sourceUrl=trim((string)($in['sourceUrl']??''));
if($profileId===''||$factKey!=='birth_date'||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$value))json_response(['error'=>'Donnée de naissance invalide.'],422);
[$year,$month,$day]=array_map('intval',explode('-',$value));
if(!checkdate($month,$day,$year))json_response(['error'=>'Date invalide.'],422);
if(!filter_var($sourceUrl,FILTER_VALIDATE_URL)||!p50_public_http_url($sourceUrl))json_response(['error'=>'Source publique invalide.'],422);
$profiles=p50_de_registry_profiles($profileId,1,0);
if(!$profiles)json_response(['error'=>'Profil introuvable.'],404);
$fetch=p50_http_fetch($sourceUrl,14,'text/html,*/*;q=0.7');
if(!$fetch['ok'])json_response(['error'=>'La source ne peut pas être consultée automatiquement.','httpStatus'=>$fetch['status']],422);
$dates=p50_de_parse_dates($fetch['body']);
if(!in_array($value,$dates,true))json_response(['error'=>'La date saisie n’a pas été retrouvée dans cette source.','datesFound'=>$dates],422);
$host=strtolower((string)(parse_url($fetch['finalUrl']?:$sourceUrl,PHP_URL_HOST)?:''));
$host=preg_replace('/^www\./','',$host)?:$host;
$sourceType='manual_source_'.substr(hash('sha256',$host),0,16);
p50_de_add_fact_evidence($profileId,$factKey,$value,$value,$sourceType,$sourceName!==''?$sourceName:$host,$fetch['finalUrl']?:$sourceUrl,95);
p50_de_publish_profile($profileId,$user['id']);
json_response(['ok'=>true,'message'=>'Source ajoutée. La date est publiée seulement si au moins deux sources distinctes concordent.']+p50_fact_payload($profileId));
