<?php
declare(strict_types=1);

require_once __DIR__.'/metrics-orchestrator-core.php';
require_once __DIR__.'/metrics-ranking-publication-core.php';

const P50_METRICS_PUBLIC_BASELINE_VERSION='PUBLIC-BASELINE-P1-V1.1';

function p50_mopb_platforms(): array {return ['YouTube','X','TikTok','Instagram','Facebook','Snapchat'];}
function p50_mopb_platform_map(): array {return array_fill_keys(p50_mopb_platforms(),0);}

function p50_mopb_public_profile_ids(PDO $pdo,int $limit=100): array {
    $limit=max(1,min(500,$limit));
    if(!p50_metrics_table_exists($pdo,'app_state'))return [];
    $public=p50_mrp_public_state($pdo);
    if(empty($public['exists']))return [];
    $ids=[];
    foreach(['2H','24H','48H','7J','15J'] as $period){
        $ranked=p50_mrp_public_rows((array)$public['state'],$period);
        foreach((array)($ranked['rows']??[]) as $row){
            $id=trim((string)($row['profileId']??''));
            if($id!=='')$ids[$id]=true;
            if(count($ids)>=$limit)break 2;
        }
    }
    return array_keys($ids);
}

function p50_mopb_verified_rows(PDO $pdo,array $profileIds): array {
    if(!$profileIds)return [];
    $placeholders=implode(',',array_fill(0,count($profileIds),'?'));
    $threshold=p50_mc_threshold();
    $stmt=$pdo->prepare("SELECT r.profile_id,s.platform FROM p50_profile_registry r JOIN p50_social_links s ON BINARY s.profile_id=BINARY r.profile_id
      WHERE r.alive=1 AND r.profile_id IN ($placeholders) AND s.status='verified' AND s.confidence>=?
      AND s.platform IN ('YouTube','X','TikTok','Instagram','Facebook','Snapchat') ORDER BY r.profile_id,s.platform LIMIT 3000");
    $stmt->execute([...$profileIds,$threshold]);
    return p50_mo_unique_candidate_rows(array_merge($stmt->fetchAll(),p50_mo_oauth_youtube_rows($pdo,$profileIds),p50_mo_oauth_meta_rows($pdo,$profileIds)));
}

function p50_mopb_increment(array &$summary,string $key,string $platform): void {
    $summary[$key]=($summary[$key]??0)+1;
    $mapKey=$key.'ByPlatform';
    if(!isset($summary[$mapKey])||!is_array($summary[$mapKey]))$summary[$mapKey]=p50_mopb_platform_map();
    $summary[$mapKey][$platform]=($summary[$mapKey][$platform]??0)+1;
}

function p50_mopb_select(PDO $pdo,string $dispatchId,?string $now=null): array {
    p50_metrics_ensure_schema($pdo);
    $cfg=p50_mo_config();$cadence=p50_mo_cadence('p1');$bucket=p50_mo_bucket($cadence,$now);
    $ids=p50_mopb_public_profile_ids($pdo,$cfg['p1Max']);$rows=p50_mopb_verified_rows($pdo,$ids);
    $eligibleProfileIds=array_values(array_unique(array_map('strval',array_column($rows,'profile_id'))));
    $eligibleLinksByPlatform=p50_mopb_platform_map();foreach($rows as $row)$eligibleLinksByPlatform[(string)$row['platform']]++;
    $summary=[
        'publicProfiles'=>count($ids),'eligibleProfiles'=>count($eligibleProfileIds),'eligibleLinks'=>count($rows),
        'publicProfilesWithoutVerifiedSources'=>array_values(array_diff($ids,$eligibleProfileIds)),
        'eligibleLinksByPlatform'=>$eligibleLinksByPlatform,'selectedByPlatform'=>p50_mopb_platform_map(),
        'jobsCreated'=>0,'jobsCreatedByPlatform'=>p50_mopb_platform_map(),'duplicateJobs'=>0,'duplicateJobsByPlatform'=>p50_mopb_platform_map(),
        'skippedFresh'=>0,'skippedFreshByPlatform'=>p50_mopb_platform_map(),
        'skippedConfiguration'=>0,'skippedConfigurationByPlatform'=>p50_mopb_platform_map(),
        'skippedAuthRequired'=>0,'skippedAuthRequiredByPlatform'=>p50_mopb_platform_map(),
        'skippedUnsupported'=>0,'skippedUnsupportedByPlatform'=>p50_mopb_platform_map(),
    ];
    $selectionTime=strtotime($now??'now');if($selectionTime===false)$selectionTime=time();$candidates=[];
    foreach($rows as $row){
        $profileId=(string)$row['profile_id'];$platform=(string)$row['platform'];$access=p50_mc_public_access($platform,$profileId);
        if(!$access['configured']){p50_mopb_increment($summary,'skippedConfiguration',$platform);continue;}
        if(!$access['authorized']){p50_mopb_increment($summary,'skippedAuthRequired',$platform);continue;}
        if(($access['mode']??'')==='unsupported_account_type'){p50_mopb_increment($summary,'skippedUnsupported',$platform);continue;}
        $fresh=$pdo->prepare("SELECT MAX(captured_at) FROM p50_metric_captures WHERE profile_id=? AND platform=? AND quality_status='usable'");
        $fresh->execute([$profileId,$platform]);$last=$fresh->fetchColumn();
        if($last&&strtotime((string)$last)>=$selectionTime-$cfg['fresh']['p1']*60){p50_mopb_increment($summary,'skippedFresh',$platform);continue;}
        $higher=$pdo->prepare("SELECT COUNT(*) FROM p50_metric_jobs WHERE scope_type='profile' AND scope_id=? AND platform=? AND priority<? AND status IN ('pending','running','retry_wait')");
        $higher->execute([$profileId,$platform,$cadence['priority']]);if((int)$higher->fetchColumn()>0){p50_mopb_increment($summary,'skippedFresh',$platform);continue;}
        $idempotency=hash('sha256',implode('|',[P50_METRICS_ORCHESTRATOR_VERSION,'p1',$bucket['key'],$profileId,$platform]));
        $candidates[]=['profileId'=>$profileId,'platform'=>$platform,'contentLimit'=>$cadence['contentLimit'],'observedAt'=>$bucket['observedAt'],'liveConfirmed'=>false,'idempotencyKey'=>$idempotency];
        $summary['selectedByPlatform'][$platform]++;
    }
    return compact('cadence','bucket','summary','candidates')+['version'=>P50_METRICS_PUBLIC_BASELINE_VERSION,'dispatchId'=>$dispatchId];
}

function p50_mopb_dispatch(PDO $pdo,string $dispatchId,?string $now=null): array {
    $dispatchId=trim($dispatchId);
    if($dispatchId===''||strlen($dispatchId)>120||!preg_match('/^[A-Za-z0-9._-]+$/',$dispatchId))throw new InvalidArgumentException('dispatchId invalide.');
    $selection=p50_mopb_select($pdo,$dispatchId,$now);$summary=$selection['summary'];
    foreach($selection['candidates'] as $candidate){
        $payload=$candidate+['cadence'=>'p1','bucket'=>$selection['bucket']['key'],'dispatchId'=>$dispatchId,'reason'=>'public_baseline'];
        $job=p50_metrics_enqueue_job($pdo,['idempotencyKey'=>$candidate['idempotencyKey'],'collector'=>strtolower($candidate['platform']).'_v1','platform'=>$candidate['platform'],'scopeType'=>'profile','scopeId'=>$candidate['profileId'],'priority'=>50,'maxAttempts'=>3,'payload'=>$payload]);
        p50_mopb_increment($summary,$job['created']?'jobsCreated':'duplicateJobs',(string)$candidate['platform']);
    }
    $remaining=(int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_jobs WHERE priority=50 AND status IN ('pending','running','retry_wait')");
    return ['ok'=>true,'version'=>P50_METRICS_PUBLIC_BASELINE_VERSION,'dispatchId'=>$dispatchId,'bucket'=>$selection['bucket'],'summary'=>$summary,'remaining'=>$remaining,'publicStateWrites'=>0];
}
