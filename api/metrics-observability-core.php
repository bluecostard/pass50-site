<?php
declare(strict_types=1);

/**
 * PASS50 Metrics Observability V1.
 *
 * Ce module est volontairement en lecture seule : il ne crée pas de table, ne
 * recalcule aucun score et ne publie jamais dans app_state.
 */

function p50_obs_scalar(PDO $pdo, string $sql, array $params=[]): mixed {
    $stmt=$pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function p50_obs_age(?string $value): ?array {
    if($value===null||trim($value)==='')return null;
    $timestamp=strtotime($value);
    if($timestamp===false)return null;
    $seconds=max(0,time()-$timestamp);
    return [
        'at'=>gmdate('c',$timestamp),
        'minutes'=>(int)floor($seconds/60),
        'hours'=>round($seconds/3600,2),
        'days'=>round($seconds/86400,2),
    ];
}

function p50_obs_safe_error(?string $message): string {
    $message=strip_tags((string)$message);
    $message=preg_replace('/[\x00-\x1F\x7F]+/u',' ',$message)??'';
    $message=preg_replace('#https?://\S+#i','[url]',$message)??'';
    $message=preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i','Bearer [redacted]',$message)??'';
    $message=preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i','[email]',$message)??'';
    $message=preg_replace('/(?:token|key|secret|password)\s*[=:]\s*\S+/i','$1=[redacted]',$message)??'';
    $message=trim(preg_replace('/\s+/u',' ',$message)??'');
    if(function_exists('mb_substr'))return mb_substr($message,0,240,'UTF-8');
    return substr($message,0,240);
}

function p50_obs_metric_count(array $metrics): int {
    return count(p50_de_normalize_score_metrics($metrics));
}

function p50_obs_profile_health(array $events, array $linkConfidences, int $threshold): array {
    $now=time();$views=$likes=$comments=$shares=$saves=0.0;$followers=0.0;
    $latest=0;$eventWeight=0.0;$freshWeight=0.0;$platforms=[];$confidences=$linkConfidences;
    $velocities=[];$hasRecentMetrics=false;
    foreach($events as $event){
        $metrics=p50_de_normalize_score_metrics(decode_json_column($event['metrics']??null,[]));
        $timestamp=strtotime((string)($event['published_at']?:$event['collected_at']))?:0;
        $ageHours=$timestamp>0?max(0,($now-$timestamp)/3600):999999;
        $weight=p50_de_time_weight($ageHours);
        if($weight<=0||!$metrics)continue;
        $hasRecentMetrics=true;
        $weightedViews=(float)($metrics['views']??0)*$weight;
        $views+=$weightedViews;
        $likes+=(float)($metrics['likes']??0)*$weight;
        $comments+=(float)($metrics['comments']??0)*$weight;
        $shares+=(float)($metrics['shares']??0)*$weight;
        $saves+=(float)($metrics['saves']??0)*$weight;
        $followers=max($followers,(float)($metrics['followers']??0)*$weight);
        $latest=max($latest,$timestamp);$eventWeight+=$weight;$freshWeight=max($freshWeight,$weight);
        if($weightedViews>0)$velocities[]=$weightedViews/max(1,$ageHours);
        $platform=(string)$event['platform'];$platforms[$platform]=max((float)($platforms[$platform]??0),$weight);
        $confidences[]=(int)$event['confidence'];
    }
    $engagement=$views>0?($likes+3*$comments+5*$shares+4*$saves)/$views:null;
    $shareRate=$views>0?($shares+$saves)/$views:null;
    $velocity=$velocities?array_sum($velocities)/count($velocities):null;
    $raw=[
        'c1'=>$followers>0?log10(1+$followers):null,
        'c2'=>$views>0?log10(1+$views):null,
        'c3'=>null,
        'c4'=>$engagement,
        'c5'=>$shareRate,
        'c6'=>$comments>0?log10(1+$comments):null,
        'c7'=>$velocity!==null?log10(1+$velocity):null,
        'c8'=>$platforms?array_sum($platforms):null,
        'c9'=>$shares>0?log10(1+$shares):null,
        'c10'=>null,'c11'=>null,'c12'=>null,
        'c13'=>$eventWeight>0?$eventWeight:null,
        'c14'=>($views>0&&($likes+$comments+$shares)>0)?1:null,
        'c15'=>$shares>0?log10(1+$shares):null,
    ];
    $weights=['c1'=>.06,'c2'=>.08,'c3'=>.07,'c4'=>.08,'c5'=>.09,'c6'=>.05,'c7'=>.10,'c8'=>.08,'c9'=>.06,'c10'=>.06,'c11'=>.05,'c12'=>.04,'c13'=>.04,'c14'=>.07,'c15'=>.07];
    $available=0.0;$criteria=0;
    foreach($raw as $key=>$value){
        if($value===null||!is_finite((float)$value))continue;
        $available+=$weights[$key];$criteria++;
    }
    $coverage=round($available*100,2);
    $sourceConfidence=$confidences?array_sum($confidences)/count($confidences):0;
    $confidence=(int)round((.5*$available+.3*$freshWeight+.2*($sourceConfidence/100))*100);
    $reasons=[];
    if(!$hasRecentMetrics)$reasons[]='noRecentMetrics';
    if($confidence<65)$reasons[]='insufficientConfidence';
    if($coverage<60)$reasons[]='insufficientCoverage';
    if($criteria<6)$reasons[]='fewerThanSixCriteria';
    return [
        'measurable'=>$hasRecentMetrics,
        'classable'=>$confidence>=65&&$coverage>=60&&$criteria>=6,
        'confidence'=>$confidence,
        'coverage'=>$coverage,
        'measuredCriteria'=>$criteria,
        'latestMetricAt'=>$latest?gmdate('c',$latest):null,
        'reasons'=>$reasons,
    ];
}

function p50_obs_pipeline_state(PDO $pdo): array {
    $stmt=$pdo->query("SELECT data,updated_at FROM app_state WHERE id='public' LIMIT 1");
    $row=$stmt->fetch()?:[];
    $state=decode_json_column($row['data']??null,[]);
    $meta=is_array($state['dataEngineMeta']['pipeline']??null)?$state['dataEngineMeta']['pipeline']:[];
    $published=(string)($meta['publishedAt']??$state['dataEngineMeta']['lastPublishedAt']??'');
    return [
        'publishedAt'=>$published!==''?(p50_obs_age($published)['at']??null):null,
        'age'=>$published!==''?p50_obs_age($published):null,
        'period'=>(string)($meta['period']??''),
        'recalculatedProfiles'=>(int)($meta['recalculatedProfiles']??0),
        'scoresChanged'=>(int)($meta['scoresChanged']??0),
        'ranksChanged'=>(int)($meta['ranksChanged']??0),
        'stateUpdatedAt'=>p50_obs_age($row['updated_at']??null),
    ];
}

function p50_obs_diagnostic(PDO $pdo, int $threshold=90): array {
    $generatedAt=gmdate('c');
    $volumes=$pdo->query(
        "SELECT
        (SELECT COUNT(*) FROM p50_collection_runs) collection_runs,
        (SELECT COUNT(*) FROM p50_activity_events) activity_events,
        (SELECT COUNT(*) FROM p50_activity_metric_history) activity_metric_history,
        (SELECT COUNT(*) FROM p50_ranking_snapshots) ranking_snapshots,
        (SELECT COUNT(*) FROM p50_profile_registry) profile_registry,
        (SELECT COUNT(*) FROM p50_social_links) social_links"
    )->fetch()?:[];
    $volumes=array_map('intval',$volumes);

    $fresh=$pdo->query(
        "SELECT
        (SELECT MAX(started_at) FROM p50_collection_runs) collection_started,
        (SELECT MAX(finished_at) FROM p50_collection_runs) collection_finished,
        (SELECT MAX(finished_at) FROM p50_collection_runs WHERE status='success') collection_success,
        (SELECT MAX(captured_at) FROM p50_activity_metric_history) metric_capture,
        (SELECT MAX(collected_at) FROM p50_activity_events) activity_event,
        (SELECT MAX(captured_at) FROM p50_ranking_snapshots) ranking_capture"
    )->fetch()?:[];
    $freshness=[];
    foreach($fresh as $key=>$value)$freshness[$key]=p50_obs_age($value!==null?(string)$value:null);

    $runStats=$pdo->query(
        "SELECT
        COUNT(*) total,
        SUM(status='success') success,
        SUM(status='error') errors,
        SUM(status='running') running,
        SUM(status IN ('interrupted','cancelled','aborted')) interrupted,
        SUM(status='running' AND started_at<DATE_SUB(NOW(),INTERVAL 30 MINUTE)) stale_running,
        COUNT(DISTINCT CASE WHEN profile_id IS NOT NULL THEN profile_id END) profiles_processed
        FROM p50_collection_runs"
    )->fetch()?:[];
    $runStats=array_map('intval',$runStats);
    $collectorRows=$pdo->query(
        "SELECT collector,COUNT(*) runs,SUM(status='success') success,SUM(status='error') errors,
        MAX(started_at) last_started_at,MAX(finished_at) last_finished_at
        FROM p50_collection_runs GROUP BY collector ORDER BY last_started_at DESC LIMIT 30"
    )->fetchAll();
    $collectors=[];
    foreach($collectorRows as $row)$collectors[]=[
        'collector'=>(string)$row['collector'],'runs'=>(int)$row['runs'],
        'success'=>(int)$row['success'],'errors'=>(int)$row['errors'],
        'lastStartedAt'=>p50_obs_age($row['last_started_at'])['at']??null,
        'lastFinishedAt'=>p50_obs_age($row['last_finished_at'])['at']??null,
    ];
    $errorRows=$pdo->query(
        "SELECT profile_id,collector,status,error_message,started_at,finished_at
        FROM p50_collection_runs
        WHERE status='error' OR status IN ('interrupted','cancelled','aborted')
        OR (status='running' AND started_at<DATE_SUB(NOW(),INTERVAL 30 MINUTE))
        ORDER BY started_at DESC LIMIT 20"
    )->fetchAll();
    $recentErrors=[];
    foreach($errorRows as $row)$recentErrors[]=[
        'profileId'=>(string)($row['profile_id']??''),
        'collector'=>(string)$row['collector'],
        'status'=>(string)$row['status'],
        'message'=>p50_obs_safe_error($row['error_message']??($row['status']==='running'?'Exécution restée en cours plus de 30 minutes':'')),
        'startedAt'=>p50_obs_age($row['started_at'])['at']??null,
        'finishedAt'=>p50_obs_age($row['finished_at'])['at']??null,
    ];

    $profiles=$pdo->query("SELECT profile_id FROM p50_profile_registry WHERE alive=1 ORDER BY profile_id LIMIT 5000")->fetchAll();
    $profileIds=array_map(static fn(array $row): string=>(string)$row['profile_id'],$profiles);
    $linkRows=$pdo->prepare("SELECT profile_id,platform,confidence FROM p50_social_links WHERE status='verified' AND confidence>=?");
    $linkRows->execute([$threshold]);$linksByProfile=[];$knownPlatforms=[];
    foreach($linkRows->fetchAll() as $row){
        $linksByProfile[(string)$row['profile_id']][]=(int)$row['confidence'];
        $knownPlatforms[(string)$row['platform']]=true;
    }

    $eventStmt=$pdo->prepare(
        "SELECT profile_id,platform,url_hash,published_at,collected_at,metrics,confidence
        FROM p50_activity_events
        WHERE status='verified' AND confidence>=?
        ORDER BY collected_at DESC LIMIT 10000"
    );
    $eventStmt->execute([$threshold]);$events=$eventStmt->fetchAll();
    $eventsByProfile=[];$platforms=[];
    foreach($events as $event){
        $profileId=(string)$event['profile_id'];$platform=(string)$event['platform'];
        $eventsByProfile[$profileId][]=$event;
        if(!isset($platforms[$platform]))$platforms[$platform]=[
            'platform'=>$platform,'uniqueEvents'=>0,'metricCaptures'=>0,'usableMetrics'=>0,
            'activeMetrics'=>0,'coveredProfiles'=>[],'lastCollectedAt'=>null,
        ];
        $platforms[$platform]['uniqueEvents']++;
        $count=p50_obs_metric_count(decode_json_column($event['metrics']??null,[]));
        $platforms[$platform]['usableMetrics']+=$count;
        $eventTime=strtotime((string)($event['published_at']?:$event['collected_at']))?:0;
        if($count>0&&$eventTime>=time()-168*3600)$platforms[$platform]['activeMetrics']+=$count;
        if($count>0)$platforms[$platform]['coveredProfiles'][$profileId]=true;
        $collectedAt=(string)$event['collected_at'];
        if($platforms[$platform]['lastCollectedAt']===null||$collectedAt>$platforms[$platform]['lastCollectedAt'])$platforms[$platform]['lastCollectedAt']=$collectedAt;
    }

    $captureRows=$pdo->query(
        "SELECT platform,COUNT(*) captures,SUM(usable_metric_count) usable_metrics,
        COUNT(DISTINCT profile_id) profiles,MAX(captured_at) last_capture
        FROM p50_activity_metric_history GROUP BY platform ORDER BY captures DESC LIMIT 50"
    )->fetchAll();
    foreach($captureRows as $row){
        $platform=(string)$row['platform'];
        if(!isset($platforms[$platform]))$platforms[$platform]=[
            'platform'=>$platform,'uniqueEvents'=>0,'metricCaptures'=>0,'usableMetrics'=>0,
            'activeMetrics'=>0,'coveredProfiles'=>[],'lastCollectedAt'=>null,
        ];
        $platforms[$platform]['metricCaptures']=(int)$row['captures'];
        $platforms[$platform]['historyUsableMetrics']=(int)$row['usable_metrics'];
        $platforms[$platform]['historyCoveredProfiles']=(int)$row['profiles'];
        $platforms[$platform]['lastMetricCaptureAt']=p50_obs_age($row['last_capture'])['at']??null;
    }
    foreach(array_keys($knownPlatforms) as $platform){
        if(!isset($platforms[$platform]))$platforms[$platform]=[
            'platform'=>$platform,'uniqueEvents'=>0,'metricCaptures'=>0,'usableMetrics'=>0,
            'activeMetrics'=>0,'coveredProfiles'=>[],'lastCollectedAt'=>null,
        ];
    }
    foreach($platforms as &$row){
        $row['coveredProfiles']=max(count($row['coveredProfiles']),(int)($row['historyCoveredProfiles']??0));
        unset($row['historyCoveredProfiles']);
        $row['lastCollectedAt']=$row['lastCollectedAt']?(p50_obs_age($row['lastCollectedAt'])['at']??null):null;
        $row['noData']=$row['usableMetrics']===0&&$row['metricCaptures']===0;
    }
    unset($row);
    ksort($platforms);

    $classification=['totalProfiles'=>count($profileIds),'measurableProfiles'=>0,'classableProfiles'=>0,'nonClassableProfiles'=>0];
    $reasonCounts=['insufficientConfidence'=>0,'insufficientCoverage'=>0,'fewerThanSixCriteria'=>0,'noRecentMetrics'=>0];
    foreach($profileIds as $profileId){
        $health=p50_obs_profile_health($eventsByProfile[$profileId]??[],$linksByProfile[$profileId]??[],$threshold);
        if($health['measurable'])$classification['measurableProfiles']++;
        if($health['classable'])$classification['classableProfiles']++;
        else{
            $classification['nonClassableProfiles']++;
            foreach($health['reasons'] as $reason)$reasonCounts[$reason]++;
        }
    }
    $classification['nonClassableReasons']=$reasonCounts;

    $buckets=['under2Hours'=>0,'from2To24Hours'=>0,'from24To48Hours'=>0,'from2To7Days'=>0,'over7Days'=>0];
    $bucketRows=$pdo->query(
        "SELECT TIMESTAMPDIFF(MINUTE,MAX(captured_at),NOW()) age_minutes
        FROM p50_activity_metric_history
        GROUP BY profile_id,platform,url_hash
        ORDER BY MAX(captured_at) DESC LIMIT 10000"
    )->fetchAll();
    foreach($bucketRows as $row){
        $minutes=max(0,(int)$row['age_minutes']);
        if($minutes<120)$buckets['under2Hours']++;
        elseif($minutes<1440)$buckets['from2To24Hours']++;
        elseif($minutes<2880)$buckets['from24To48Hours']++;
        elseif($minutes<10080)$buckets['from2To7Days']++;
        else $buckets['over7Days']++;
    }

    $pipeline=p50_obs_pipeline_state($pdo);
    $canonical=p50_metrics_schema_status($pdo);
    $freshness['pipeline_publication']=$pipeline['age'];
    $lastCron=p50_obs_scalar($pdo,"SELECT MAX(started_at) FROM p50_collection_runs WHERE collector LIKE 'cron_%'");
    $browserDependent=true;
    $automation=[
        'browserDependent'=>$browserDependent,
        'summary'=>'Le classement dépend encore du bouton MAJ PASS50 et du maintien de la page ouverte : aucun workflow métriques planifié n’est présent dans le dépôt.',
        'lastObservedCronRun'=>p50_obs_age($lastCron!==false&&$lastCron!==null?(string)$lastCron:null),
        'known'=>[
            ['name'=>'PASS50 Live Radar Sweep','schedule'=>'*/10 * * * *','scope'=>'live uniquement','publishesScores'=>false],
            ['name'=>'data-cron.php','schedule'=>null,'scope'=>'collecte historique et publication par profil','publishesScores'=>true],
            ['name'=>'metrics-cron.php','schedule'=>null,'scope'=>'YouTube/X expérimental','publishesScores'=>true],
            ['name'=>'Bouton MAJ PASS50','schedule'=>null,'scope'=>'collecte complète pilotée par navigateur','publishesScores'=>true],
        ],
    ];

    $platformList=array_values($platforms);
    $platformsWithoutData=array_values(array_map(
        static fn(array $row): string=>$row['platform'],
        array_filter($platformList,static fn(array $row): bool=>$row['noData'])
    ));
    $activeMetrics=array_sum(array_column($platformList,'activeMetrics'));
    $status=$classification['classableProfiles']===0||$activeMetrics===0?'blocked':
        (($browserDependent||$platformsWithoutData||$classification['classableProfiles']<$classification['totalProfiles'])?'incomplete':'operational');
    $staticReasons=[];
    if($browserDependent)$staticReasons[]='Aucune collecte métrique générale n’est planifiée sans navigateur.';
    if($classification['classableProfiles']===0)$staticReasons[]='Aucun profil ne satisfait actuellement les seuils de classement automatique.';
    if($activeMetrics===0)$staticReasons[]='Aucune métrique récente active n’alimente le moteur.';
    if($reasonCounts['insufficientCoverage']>0)$staticReasons[]='La couverture des critères est insuffisante pour '.$reasonCounts['insufficientCoverage'].' profil(s).';
    if($platformsWithoutData)$staticReasons[]='Plateformes sans donnée exploitable : '.implode(', ',$platformsWithoutData).'.';

    return [
        'ok'=>true,'readOnly'=>true,'generatedAt'=>$generatedAt,'status'=>$status,
        'threshold'=>$threshold,'volumes'=>$volumes,'freshness'=>$freshness,
        'collections'=>['summary'=>$runStats,'collectors'=>$collectors,'recentErrors'=>$recentErrors],
        'platforms'=>$platformList,'platformsWithoutData'=>$platformsWithoutData,
        'freshnessWindows'=>$buckets,
        'ranking'=>$classification+[
            'activeMetrics'=>$activeMetrics,
            'scoresChanged'=>$pipeline['scoresChanged'],
            'ranksChanged'=>$pipeline['ranksChanged'],
            'recalculatedProfiles'=>$pipeline['recalculatedProfiles'],
            'lastAtomicPublicationAt'=>$pipeline['publishedAt'],
            'lastAtomicPublicationAge'=>$pipeline['age'],
        ],
        'automation'=>$automation,'canonicalSchema'=>[
            'schemaVersion'=>$canonical['schemaVersion'],'migrationStatus'=>$canonical['migrationStatus'],
            'accounts'=>$canonical['volumes']['p50_metric_accounts']??null,
            'contents'=>$canonical['volumes']['p50_metric_contents']??null,
            'captures'=>$canonical['volumes']['p50_metric_captures']??null,
            'jobs'=>$canonical['volumes']['p50_metric_jobs']??null,
            'runs'=>$canonical['volumes']['p50_metric_runs']??null,
            'quarantinedCaptures'=>($canonical['tables']['p50_metric_captures']??false)?(int)p50_obs_scalar($pdo,"SELECT COUNT(*) FROM p50_metric_captures WHERE quality_status='quarantined'"):null,
            'lastBackfillAt'=>$canonical['lastBackfillAt'],'tables'=>$canonical['tables'],
        ],'staticRankingReasons'=>$staticReasons,
        'limits'=>['eventRows'=>10000,'captureSeries'=>10000,'collectors'=>30,'recentErrors'=>20],
    ];
}
