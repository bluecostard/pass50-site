<?php
declare(strict_types=1);

require_once __DIR__.'/youtube-metrics-bridge-core.php';

const P50_METRICS_CONTROL_CENTER_VERSION='1.0.0';

function p50mcc_age(?string $value): ?array {
    if($value===null||trim($value)==='')return null;
    $timestamp=strtotime($value);if($timestamp===false)return null;
    $seconds=max(0,time()-$timestamp);
    return ['at'=>gmdate('c',$timestamp),'minutes'=>(int)floor($seconds/60),'hours'=>round($seconds/3600,2),'days'=>round($seconds/86400,2)];
}

function p50mcc_platform_row(string $platform): array {
    return [
        'platform'=>$platform,'eligibleProfiles'=>0,'eligibleLinks'=>0,'canonicalAccounts'=>0,'coveredProfiles'=>0,
        'freshProfiles24h'=>0,'captures'=>0,'captures24h'=>0,'usableCaptures'=>0,'quarantinedCaptures'=>0,
        'coveragePercent'=>0,'freshnessPercent'=>0,'latestCaptureAt'=>null,'latestCaptureAge'=>null,
        'lastRunAt'=>null,'lastRunStatus'=>null,'lastError'=>null,'queue'=>['pending'=>0,'running'=>0,'retry_wait'=>0,'failed'=>0],
        'configured'=>false,'authorized'=>false,'mode'=>'','nextExpectedAt'=>null,'state'=>'not_configured','actionRequired'=>'Configurer un accès développeur officiel.',
    ];
}

function p50mcc_status(PDO $pdo,int $threshold): array {
    $platformNames=['YouTube','Facebook','Instagram','TikTok','X','Snapchat'];$platforms=[];
    foreach($platformNames as $platform)$platforms[$platform]=p50mcc_platform_row($platform);

    if(p50_metrics_table_exists($pdo,'p50_social_links')){
        $stmt=$pdo->prepare("SELECT platform,COUNT(*) links,COUNT(DISTINCT profile_id) profiles FROM p50_social_links WHERE status='verified' AND confidence>=? AND platform IN ('YouTube','Facebook','Instagram','TikTok','X','Snapchat') GROUP BY platform");
        $stmt->execute([$threshold]);
        foreach($stmt->fetchAll() as $row){$platform=(string)$row['platform'];if(!isset($platforms[$platform]))continue;$platforms[$platform]['eligibleLinks']=(int)$row['links'];$platforms[$platform]['eligibleProfiles']=(int)$row['profiles'];}
    }
    if(p50_metrics_table_exists($pdo,'p50_metric_accounts')){
        foreach($pdo->query("SELECT platform,COUNT(*) accounts,COUNT(DISTINCT profile_id) profiles FROM p50_metric_accounts WHERE status='active' GROUP BY platform")->fetchAll() as $row){$platform=(string)$row['platform'];if(!isset($platforms[$platform]))continue;$platforms[$platform]['canonicalAccounts']=(int)$row['accounts'];$platforms[$platform]['coveredProfiles']=(int)$row['profiles'];}
    }
    if(p50_metrics_table_exists($pdo,'p50_metric_captures')){
        foreach($pdo->query("SELECT platform,COUNT(*) captures,SUM(quality_status='usable') usable,SUM(quality_status='quarantined') quarantined,SUM(captured_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)) captures24h,COUNT(DISTINCT CASE WHEN captured_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR) AND quality_status='usable' THEN profile_id END) fresh_profiles,MAX(captured_at) latest FROM p50_metric_captures GROUP BY platform")->fetchAll() as $row){
            $platform=(string)$row['platform'];if(!isset($platforms[$platform]))continue;
            $platforms[$platform]['captures']=(int)$row['captures'];$platforms[$platform]['usableCaptures']=(int)$row['usable'];$platforms[$platform]['quarantinedCaptures']=(int)$row['quarantined'];$platforms[$platform]['captures24h']=(int)$row['captures24h'];$platforms[$platform]['freshProfiles24h']=(int)$row['fresh_profiles'];
            $platforms[$platform]['latestCaptureAt']=$row['latest']?gmdate('c',strtotime((string)$row['latest'])):null;$platforms[$platform]['latestCaptureAge']=p50mcc_age($row['latest']?(string)$row['latest']:null);
        }
    }
    if(p50_metrics_table_exists($pdo,'p50_metric_jobs')){
        foreach($pdo->query("SELECT platform,status,COUNT(*) total FROM p50_metric_jobs WHERE platform IS NOT NULL AND status IN ('pending','running','retry_wait','failed') GROUP BY platform,status")->fetchAll() as $row){$platform=(string)$row['platform'];$status=(string)$row['status'];if(isset($platforms[$platform]['queue'][$status]))$platforms[$platform]['queue'][$status]=(int)$row['total'];}
    }
    if(p50_metrics_table_exists($pdo,'p50_metric_runs')){
        foreach($platformNames as $platform){
            $stmt=$pdo->prepare('SELECT status,error_message,finished_at,started_at FROM p50_metric_runs WHERE platform=? OR collector IN (?,?) ORDER BY started_at DESC LIMIT 1');
            $collector=strtolower($platform).'_v1';$oauthCollector=$platform==='YouTube'?'youtube_oauth_v1':'__none__';$stmt->execute([$platform,$collector,$oauthCollector]);$run=$stmt->fetch();
            if($run){$at=(string)($run['finished_at']?:$run['started_at']);$platforms[$platform]['lastRunAt']=$at!==''?gmdate('c',strtotime($at)):null;$platforms[$platform]['lastRunStatus']=(string)$run['status'];$platforms[$platform]['lastError']=function_exists('p50_obs_safe_error')?p50_obs_safe_error($run['error_message']??''):p50_metrics_safe_error($run['error_message']??null);}
        }
    }

    $collectorStatus=function_exists('p50_metrics_collectors_status')?p50_metrics_collectors_status($pdo):[];
    $orchestrator=function_exists('p50_mo_status')?p50_mo_status($pdo):[];
    $next=(array)($orchestrator['nextExpectedAt']??[]);
    foreach($platforms as $platform=>&$row){
        $access=(array)($collectorStatus[strtolower($platform)]??[]);$row['configured']=!empty($access['configured']);$row['authorized']=!empty($access['authorized']);$row['mode']=(string)($access['mode']??'');
        $eligible=max(0,(int)$row['eligibleProfiles']);$covered=min($eligible,max(0,(int)$row['coveredProfiles']));$fresh=min($eligible,max(0,(int)$row['freshProfiles24h']));
        $row['coveragePercent']=$eligible>0?(int)round($covered*100/$eligible):0;$row['freshnessPercent']=$eligible>0?(int)round($fresh*100/$eligible):0;
        $row['missingProfiles']=max(0,$eligible-$covered);$row['staleProfiles']=max(0,$covered-$fresh);$row['nextExpectedAt']=$next['p1']??null;
        if(!$row['configured']){$row['state']='not_configured';$row['actionRequired']='Configurer l’API ou connecter un compte développeur officiel.';}
        elseif(!$row['authorized']){$row['state']='authorization_required';$row['actionRequired']='Autoriser la plateforme ou renouveler son jeton.';}
        elseif($eligible===0){$row['state']='no_verified_links';$row['actionRequired']='Ajouter ou vérifier des liens officiels.';}
        elseif($covered===0){$row['state']='no_coverage';$row['actionRequired']='Lancer la première collecte canonique.';}
        elseif($fresh<$eligible){$row['state']='incomplete';$row['actionRequired']='Collecter les profils manquants ou trop anciens.';}
        elseif(($row['queue']['failed']??0)>0){$row['state']='degraded';$row['actionRequired']='Examiner les tâches échouées.';}
        else{$row['state']='operational';$row['actionRequired']='Aucune action urgente.';}
    }
    unset($row);

    $youtube=p50ym_safe_connections($pdo);
    $summary=['eligibleProfiles'=>0,'coveredProfiles'=>0,'freshProfiles24h'=>0,'captures24h'=>0,'pendingJobs'=>0,'failedJobs'=>0,'operationalPlatforms'=>0];
    foreach($platforms as $row){foreach(['eligibleProfiles','coveredProfiles','freshProfiles24h','captures24h'] as $key)$summary[$key]+=(int)$row[$key];$summary['pendingJobs']+=(int)$row['queue']['pending']+(int)$row['queue']['retry_wait'];$summary['failedJobs']+=(int)$row['queue']['failed'];$summary['operationalPlatforms']+=(int)($row['state']==='operational');}
    $summary['globalCoveragePercent']=$summary['eligibleProfiles']>0?(int)round(min($summary['eligibleProfiles'],$summary['coveredProfiles'])*100/$summary['eligibleProfiles']):0;
    $summary['globalFreshnessPercent']=$summary['eligibleProfiles']>0?(int)round(min($summary['eligibleProfiles'],$summary['freshProfiles24h'])*100/$summary['eligibleProfiles']):0;

    return ['version'=>P50_METRICS_CONTROL_CENTER_VERSION,'threshold'=>$threshold,'generatedAt'=>gmdate('c'),'summary'=>$summary,'platforms'=>array_values($platforms),'youtubeOAuth'=>$youtube,'orchestrator'=>['enabled'=>!empty($orchestrator['enabled']),'automationObservedRecently'=>!empty($orchestrator['automationObservedRecently']),'lastWorkerRun'=>$orchestrator['lastWorkerRun']??null,'nextExpectedAt'=>$next]];
}
