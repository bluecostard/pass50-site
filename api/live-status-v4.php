<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/data-engine-core.php';
require __DIR__.'/live-radar-v4-core.php';
require_method('GET');
set_time_limit(120);

try { db()->exec("SET time_zone = '+00:00'"); } catch (Throwable) {}

$mode=strtolower(trim((string)($_GET['mode']??'quick')));
if(!in_array($mode,['quick','full','profile','status'],true))$mode='quick';
$force=p50_live_v4_bool_query('force')||in_array($mode,['full','profile'],true);

// Lecture publique : snapshot cache, sans sync registry ni scrape TikTok.
if($mode==='status'&&!$force){
    require_once __DIR__.'/live-status-cache-core.php';
    p50_live_status_cache_respond();
}

p50_live_v4_ensure_schema();
p50_de_sync_registry_if_stale(300);
$state=p50_de_load_public_state();
$sources=p50_live_v4_sources($state);

$platformPriority=['TikTok'=>0,'Facebook'=>1,'YouTube'=>2,'Instagram'=>3];
usort($sources,static function(array $a,array $b) use($platformPriority): int {
    $cmp=((int)($a['priority']??3))<=>((int)($b['priority']??3));
    if($cmp!==0)return $cmp;
    $rankCmp=p50_live_v4_discovery_rank($a)<=>p50_live_v4_discovery_rank($b);
    if($rankCmp!==0)return $rankCmp;
    $ad=(string)($a['last_checked_at']??'');$bd=(string)($b['last_checked_at']??'');
    if($ad!==$bd){if($ad==='')return -1;if($bd==='')return 1;return strcmp($ad,$bd);}
    $cmp=($platformPriority[(string)($a['platform']??'')]??9)<=>($platformPriority[(string)($b['platform']??'')]??9);
    return $cmp!==0?$cmp:strnatcasecmp((string)($a['public_name']??''),(string)($b['public_name']??''));
});

$profileFilter=trim((string)($_GET['profileId']??''));
if($profileFilter!=='')$sources=array_values(array_filter($sources,static fn($source)=>(string)$source['profile_id']===$profileFilter));
$sourceMap=[];foreach($sources as $source)$sourceMap[(string)$source['source_key']]=$source;

$batch=max(1,min(16,(int)($_GET['batch']??14)));
$refresh=30;
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
    // Reconfirmer les lives actifs, mais réserver toujours des slots de découverte.
    $activeKeys=[];
    try{
        foreach(db()->query("SELECT profile_id,platform FROM p50_live_streams WHERE source IN ('automatic','meta_authorized') AND status='live'")->fetchAll() as $row){
            $activeKeys[strtolower((string)$row['platform']).'|'.trim((string)$row['profile_id'])]=true;
        }
    }catch(Throwable){}
    $reconfirm=[];$discovery=[];$used=[];
    foreach($sources as $source){
        $key=strtolower((string)$source['platform']).'|'.trim((string)$source['profile_id']);
        if(isset($activeKeys[$key])||p50_live_v4_needs_p0_rescan($source)||p50_live_v4_is_warm_watch($source)){
            $reconfirm[]=$source;$used[(string)$source['source_key']]=true;
        }else $discovery[]=$source;
    }
    // Meta classifiés par Graph : le cron OAuth suffit, on libère les slots scrape.
    $reconfirm=array_values(array_filter($reconfirm,static fn(array $source): bool => !p50_live_v4_is_graph_fresh($source)));
    $discovery=array_values(array_filter($discovery,static fn(array $source): bool => !p50_live_v4_is_graph_fresh($source)));
    usort($reconfirm,static function(array $a,array $b) use($activeKeys): int {
        $keyA=strtolower((string)$a['platform']).'|'.trim((string)$a['profile_id']);
        $keyB=strtolower((string)$b['platform']).'|'.trim((string)$b['profile_id']);
        $prioA=isset($activeKeys[$keyA])?0:(p50_live_v4_is_p0_source($a)?1:(p50_live_v4_is_warm_watch($a)?2:3));
        $prioB=isset($activeKeys[$keyB])?0:(p50_live_v4_is_p0_source($b)?1:(p50_live_v4_is_warm_watch($b)?2:3));
        if($prioA!==$prioB)return $prioA<=>$prioB;
        $ad=(string)($a['last_checked_at']??'');$bd=(string)($b['last_checked_at']??'');
        if($ad===$bd)return strnatcasecmp((string)$a['public_name'],(string)$b['public_name']);
        if($ad==='')return -1;if($bd==='')return 1;return strcmp($ad,$bd);
    });
    usort($discovery,static function(array $a,array $b): int {
        $rankCmp=p50_live_v4_discovery_rank($a)<=>p50_live_v4_discovery_rank($b);
        if($rankCmp!==0)return $rankCmp;
        $ad=(string)($a['last_checked_at']??'');$bd=(string)($b['last_checked_at']??'');
        if($ad===$bd)return strnatcasecmp((string)$a['public_name'],(string)$b['public_name']);
        if($ad==='')return -1;if($bd==='')return 1;return strcmp($ad,$bd);
    });
    $discoveryFloor=min(5,max(4,(int)floor(($batch*2)/3)));
    $reconfirmCap=max(0,$batch-$discoveryFloor);
    $reconfirm=array_slice($reconfirm,0,$reconfirmCap);
    $discoveryQuota=min($discoveryFloor,max(0,$batch-count($reconfirm)));
    // Réserver une part Meta unknown/never_checked pour remonter Instagram/Facebook.
    $metaNeed=array_values(array_filter($discovery,static function(array $source): bool {
        if(!in_array((string)($source['platform']??''),['Instagram','Facebook'],true))return false;
        $state=strtolower(trim((string)($source['last_state']??'never_checked')));
        return $state===''||$state==='never_checked'||$state==='unknown';
    }));
    $metaFloor=min(2,max(1,(int)floor($discoveryQuota/4)));
    $metaPick=array_slice($metaNeed,0,min($metaFloor,$discoveryQuota));
    $metaUsed=[];foreach($metaPick as $source)$metaUsed[(string)$source['source_key']]=true;
    $discoveryRest=[];foreach($discovery as $source){if(!isset($metaUsed[(string)$source['source_key']]))$discoveryRest[]=$source;}
    $discoveryPick=array_merge($metaPick,array_slice($discoveryRest,0,max(0,$discoveryQuota-count($metaPick))));
    $selected=array_merge($reconfirm,$discoveryPick);
    foreach($selected as $source)$used[(string)$source['source_key']]=true;
    if(count($selected)<$batch)foreach($sources as $source){
        $key=(string)$source['source_key'];
        if(isset($used[$key])||p50_live_v4_is_graph_fresh($source))continue;
        $selected[]=$source;$used[$key]=true;
        if(count($selected)>=$batch)break;
    }
    $selected=array_slice($selected,0,$batch);
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
            $previousState=strtolower(trim((string)($source['last_state']??'never_checked')));
            $platformStats[$platform]['scanned']++;
            $health=p50_live_v4_health_update($source,$result);
            $effectivePrevious=strtolower(trim((string)($health['previousState']??$previousState)));
            if($stateValue==='offline'&&!p50_live_v4_should_end_from_probe($effectivePrevious,$result)){
                $stateValue='unknown';
                $result['state']='unknown';
                $result['error']='tiktok_no_live_signal_while_live';
            }
            if($stateValue==='live'&&!empty($result['live'])){
                if(p50_live_v4_store_live($result['live'])){$foundThisPass++;$platformStats[$platform]['found']++;}
            }elseif($stateValue==='probable'&&!empty($result['live'])){
                p50_live_v4_store_candidate($result['live'],(string)($result['error']??'probable'));$candidatesThisPass++;$platformStats[$platform]['candidates']++;
            }elseif($stateValue==='replay'){
                p50_live_v4_mark_ended($profileId,$platform,'replay',is_array($result['replay']??null)?$result['replay']:null);$replaysThisPass++;$platformStats[$platform]['replays']++;
            }elseif($stateValue==='offline'){
                p50_live_v4_mark_ended($profileId,$platform,(string)($result['error']??'offline'));
            }elseif($stateValue==='unknown'&&($health['previousState']??'')==='live'){
                // Trust Gate : un blocage ne prolonge plus la publication ; le flux sort dès que la fenêtre publique expire.
                db()->prepare("UPDATE p50_live_streams SET metadata=JSON_SET(COALESCE(metadata,'{}'),'$.withdrawalReason','awaiting_reconfirm_after_block') WHERE profile_id=? AND platform=? AND source='automatic' AND status='live'")
                    ->execute([$profileId,$platform]);
            }
            $diagnostics[]=[
                'profileId'=>$profileId,'name'=>(string)$source['public_name'],'platform'=>$platform,'state'=>$stateValue,
                'publicState'=>$stateValue==='probable'?'unconfirmed':$stateValue,
                'lastCheckedAt'=>gmdate(DATE_ATOM),'lastConfirmedAt'=>$stateValue==='live'?gmdate(DATE_ATOM):null,
                'continuityPreserved'=>false,
                'trustGate'=>P50_LIVE_V4_TRUST_REVISION,
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
    }catch(Throwable $error){
        error_log('PASS50 live radar scan: '.substr($error->getMessage(),0,300));
        $diagnostics[]=[
            'profileId'=>'','name'=>'scan_pass','platform'=>'',
            'state'=>'unknown','publicState'=>'unknown',
            'lastCheckedAt'=>gmdate(DATE_ATOM),'lastConfirmedAt'=>null,
            'continuityPreserved'=>false,'trustGate'=>P50_LIVE_V4_TRUST_REVISION,
            'withdrawalReason'=>'scan_pass_exception','confidence'=>0,
            'error'=>substr($error->getMessage(),0,180),'evidence'=>[],'probes'=>[],
        ];
        // Ne pas faire échouer toute la passe : le snapshot public reste servi.
    }finally{
        try{db()->query("SELECT RELEASE_LOCK('pass50_live_radar_v4')");}catch(Throwable){}
    }
}

$officialKeys=[];foreach($sources as $source)$officialKeys[strtolower((string)$source['platform']).'|'.trim((string)$source['profile_id'])]=true;
$automatic=array_values(array_filter(p50_live_v4_active_rows(),static function(array $stream) use($officialKeys): bool {
    $profileId=trim((string)($stream['profileId']??''));
    if($profileId==='')return false;
    if(p50_live_v4_known_false_positive($stream))return false;
    if((string)($stream['source']??'')==='meta_authorized')return true;
    $key=strtolower((string)($stream['platform']??'')).'|'.$profileId;
    return isset($officialKeys[$key]);
}));
$manual=p50_live_v4_manual_streams($state);
$streams=p50_live_v4_filter_public_streams(p50_live_v4_dedup($automatic,$manual));
$healthSummary=p50_live_v4_health_summary($sources,$automatic);
if($scanPerformed){
    $healthFresh=p50_live_v4_health_map();
    foreach($sources as &$source){
        $key=(string)$source['platform'].'|'.(string)$source['profile_id'];
        if(!isset($healthFresh[$key]))continue;
        $source['last_checked_at']=(string)($healthFresh[$key]['last_checked_at']??'');
        $source['last_state']=(string)($healthFresh[$key]['last_state']??'never_checked');
    }
    unset($source);
}
$passCoverage=$cycleTotal>0?(int)round(($mode==='full'?$cycleScanned:count($selected))*100/$cycleTotal):100;
$coverageStats=p50_live_v4_coverage_stats($sources);
$coverage=(int)$coverageStats['coveragePercent'];
$lastFull=p50_de_get_setting('live_radar_v4_last_full_sweep',null);

$payload=['ok'=>true,'liveStreams'=>$streams,'radar'=>[
    'version'=>'4.6','mode'=>$mode,'scanPerformed'=>$scanPerformed,'busy'=>$busy,'forced'=>$force,'lastScanAt'=>$lastScan?:null,'serverNow'=>gmdate(DATE_ATOM),
    'cycleId'=>$cycleId,'cycleComplete'=>$cycleComplete,'cycleTotal'=>$cycleTotal,'cycleScanned'=>$cycleScanned,
    'sourcesScannedThisPass'=>count($selected),'livesFoundThisPass'=>$foundThisPass,'candidatesFoundThisPass'=>$candidatesThisPass,'replaysFoundThisPass'=>$replaysThisPass,
    'livesFoundInCycle'=>$cycleFound,'candidatesFoundInCycle'=>$cycleCandidates,
    'coveragePercent'=>$coverage,'passCoveragePercent'=>$passCoverage,'classifiedPercent'=>(int)$coverageStats['classifiedPercent'],
    'coverageWindowSeconds'=>(int)$coverageStats['windowSeconds'],'coverageCheckedRecent'=>(int)$coverageStats['checkedRecent'],
    'coverageUnknownRecent'=>(int)$coverageStats['unknownRecent'],'coverageRevision'=>P50_LIVE_V4_COVERAGE_REVISION,
    'officialSourcesKnown'=>count($sources),'activeAutomaticConfirmed'=>count($automatic),'platforms'=>$platformStats,'health'=>$healthSummary,'lastFullSweep'=>$lastFull,
    'refreshSeconds'=>$refresh,'batchSize'=>$batch,'discoveryQuota'=>$discoveryQuota,'confidenceThreshold'=>p50_de_threshold(),'platformPriority'=>['TikTok','Facebook','YouTube','Instagram'],
    'graceMinutes'=>p50_live_v4_reconfirm_grace_map(),'trustSeconds'=>p50_live_v4_trust_seconds_map(),'trustGate'=>P50_LIVE_V4_TRUST_REVISION,'diagnostics'=>$diagnostics,
],'generatedAt'=>gmdate('c')];

// Réchauffe le snapshot public après un scan pour que mode=status reste instantané.
if($scanPerformed){
    require_once __DIR__.'/live-status-cache-core.php';
    p50_live_status_cache_store([
        'ok'=>true,
        'contract'=>P50_LIVE_STATUS_CACHE_CONTRACT,
        'cached'=>true,
        'liveStreams'=>$streams,
        'radar'=>array_merge($payload['radar'],[
            'mode'=>'status',
            'scanPerformed'=>false,
            'busy'=>false,
            'forced'=>false,
            'diagnostics'=>[],
            'sourcesScannedThisPass'=>0,
            'livesFoundThisPass'=>0,
            'candidatesFoundThisPass'=>0,
            'replaysFoundThisPass'=>0,
        ]),
        'generatedAt'=>gmdate('c'),
    ]);
}

json_response($payload);
