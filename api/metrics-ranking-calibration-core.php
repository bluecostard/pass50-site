<?php
declare(strict_types=1);

require_once __DIR__.'/metrics-ranking-core.php';

const P50_MR_CALIBRATION_VERSION='CAL-V1.0';

function p50_mrc_average(array $values): ?float {
    $numeric=array_values(array_filter($values,static fn($value)=>is_int($value)||is_float($value)||is_numeric($value)));
    return $numeric?array_sum(array_map('floatval',$numeric))/count($numeric):null;
}

function p50_mrc_top(array $snapshots,int $limit): array {
    $top=[];foreach($snapshots as $row)if((int)$row['rank_position']<=$limit)$top[(string)$row['profile_id']]=$row;
    return $top;
}

function p50_mrc_transition(array $previous,array $current): array {
    if(!$previous)return [
        'top10Retention'=>null,'top50Retention'=>null,'medianAbsoluteRankMovement'=>null,
        'top50Entries'=>null,'top50Exits'=>null,'medianScoreChange'=>null,
    ];
    $previous10=p50_mrc_top($previous,10);$current10=p50_mrc_top($current,10);
    $previous50=p50_mrc_top($previous,50);$current50=p50_mrc_top($current,50);
    $retention=static function(array $before,array $after): ?float {
        if(!$before)return null;return count(array_intersect_key($before,$after))/count($before)*100;
    };
    $beforeById=[];foreach($previous as $row)$beforeById[(string)$row['profile_id']]=$row;
    $rankMovements=[];$scoreChanges=[];
    foreach($current as $row){
        $profileId=(string)$row['profile_id'];if(!isset($beforeById[$profileId]))continue;
        $rankMovements[]=abs((int)$row['rank_position']-(int)$beforeById[$profileId]['rank_position']);
        $scoreChanges[]=abs((float)$row['score']-(float)$beforeById[$profileId]['score']);
    }
    return [
        'top10Retention'=>$retention($previous10,$current10),
        'top50Retention'=>$retention($previous50,$current50),
        'medianAbsoluteRankMovement'=>p50_mr_median($rankMovements),
        'top50Entries'=>count(array_diff_key($current50,$previous50)),
        'top50Exits'=>count(array_diff_key($previous50,$current50)),
        'medianScoreChange'=>p50_mr_median($scoreChanges),
    ];
}

function p50_mrc_threshold_simulation(array $currentRows): array {
    $coverageThresholds=[35,40,45,50,55,60];$confidenceThresholds=[45,50,55,60,65,70];
    $baseline=count(array_filter($currentRows,static fn($row)=>(bool)$row['classable']));
    $total=count($currentRows);$thresholdReasons=['coverage_below_45'=>true,'confidence_below_55'=>true];$cells=[];
    foreach($coverageThresholds as $coverageThreshold)foreach($confidenceThresholds as $confidenceThreshold){
        $classable=0;
        foreach($currentRows as $row){
            if($row['score']===null)continue;
            $reasons=json_decode((string)$row['exclusion_reasons_json'],true)?:[];
            $hard=array_filter($reasons,static fn($reason)=>!isset($thresholdReasons[(string)$reason]));
            if($hard)continue;
            if((float)$row['coverage']>=$coverageThreshold&&(float)$row['confidence']>=$confidenceThreshold)$classable++;
        }
        $cells[]=[
            'coverageThreshold'=>$coverageThreshold,'confidenceThreshold'=>$confidenceThreshold,
            'simulatedClassableCount'=>$classable,'differenceFromBaseline'=>$classable-$baseline,
            'simulatedClassableRatio'=>$total?($classable/$total*100):0.0,
        ];
    }
    return [
        'coverageThresholds'=>$coverageThresholds,'confidenceThresholds'=>$confidenceThresholds,'cells'=>$cells,
        'baseline'=>['coverageThreshold'=>45,'confidenceThreshold'=>55,'classableCount'=>$baseline,'totalProfiles'=>$total],
    ];
}

function p50_mrc_read(PDO $pdo,string $period,int $runLimit=24): array {
    $periods=p50_mr_periods();if(!isset($periods[$period]))$period='2H';$runLimit=max(6,min(100,$runLimit));
    $empty=[
        'calibrationVersion'=>P50_MR_CALIBRATION_VERSION,'algorithmVersion'=>P50_MR_ALGORITHM_VERSION,
        'selectedPeriod'=>$period,'generatedAt'=>gmdate(DATE_ATOM),
        'historyStatus'=>['successfulCycles'=>0,'exactCycles'=>0,'minimumExactCycles'=>24,'firstCycleAt'=>null,'lastCycleAt'=>null,'state'=>'collecting'],
        'aggregate'=>['transitionCount'=>0,'stability'=>'insufficient_history','averageTop10Retention'=>null,'averageTop50Retention'=>null,'medianAbsoluteRankMovement'=>null,'medianScoreChange'=>null,'averageClassableCount'=>null],
        'runs'=>[],'thresholdSimulation'=>p50_mrc_threshold_simulation([]),
        'limitations'=>['historicalSnapshotsTop100'=>true,'serverPublicRankingAccess'=>false,'automaticChanges'=>false],
    ];
    if(!p50_metrics_table_exists($pdo,'p50_metric_ranking_runs'))return $empty;

    $stmt=$pdo->prepare("SELECT run_uuid,trigger_type,periods_json,started_at,finished_at
        FROM p50_metric_ranking_runs WHERE algorithm_version=? AND status='success' AND finished_at IS NOT NULL
        ORDER BY finished_at DESC,id DESC LIMIT 200");
    $stmt->execute([P50_MR_ALGORITHM_VERSION]);$matching=[];
    foreach($stmt->fetchAll() as $run){
        $runPeriods=json_decode((string)$run['periods_json'],true)?:[];
        if(in_array($period,$runPeriods,true))$matching[]=$run;
    }
    $allRunIds=array_column($matching,'run_uuid');$summaries=[];
    if($allRunIds&&p50_metrics_table_exists($pdo,'p50_metric_ranking_period_runs')){
        $placeholders=implode(',',array_fill(0,count($allRunIds),'?'));
        $summaryStmt=$pdo->prepare("SELECT * FROM p50_metric_ranking_period_runs
            WHERE algorithm_version=? AND period_key=? AND run_uuid IN ($placeholders)");
        $summaryStmt->execute([P50_MR_ALGORITHM_VERSION,$period,...$allRunIds]);
        foreach($summaryStmt->fetchAll() as $row)$summaries[(string)$row['run_uuid']]=$row;
    }
    $retained=array_slice($matching,0,$runLimit);$retainedIds=array_column($retained,'run_uuid');$snapshots=[];
    if($retainedIds&&p50_metrics_table_exists($pdo,'p50_metric_ranking_snapshots')){
        $placeholders=implode(',',array_fill(0,count($retainedIds),'?'));
        $snapshotStmt=$pdo->prepare("SELECT run_uuid,profile_id,rank_position,score,confidence,coverage
            FROM p50_metric_ranking_snapshots WHERE algorithm_version=? AND period_key=? AND run_uuid IN ($placeholders)
            ORDER BY captured_at,rank_position,profile_id");
        $snapshotStmt->execute([P50_MR_ALGORITHM_VERSION,$period,...$retainedIds]);
        foreach($snapshotStmt->fetchAll() as $row)$snapshots[(string)$row['run_uuid']][]=$row;
    }
    $currentRows=[];
    if(p50_metrics_table_exists($pdo,'p50_metric_ranking_current')){
        $currentStmt=$pdo->prepare("SELECT profile_id,score,confidence,coverage,classable,exclusion_reasons_json
            FROM p50_metric_ranking_current WHERE algorithm_version=? AND period_key=? ORDER BY profile_id");
        $currentStmt->execute([P50_MR_ALGORITHM_VERSION,$period]);$currentRows=$currentStmt->fetchAll();
    }

    $chronological=array_reverse($retained);$runs=[];$previousSnapshots=[];
    foreach($chronological as $run){
        $runUuid=(string)$run['run_uuid'];$cycleSnapshots=$snapshots[$runUuid]??[];$summary=$summaries[$runUuid]??null;
        $snapshotScores=array_column($cycleSnapshots,'score');$snapshotConfidence=array_column($cycleSnapshots,'confidence');$snapshotCoverage=array_column($cycleSnapshots,'coverage');
        $snapshotCount=count($cycleSnapshots);$exact=$summary!==null;$capped=!$exact&&$snapshotCount>=100;
        $transition=p50_mrc_transition($previousSnapshots,$cycleSnapshots);
        $runs[]=[
            'runUuid'=>$runUuid,'triggerType'=>(string)$run['trigger_type'],'startedAt'=>$run['started_at'],'finishedAt'=>$run['finished_at'],
            'profilesConsidered'=>$exact?(int)$summary['profiles_considered']:null,
            'classableCount'=>$exact?(int)$summary['classable_count']:$snapshotCount,
            'classableCountCapped'=>$capped,'excludedCount'=>$exact?(int)$summary['excluded_count']:null,
            'averageScore'=>$exact&&$summary['average_score']!==null?(float)$summary['average_score']:p50_mrc_average($snapshotScores),
            'medianScore'=>$exact&&$summary['median_score']!==null?(float)$summary['median_score']:p50_mr_median($snapshotScores),
            'topScore'=>$exact&&$summary['top_score']!==null?(float)$summary['top_score']:($snapshotScores?max(array_map('floatval',$snapshotScores)):null),
            'averageConfidence'=>$exact?(float)$summary['average_confidence']:p50_mrc_average($snapshotConfidence),
            'averageCoverage'=>$exact?(float)$summary['average_coverage']:p50_mrc_average($snapshotCoverage),
            'thresholdExcludedCount'=>$exact?(int)$summary['threshold_excluded_count']:null,
            'hardExcludedCount'=>$exact?(int)$summary['hard_excluded_count']:null,
            'otherExcludedCount'=>$exact?(int)$summary['other_excluded_count']:null,
            'snapshotCount'=>$snapshotCount,'top10Retention'=>$transition['top10Retention'],'top50Retention'=>$transition['top50Retention'],
            'medianAbsoluteRankMovement'=>$transition['medianAbsoluteRankMovement'],'top50Entries'=>$transition['top50Entries'],
            'top50Exits'=>$transition['top50Exits'],'medianScoreChange'=>$transition['medianScoreChange'],'summaryExact'=>$exact,
        ];
        $previousSnapshots=$cycleSnapshots;
    }
    $transitions=array_values(array_filter($runs,static fn($run)=>$run['top50Retention']!==null));
    $averageTop10=p50_mrc_average(array_column($transitions,'top10Retention'));
    $averageTop50=p50_mrc_average(array_column($transitions,'top50Retention'));
    $medianMovement=p50_mr_median(array_column($transitions,'medianAbsoluteRankMovement'));
    $medianScoreChange=p50_mr_median(array_column($transitions,'medianScoreChange'));
    if(count($transitions)<6)$stability='insufficient_history';
    elseif($averageTop50!==null&&$averageTop50>=85&&$medianMovement!==null&&$medianMovement<=3)$stability='stable';
    elseif($averageTop50!==null&&$averageTop50>=70&&$medianMovement!==null&&$medianMovement<=8)$stability='moderate';
    else $stability='volatile';
    $exactCycles=count(array_intersect(array_keys($summaries),$allRunIds));$historyState=$exactCycles<6?'collecting':($exactCycles<24?'observing':'calibratable');
    $classableValues=array_values(array_filter(array_column($runs,'classableCount'),static fn($value)=>$value!==null));
    $oldestRun=$matching?$matching[count($matching)-1]:null;

    return [
        'calibrationVersion'=>P50_MR_CALIBRATION_VERSION,'algorithmVersion'=>P50_MR_ALGORITHM_VERSION,
        'selectedPeriod'=>$period,'generatedAt'=>gmdate(DATE_ATOM),
        'historyStatus'=>[
            'successfulCycles'=>count($matching),'exactCycles'=>$exactCycles,'minimumExactCycles'=>24,
            'firstCycleAt'=>$oldestRun['finished_at']??null,'lastCycleAt'=>$matching?$matching[0]['finished_at']:null,
            'state'=>$historyState,
        ],
        'aggregate'=>[
            'transitionCount'=>count($transitions),'stability'=>$stability,
            'averageTop10Retention'=>$averageTop10,'averageTop50Retention'=>$averageTop50,
            'medianAbsoluteRankMovement'=>$medianMovement,'medianScoreChange'=>$medianScoreChange,
            'averageClassableCount'=>p50_mrc_average($classableValues),
        ],
        'runs'=>array_reverse($runs),'thresholdSimulation'=>p50_mrc_threshold_simulation($currentRows),
        'limitations'=>['historicalSnapshotsTop100'=>true,'serverPublicRankingAccess'=>false,'automaticChanges'=>false],
    ];
}
