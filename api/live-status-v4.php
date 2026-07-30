<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/data-engine-core.php';
require __DIR__.'/live-radar-v4-core.php';
require_method('GET');
set_time_limit(60);

try { db()->exec("SET time_zone = '+00:00'"); } catch (Throwable) {}

p50_live_v4_ensure_schema();
p50_de_sync_registry_from_state();
$state=p50_de_load_public_state();
$sources=p50_live_v4_sources($state);

$platformPriority=['TikTok'=>0,'Facebook'=>1,'YouTube'=>2,'Instagram'=>3];
usort($sources,static function(array $a,array $b) use($platformPriority): int {
    $cmp=((int)($a['priority']??3))<=>((int)($b['priority']??3));
    if($cmp!==0)return $cmp;
    $ad=(string)($a['last_checked_at']??'');$bd=(string)($b['last_checked_at']??'');
    if($ad!==$bd){if($ad==='')return -1;if($bd==='')return 1;return strcmp($ad,$bd);}
    $cmp=($platformPriority[(string)($a['platform']??'')]??9)<=>($platformPriority[(string)($b['platform']??'')]??9);
    return $cmp!==0?$cmp:strnatcasecmp((string)($a['public_name']??''),(string)($b['public_name']??''));
});

$profileFilter=trim((string)($_GET['profileId']??''));
if($profileFilter!=='')$sources=array_values(array_filter($sources,static fn($source)=>(string)$source['profile_id']===$profileFilter));
$sourceMap=[];foreach($sources as $source)$sourceMap[(string)$source['source_key']]=$source;

$mode=strtolower((string)($_GET['mode']??'quick'));
if(!in_array($mode,['quick','full','profile','status'],true))$mode='quick';
$force=p50_live_v4_bool_query('force')||in_array($mode,['full','profile'],true);
$batch=max(1,min(12,(int)($_GET['batch']??12)));
$refresh=45;
$lastScan=(string)p50_de_get_setting('live_radar_v4_last_scan_at','');
$lastTs=$lastScan!==''?(strtotime($lastScan)?:0):0;
$canScan=$mode!=='status'&&($force||(time()-$lastTs)>=$refresh);
$cycleId=null;$cycleComplete=true;$cycleScanned=0;$cycleFound=0;$cycleCandidates=0;$cycleTotal=count($sources);$selected=[];$manifest=null;$keys=[];$discoveryQuota=0;

if($mode==='full'){
    $cycleId=p50_live_v4_cycle_id();$cycleKey=p50_live_v4_cycle_key($cycleId);$manifest=p50_de_get_setting($cycleKey,null);
    $valid=is_array($manifest)&&isset($manifest['keys'],$manifest['cursor'],$manifest['createdAt'])&&strtotime((string)$manifest['createdAt'])>time()-900;
    if(!$valid)$manifest=['cycleId'=>$cycleId,'createdAt'=>gmdate(DATE_ATOM),'keys'=>array_values(array_keys($sourceMap)),'cursor'=>0,'scanned'=>0,'found'=>0,'candidates'=>0,'complete'=>false];
    $cycleTotal=count((array)$manifest['keys']);$cursor=max(0,(int)$manifest['cursor']);$keys=array_slice((array)$manifest['keys'],$cursor,$batch);
    foreach($keys as $key)if(isset($sourceMap[$key]))$selected[]=$sourceMap[$key];
    $cycleScanned=(int)$manifest['scanned'];$cycleFound=(int)$manifest['found'];$cycleCandidates=(int)($manifest['candidates']??0);$cycleComplete=$cursor>=$cycleTotal;
}elseif($mode==='profile'){
    $selected=array_slice($sources,0,$batch);$cycleTotal=count($sources);
}elseif($mode==='quick'){
    $discoveryQuota=min(4,$batch);$priorityLimit=max(0,$batch-$discoveryQuota);$priority=array_slice($sources,0,$priorityLimit);$used=[];
    foreach($priority as $source)$used[(string)$source['source_key']]=true;
    $discovery=array_values(array_filter($sources,static fn($source)=>!isset($used[(string)$source['source_key']])&&(int)($source['priority']??3)>=2));
    usort($discovery,static function(array $a,array $b): int {$ad=(string)($a['last_checked_at']??'');$bd=(string)($b['last_checked_at']??'');if($ad===$bd)return strnatcasecmp((string)$a['public_name'],(string)$b['public_name']);if($ad==='')return -1;if($bd==='')return 1;return strcmp($ad,$bd);});
    $selected=array_merge($priority,array_slice($discovery,0,$discoveryQuota));foreach($selected as $source)$used[(string)$source['source_key']]=true;
    if(count($selected)<$batch)foreach($sources as $source){$key=(string)$source['source_key'];if(isset($used[$key]))continue;$selected[]=$source;$used[$key]=true;if(count($selected)>=$batch)break;}
}else{
    $selected=array_slice($sources,0,$batch);
}

$scanPerformed=false;$busy=false;$foundThisPass=0;$candidatesThisPass=0;$replaysThisPass=0;$diagnostics=[];$platformStats=[];
foreach(['TikTok','Facebook','YouTube','Instagram'] as $platform)$platformStats[$platform]=['known'=>count(array_filter($sources,static fn($source)=>(string)$source['platform']===$platform)),'scanned'=>0,'found'=>0,'candidates'=>0,'replays'=>0];

$lock=false;
if($canScan&&$selected){try{$lock=(int)db()->query("SELECT GET_LOCK('pass50_live_radar_v4',0)")->fetchColumn()===1;}catch(Throwable){}}
if($canScan&&$selected&&!$lock)$busy=true;

if($canScan&&$selected&&$lock){
    $scanPerformed=true;
    try{
        foreach(p50_live_v4_scan_batch($selected) as $result){
            $source=$result['source'];$platform=(string)$source['platform'];$profileId=(string)$source['profile_id'];$stateValue=(string)($result['state']??'unknown');
            $platformStats[$platform]['scanned']++;
            $health=p50_live_v4_health_update($source,$result);
            if($stateValue==='live'&&!empty($result['live'])){
                p50_live_v4_store_live($result['live']);$foundThisPass++;$platformStats[$platform]['found']++;
            }elseif($stateValue==='probable'&&!empty($result['live'])){
                p50_live_v4_store_candidate($result['live'],(string)($result['error']??'probable'));$candidatesThisPass++;$platformStats[$platform]['candidates']++;
            }elseif($stateValue==='replay'){
                p50_live_v4_mark_ended($profileId,$platform,'replay',is_array($result['replay']??null)?$result['replay']:null);$replaysThisPass++;$platformStats[$platform]['replays']++;
            }elseif($stateValue==='offline'){
                p50_live_v4_mark_ended($profileId,$platform,(string)($result['error']??'offline'));
            }
            $diagnostics[]=[
                'profileId'=>$profileId,'name'=>(string)$source['public_name'],'platform'=>$platform,'state'=>$stateValue,
                'publicState'=>$stateValue==='probable'?'unconfirmed':$stateValue,
                'lastCheckedAt'=>gmdate(DATE_ATOM),'lastConfirmedAt'=>$stateValue==='live'?gmdate(DATE_ATOM):null,
                'continuityPreserved'=>false,
                'withdrawalReason'=>in_array($stateValue,['live','probable'],true)?'':(string)($result['error']??$stateValue),
                'confidence'=>(int)($result['confidence']??0),'error'=>(string)($result['error']??''),'evidence'=>$result['evidence']??[],'probes'=>$result['probes']??[],
            ];
        }
        $lastScan=gmdate(DATE_ATOM);p50_de_set_setting('live_radar_v4_last_scan_at',$lastScan);
        if($mode==='full'&&is_array($manifest)){
            $manifest['cursor']=min($cycleTotal,(int)$manifest['cursor']+count($keys));
            $manifest['scanned']=(int)$manifest['scanned']+count($selected);$manifest['found']=(int)$manifest['found']+$foundThisPass;$manifest['candidates']=(int)($manifest['candidates']??0)+$candidatesThisPass;
            $manifest['complete']=(int)$manifest['cursor']>=$cycleTotal;$manifest['updatedAt']=gmdate(DATE_ATOM);
            $cycleScanned=(int)$manifest['scanned'];$cycleFound=(int)$manifest['found'];$cycleCandidates=(int)$manifest['candidates'];$cycleComplete=(bool)$manifest['complete'];
            p50_de_set_setting(p50_live_v4_cycle_key((string)$cycleId),$manifest);
            if($cycleComplete)p50_de_set_setting('live_radar_v4_last_full_sweep',['completedAt'=>gmdate(DATE_ATOM),'total'=>$cycleTotal,'found'=>$cycleFound,'candidates'=>$cycleCandidates]);
        }
    }finally{
        try{db()->query("SELECT RELEASE_LOCK('pass50_live_radar_v4')");}catch(Throwable){}
    }
}

$officialKeys=[];foreach($sources as $source)$officialKeys[strtolower((string)$source['platform']).'|'.trim((string)$source['profile_id'])]=true;
$automatic=array_values(array_filter(p50_live_v4_active_rows(),static function(array $stream) use($officialKeys): bool {
    $profileId=trim((string)($stream['profileId']??''));
    if($profileId==='')return false;
    if((string)($stream['source']??'')==='meta_authorized')return true;
    $key=strtolower((string)($stream['platform']??'')).'|'.$profileId;
    return isset($officialKeys[$key]);
}));
$manual=p50_live_v4_manual_streams($state);$streams=p50_live_v4_dedup($automatic,$manual);$healthSummary=p50_live_v4_health_summary($sources,$automatic);
$coverage=$cycleTotal>0?(int)round(($mode==='full'?$cycleScanned:count($selected))*100/$cycleTotal):100;$lastFull=p50_de_get_setting('live_radar_v4_last_full_sweep',null);

json_response(['ok'=>true,'liveStreams'=>$streams,'radar'=>[
    'version'=>'4.1','mode'=>$mode,'scanPerformed'=>$scanPerformed,'busy'=>$busy,'forced'=>$force,'lastScanAt'=>$lastScan?:null,'serverNow'=>gmdate(DATE_ATOM),
    'cycleId'=>$cycleId,'cycleComplete'=>$cycleComplete,'cycleTotal'=>$cycleTotal,'cycleScanned'=>$cycleScanned,
    'sourcesScannedThisPass'=>count($selected),'livesFoundThisPass'=>$foundThisPass,'candidatesFoundThisPass'=>$candidatesThisPass,'replaysFoundThisPass'=>$replaysThisPass,
    'livesFoundInCycle'=>$cycleFound,'candidatesFoundInCycle'=>$cycleCandidates,'coveragePercent'=>$coverage,
    'officialSourcesKnown'=>count($sources),'activeAutomaticConfirmed'=>count($automatic),'platforms'=>$platformStats,'health'=>$healthSummary,'lastFullSweep'=>$lastFull,
    'refreshSeconds'=>$refresh,'batchSize'=>$batch,'discoveryQuota'=>$discoveryQuota,'confidenceThreshold'=>p50_de_threshold(),'platformPriority'=>['TikTok','Facebook','YouTube','Instagram'],'graceMinutes'=>array_replace(P50_LIVE_V4_GRACE_MINUTES,['TikTok'=>2]),'diagnostics'=>$diagnostics,
]]);
