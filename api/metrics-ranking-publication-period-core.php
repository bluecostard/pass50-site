<?php
declare(strict_types=1);

require_once __DIR__.'/metrics-ranking-publication-core.php';

const P50_MRPA_PERIOD_SELECTION_VERSION='PUBSIM-PERIOD-V1.0';
const P50_MRPA_EXIT_DIAGNOSTICS_VERSION='PUBSIM-EXIT-DIAG-V1.0';

function p50_mrpa_period_priority(): array {
    return ['2H','24H','48H','7J','15J'];
}

function p50_mrpa_latest_successful_run(PDO $pdo): ?array {
    if(!p50_metrics_table_exists($pdo,'p50_metric_ranking_runs'))return null;
    $stmt=$pdo->prepare("SELECT run_uuid,periods_json,finished_at
      FROM p50_metric_ranking_runs
      WHERE algorithm_version=? AND status='success' AND finished_at IS NOT NULL
      ORDER BY finished_at DESC,id DESC LIMIT 1");
    $stmt->execute([P50_MR_ALGORITHM_VERSION]);
    $row=$stmt->fetch();
    if(!$row)return null;
    $periods=json_decode((string)$row['periods_json'],true);
    $periods=is_array($periods)?array_values(array_intersect(p50_mrpa_period_priority(),array_map('strval',$periods))):[];
    return ['runUuid'=>(string)$row['run_uuid'],'periods'=>$periods,'finishedAt'=>(string)$row['finished_at']];
}

function p50_mrpa_period_availability(PDO $pdo,?string $runUuid=null): array {
    $latest=p50_mrpa_latest_successful_run($pdo);$runUuid=trim((string)($runUuid??($latest['runUuid']??'')));$availability=[];
    foreach(p50_mrpa_period_priority() as $period)$availability[$period]=['period'=>$period,'runUuid'=>$runUuid?:null,'totalRows'=>0,'classableRows'=>0,'candidateRows'=>0,'distinctRuns'=>0,'available'=>false,'exclusionSummary'=>[],'averageCoverage'=>0.0,'averageConfidence'=>0.0];
    if($runUuid===''||!p50_metrics_table_exists($pdo,'p50_metric_ranking_current'))return $availability;
    $stmt=$pdo->prepare("SELECT period_key,COUNT(*) total_rows,
        SUM(CASE WHEN classable=1 THEN 1 ELSE 0 END) classable_rows,
        SUM(CASE WHEN classable=1 AND rank_position IS NOT NULL AND score IS NOT NULL THEN 1 ELSE 0 END) candidate_rows,
        COUNT(DISTINCT run_uuid) distinct_runs
      FROM p50_metric_ranking_current WHERE algorithm_version=? AND run_uuid=? GROUP BY period_key");
    $stmt->execute([P50_MR_ALGORITHM_VERSION,$runUuid]);
    foreach($stmt->fetchAll() as $row){$period=(string)$row['period_key'];if(!array_key_exists($period,$availability))continue;$candidateRows=(int)$row['candidate_rows'];
        $availability[$period]=['period'=>$period,'runUuid'=>$runUuid,'totalRows'=>(int)$row['total_rows'],'classableRows'=>(int)$row['classable_rows'],'candidateRows'=>$candidateRows,'distinctRuns'=>(int)$row['distinct_runs'],'available'=>$candidateRows>0,'exclusionSummary'=>[],'averageCoverage'=>0.0,'averageConfidence'=>0.0];}
    if(p50_metrics_table_exists($pdo,'p50_metric_ranking_period_runs')){
        $summary=$pdo->prepare("SELECT period_key,average_coverage,average_confidence,exclusion_summary_json FROM p50_metric_ranking_period_runs WHERE algorithm_version=? AND run_uuid=?");
        $summary->execute([P50_MR_ALGORITHM_VERSION,$runUuid]);
        foreach($summary->fetchAll() as $row){$period=(string)$row['period_key'];if(!isset($availability[$period]))continue;$availability[$period]['averageCoverage']=(float)$row['average_coverage'];$availability[$period]['averageConfidence']=(float)$row['average_confidence'];$availability[$period]['exclusionSummary']=json_decode((string)$row['exclusion_summary_json'],true)?:[];}
    }
    return $availability;
}

function p50_mrpa_select_period(PDO $pdo,string $requestedPeriod='2H'): array {
    $requestedPeriod=strtoupper(trim($requestedPeriod));if($requestedPeriod!=='AUTO'&&!array_key_exists($requestedPeriod,p50_mr_periods()))$requestedPeriod='2H';
    $latest=p50_mrpa_latest_successful_run($pdo);$availability=p50_mrpa_period_availability($pdo,$latest['runUuid']??null);$covered=$latest['periods']??[];$priority=p50_mrpa_period_priority();
    $candidates=$requestedPeriod==='AUTO'?$priority:array_values(array_unique([$requestedPeriod,...$priority]));
    foreach($candidates as $period){
        if($covered&&!in_array($period,$covered,true))continue;if((int)($availability[$period]['candidateRows']??0)<=0)continue;
        $reason=$requestedPeriod==='AUTO'?'auto_first_classable_period':($period===$requestedPeriod?'requested_period_classable':'requested_period_empty_fallback');
        return ['version'=>P50_MRPA_PERIOD_SELECTION_VERSION,'requestedPeriod'=>$requestedPeriod,'selectedPeriod'=>$period,'reason'=>$reason,'fallbackUsed'=>$requestedPeriod!=='AUTO'&&$period!==$requestedPeriod,'latestRun'=>$latest,'availability'=>$availability];
    }
    $selected=$requestedPeriod!=='AUTO'?$requestedPeriod:($covered[0]??'2H');if(!array_key_exists($selected,p50_mr_periods()))$selected='2H';
    return ['version'=>P50_MRPA_PERIOD_SELECTION_VERSION,'requestedPeriod'=>$requestedPeriod,'selectedPeriod'=>$selected,'reason'=>$latest===null?'no_successful_run':'no_classable_period_available','fallbackUsed'=>false,'latestRun'=>$latest,'availability'=>$availability];
}

function p50_mrpa_exit_diagnostics(array $result,array $experimentalRows,int $limit=100): array {
    $limit=max(1,min(200,$limit));$byId=[];
    foreach($experimentalRows as $row){$id=trim((string)($row['profileId']??''));if($id!=='')$byId[$id]=$row;}
    $rows=[];$reasons=[];
    foreach((array)($result['movements']??[]) as $movement){
        if(($movement['type']??'')!=='exit')continue;$id=(string)($movement['profileId']??'');$source=$byId[$id]??null;
        $why=[];
        if(!$source)$why=['missing_experimental_row'];
        else{
            $why=array_values(array_filter(array_map('strval',(array)($source['exclusionReasons']??[]))));
            if(!$why&&empty($source['classable']))$why=['non_classable_without_reason'];
            if(!$why&&($source['rank']??null)===null)$why=['rank_missing'];
            if(!$why&&($source['score']??null)===null)$why=['score_missing'];
            if(!$why)$why=['candidate_filter_mismatch'];
        }
        foreach($why as $reason)$reasons[$reason]=($reasons[$reason]??0)+1;
        $rows[]=['profileId'=>$id,'name'=>(string)($movement['name']??($source['name']??'')),'publicRank'=>$movement['publicRank']??null,'classable'=>$source['classable']??false,'rank'=>$source['rank']??null,'score'=>$source['score']??null,'confidence'=>$source['confidence']??null,'coverage'=>$source['coverage']??null,'reasons'=>$why];
        if(count($rows)>=$limit)break;
    }
    arsort($reasons);
    return ['version'=>P50_MRPA_EXIT_DIAGNOSTICS_VERSION,'exitCount'=>(int)($result['summary']['counts']['exits']??count($rows)),'reportedCount'=>count($rows),'reasonSummary'=>$reasons,'profiles'=>$rows];
}

function p50_mrpa_simulate(PDO $pdo,string $requestedPeriod='2H',int $limit=200,?DateTimeImmutable $now=null): array {
    $selection=p50_mrpa_select_period($pdo,$requestedPeriod);$selectedPeriod=(string)$selection['selectedPeriod'];
    $result=p50_mrp_simulate($pdo,$selectedPeriod,$limit,$now);
    $result['requestedPeriod']=$selection['requestedPeriod'];
    $result['periodSelection']=['version'=>$selection['version'],'reason'=>$selection['reason'],'fallbackUsed'=>$selection['fallbackUsed'],'latestRunUuid'=>$selection['latestRun']['runUuid']??null];
    $result['periodAvailability']=$selection['availability'];
    $result['exitDiagnostics']=p50_mrpa_exit_diagnostics($result,p50_mrp_experimental_rows($pdo,$selectedPeriod),$limit);
    return $result;
}
