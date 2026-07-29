<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/data-engine-core.php';
require __DIR__.'/live-radar-v4-core.php';
require_method('GET');
set_time_limit(60);

// Les DATETIME du Radar sont échangés comme des dates UTC. Sans ce réglage,
// certains hébergements IONOS renvoient des confirmations plusieurs heures
// dans le futur et le navigateur les retire immédiatement.
try { db()->exec("SET time_zone = '+00:00'"); } catch (Throwable) {}

p50_live_v4_ensure_schema();
p50_de_sync_registry_from_state();
$state=p50_de_load_public_state();
$sources=p50_live_v4_sources($state);
$profileFilter=trim((string)($_GET['profileId']??''));
if($profileFilter!=='')$sources=array_values(array_filter($sources,static fn($source)=>(string)$source['profile_id']===$profileFilter));
$sourceMap=[];foreach($sources as $source)$sourceMap[(string)$source['source_key']]=$source;

$mode=strtolower((string)($_GET['mode']??'quick'));
if(!in_array($mode,['quick','full','profile','status'],true))$mode='quick';
$force=p50_live_v4_bool_query('force')||in_array($mode,['full','profile'],true);
$batch=max(1,min(12,(int)($_GET['batch']??8)));
$refresh=45;
$lastScan=(string)p50_de_get_setting('live_radar_v4_last_scan_at','');
$lastTs=$lastScan!==''?(strtotime($lastScan)?:0):0;
$canScan=$mode!=='status'&&($force||(time()-$lastTs)>=$refresh);
$cycleId=null;$cycleComplete=true;$cycleScanned=0;$cycleFound=0;$cycleCandidates=0;$cycleTotal=count($sources);$selected=[];$manifest=null;$keys=[];

if($mode==='full'){
    $cycleId=p50_live_v4_cycle_id();$cycleKey=p50_live_v4_cycle_key($cycleId);$manifest=p50_de_get_setting($cycleKey,null);
    $valid=is_array($manifest)&&isset($manifest['keys'],$manifest['cursor'],$manifest['createdAt'])&&strtotime((string)$manifest['createdAt'])>time()-900;
    if(!$valid)$manifest=['cycleId'=>$cycleId,'createdAt'=>gmdate(DATE_ATOM),'keys'=>array_values(array_keys($sourceMap)),'cursor'=>0,'scanned'=>0,'found'=>0,'candidates'=>0,'complete'=>false];
    $cycleTotal=count((array)$manifest['keys']);$cursor=max(0,(int)$manifest['cursor']);$keys=array_slice((array)$manifest['keys'],$cursor,$batch);
    foreach($keys as $key)if(isset($sourceMap[$key]))$selected[]=$sourceMap[$key];
    $cycleScanned=(int)$manifest['scanned'];$cycleFound=(int)$manifest['found'];$cycleCandidates=(int)($manifest['candidates']??0);$cycleComplete=$cursor>=$cycleTotal;
}elseif($mode==='profile'){
    $selected=array_slice($sources,0,$batch);$cycleTotal=count($sources);
}else{
    $selected=array_slice($sources,0,$batch);
}

$scanPerformed=false;$busy=false;$foundThisPass=0;$candidatesThisPass=0;$replaysThisPass=0;$diagnostics=[];$platformStats=[];
foreach(P50_LIVE_V4_PLATFORMS as $platform)$platformStats[$platform]=['known'=>count(array_filter($sources,static fn($source)=>(string)$source['platform']===$platform)),'scanned'=>0,'found'=>0,'candidates'=>0,'replays'=>0];

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
                'continuityPreserved'=>$stateValue==='unknown'&&in_array((string)($health['previousState']??''),['live','probable'],true),
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
    // Une connexion Meta autorisée et explicitement associée à une fiche est déjà
    // une source officielle. Elle ne doit pas être supprimée parce que la fiche ne
    // possède pas encore de lien Facebook/Instagram saisi manuellement.
    if((string)($stream['source']??'')==='meta_authorized')return true;
    $key=strtolower((string)($stream['platform']??'')).'|'.$profileId;
    return isset($officialKeys[$key]);
}));
$manual=p50_live_v4_manual_streams($state);$streams=p50_live_v4_dedup($automatic,$manual);$healthSummary=p50_live_v4_health_summary($sources,$automatic);
$coverage=$cycleTotal>0?(int)round(($mode==='full'?$cycleScanned:count($selected))*100/$cycleTotal):100;$lastFull=p50_de_get_setting('live_radar_v4_last_full_sweep',null);

json_response(['ok'=>true,'liveStreams'=>$streams,'radar'=>[
    'version'=>4,'mode'=>$mode,'scanPerformed'=>$scanPerformed,'busy'=>$busy,'forced'=>$force,'lastScanAt'=>$lastScan?:null,'serverNow'=>gmdate(DATE_ATOM),
    'cycleId'=>$cycleId,'cycleComplete'=>$cycleComplete,'cycleTotal'=>$cycleTotal,'cycleScanned'=>$cycleScanned,
    'sourcesScannedThisPass'=>count($selected),'livesFoundThisPass'=>$foundThisPass,'candidatesFoundThisPass'=>$candidatesThisPass,'replaysFoundThisPass'=>$replaysThisPass,
    'livesFoundInCycle'=>$cycleFound,'candidatesFoundInCycle'=>$cycleCandidates,'coveragePercent'=>$coverage,
    'officialSourcesKnown'=>count($sources),'activeAutomaticConfirmed'=>count($automatic),'platforms'=>$platformStats,'health'=>$healthSummary,'lastFullSweep'=>$lastFull,
    'refreshSeconds'=>$refresh,'graceMinutes'=>P50_LIVE_V4_GRACE_MINUTES,'diagnostics'=>$diagnostics,
]]);
