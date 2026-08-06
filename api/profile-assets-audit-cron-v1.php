<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-orchestrator-core.php';
require __DIR__.'/data-engine-core.php';

const P50_PROFILE_ASSETS_AUDIT_VERSION='PROFILE-ASSETS-AUDIT-V1.0';
header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['error'=>'Méthode refusée.'],405);
$raw=file_get_contents('php://input');
if($raw===false||strlen($raw)>32768)json_response(['error'=>'Corps invalide.'],413);
$cfg=p50_mo_config();$secret=(string)$cfg['cronSecret'];
if(!$cfg['enabled']||strlen($secret)<32)json_response(['error'=>'Cron non configuré.'],503);
$timestamp=trim((string)($_SERVER['HTTP_X_P50_TIMESTAMP']??''));
$signature=strtolower(trim((string)($_SERVER['HTTP_X_P50_SIGNATURE']??'')));
if(!preg_match('/^\d{10}$/',$timestamp)||abs(time()-(int)$timestamp)>300)json_response(['error'=>'Horodatage refusé.'],401);
if(!p50_mo_verify_cron_signature($secret,$timestamp,$raw,$signature))json_response(['error'=>'Signature refusée.'],401);
$input=json_decode($raw,true);if(!is_array($input))json_response(['error'=>'JSON invalide.'],422);
$action=(string)($input['action']??'audit');
if(!in_array($action,['probe','audit','repair'],true))json_response(['error'=>'Action invalide.'],422);
$dispatchId=trim((string)($input['dispatchId']??''));
if($dispatchId===''||strlen($dispatchId)>120||!preg_match('/^[A-Za-z0-9._-]+$/',$dispatchId))json_response(['error'=>'dispatchId invalide.'],422);
if($action==='probe')json_response(['ok'=>true,'action'=>'probe','version'=>P50_PROFILE_ASSETS_AUDIT_VERSION,'publicStateWrites'=>0]);

function p50_paa_valid_photo(string $url): bool {
    $url=trim($url);if($url==='')return false;
    if(preg_match('~^(?:\./|/)?(?:assets|uploads|images)/~i',$url))return true;
    return filter_var($url,FILTER_VALIDATE_URL)!==false&&preg_match('~^https://~i',$url)===1;
}
function p50_paa_repo_photos(): array {
    $out=[];$root=dirname(__DIR__);
    foreach(glob($root.'/profile-*.js')?:[] as $file){
        $text=@file_get_contents($file);if(!is_string($text))continue;
        if(!preg_match("~const\s+PROFILE_ID\s*=\s*['\"]([^'\"]+)~",$text,$id))continue;
        if(!preg_match("~photoUrl\s*:\s*['\"]([^'\"]+)~",$text,$photo))continue;
        $url=trim($photo[1]);if(p50_paa_valid_photo($url))$out[$id[1]]=$url;
    }
    return $out;
}
function p50_paa_direct_link(string $platform,string $url): bool {
    $normalized=p50_de_normalize_social_url($platform,$url);
    return $normalized!==''&&p50_platform_host_ok($platform,$normalized)&&p50_de_direct_social_path($platform,$normalized);
}
function p50_paa_history_links(PDO $pdo): array {
    $out=[];
    $put=static function(array &$out,string $pid,string $platform,string $url,int $priority,string $source): void {
        $url=trim($url);if($pid===''||$platform===''||$url===''||!p50_paa_direct_link($platform,$url))return;
        $key=$pid.'|'.$platform;if(isset($out[$key])&&$out[$key]['priority']>$priority)return;
        $out[$key]=['profileId'=>$pid,'platform'=>$platform,'url'=>p50_de_normalize_social_url($platform,$url),'priority'=>$priority,'source'=>$source];
    };
    $stmt=$pdo->query("SELECT profile_id,platform,normalized_url,status,source_types FROM p50_social_links ORDER BY updated_at DESC");
    foreach($stmt->fetchAll() as $row){
        $types=decode_json_column($row['source_types']??null,[]);$manual=array_intersect($types,['manual_owner','manual_admin']);
        $priority=((string)$row['status']==='verified'&&!empty($manual))?100:((string)$row['status']==='verified'?90:60);
        $put($out,(string)$row['profile_id'],(string)$row['platform'],(string)$row['normalized_url'],$priority,'social_links');
    }
    $stmt=$pdo->query("SELECT profile_id,platform,normalized_url,source_type FROM p50_social_link_evidence WHERE source_type IN ('manual_owner','manual_admin','manual_candidate') ORDER BY fetched_at DESC,id DESC");
    foreach($stmt->fetchAll() as $row)$put($out,(string)$row['profile_id'],(string)$row['platform'],(string)$row['normalized_url'],in_array((string)$row['source_type'],['manual_owner','manual_admin'],true)?95:55,'evidence');
    $stmt=$pdo->query("SELECT profile_id,platform,new_url,action_type FROM p50_social_link_audit WHERE new_url IS NOT NULL AND new_url<>'' ORDER BY created_at DESC,id DESC");
    foreach($stmt->fetchAll() as $row)$put($out,(string)$row['profile_id'],(string)$row['platform'],(string)$row['new_url'],in_array((string)$row['action_type'],['confirm','restore','integrity_restore'],true)?85:50,'audit');
    return $out;
}

$pdo=db();p50_de_ensure_schema();p50_de_sync_registry_from_state();
$repoPhotos=p50_paa_repo_photos();$history=p50_paa_history_links($pdo);
$pdo->beginTransaction();
try{
    $state=p50_de_load_public_state_for_update();if(!$state)throw new RuntimeException('État public introuvable.');
    $profiles=&$state['profiles'];if(!is_array($profiles))throw new RuntimeException('Profils introuvables.');
    $rows=[];$restoredLinks=0;$restoredPhotos=0;$profilesChanged=0;$changed=false;
    foreach($profiles as &$profile){
        if(!is_array($profile)||empty($profile['id']))continue;
        $pid=(string)$profile['id'];$name=(string)($profile['name']??$pid);$profileChanged=false;
        $profile['links']=is_array($profile['links']??null)?$profile['links']:[];
        $profile['linkChecks']=is_array($profile['linkChecks']??null)?$profile['linkChecks']:[];
        $missing=[];$invalid=[];$restored=[];
        foreach($history as $candidate){
            if($candidate['profileId']!==$pid)continue;
            $platform=$candidate['platform'];$current=trim((string)($profile['links'][$platform]??''));
            if($current!==''&&p50_paa_direct_link($platform,$current))continue;
            if($current!=='')$invalid[]=$platform;
            $missing[]=$platform;
            if($action==='repair'&&$candidate['priority']>=85){
                $profile['links'][$platform]=$candidate['url'];
                $profile['linkChecks'][$platform]=['status'=>'owner_verified','checkedAt'=>gmdate(DATE_ATOM),'message'=>'Lien restauré depuis l’historique serveur PASS50','persistedServerSide'=>true,'restoredFrom'=>$candidate['source']];
                $profile['platforms']=array_values(array_unique(array_merge((array)($profile['platforms']??[]),[$platform])));
                $restored[]=$platform;$restoredLinks++;$profileChanged=true;
            }
        }
        $photo=trim((string)($profile['photoUrl']??''));$candidate=trim((string)($profile['photoCandidateUrl']??''));$photoSource='current';
        $photoMissing=!p50_paa_valid_photo($photo);
        if($photoMissing&&$action==='repair'){
            $status=strtolower((string)($profile['photoStatus']??''));
            if(p50_paa_valid_photo($candidate)&&(!empty($profile['photoManualLocked'])||in_array($status,['verified','validated','approved','manual_verified'],true))){$photo=$candidate;$photoSource='validated_candidate';}
            elseif(isset($repoPhotos[$pid])){$photo=$repoPhotos[$pid];$photoSource='repository_profile_module';}
            if($photo!==''){
                $profile['photoUrl']=$photo;$profile['photoStatus']='verified';$profile['photoNote']='Photo restaurée automatiquement depuis une source PASS50 persistante.';$restoredPhotos++;$profileChanged=true;$photoMissing=false;
            }
        }
        if($profileChanged){$profilesChanged++;$changed=true;}
        $rows[]=['profileId'=>$pid,'name'=>$name,'currentLinkCount'=>count(array_filter($profile['links'])),'historicalMissingPlatforms'=>array_values(array_unique($missing)),'invalidCurrentPlatforms'=>array_values(array_unique($invalid)),'restoredPlatforms'=>$restored,'photoPresent'=>!$photoMissing,'photoSource'=>$photoSource,'photoUrl'=>$photoMissing?'':$photo];
    }
    unset($profile);
    if($changed&&$action==='repair'){
        $state['stateRevision']=max(0,(int)($state['stateRevision']??0))+1;
        $state['profileAssetsRepair']=['version'=>P50_PROFILE_ASSETS_AUDIT_VERSION,'updatedAt'=>gmdate(DATE_ATOM),'profilesChanged'=>$profilesChanged,'linksRestored'=>$restoredLinks,'photosRestored'=>$restoredPhotos];
        p50_de_save_public_state($state,null,false);
    }
    $pdo->commit();
    $withoutLinks=count(array_filter($rows,static fn($r)=>$r['currentLinkCount']===0));
    $withoutPhoto=count(array_filter($rows,static fn($r)=>!$r['photoPresent']));
    json_response(['ok'=>true,'action'=>$action,'version'=>P50_PROFILE_ASSETS_AUDIT_VERSION,'dispatchId'=>$dispatchId,'generatedAt'=>gmdate(DATE_ATOM),'summary'=>['profilesAudited'=>count($rows),'profilesChanged'=>$profilesChanged,'linksRestored'=>$restoredLinks,'photosRestored'=>$restoredPhotos,'profilesWithoutLinks'=>$withoutLinks,'profilesWithoutPhoto'=>$withoutPhoto],'profiles'=>$rows,'publicStateWrites'=>$changed&&$action==='repair'?1:0]);
}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();json_response(['error'=>'Audit des fiches interrompu.','detail'=>$error->getMessage()],500);}
