<?php
declare(strict_types=1);

require_once __DIR__.'/metrics-schema-core.php';

const P50_MR_READINESS_VERSION='1.0.0';
const P50_MR_READINESS_MAX_P1_AGE_MINUTES=180;
const P50_MR_READINESS_FUTURE_TOLERANCE_MINUTES=5;

function p50_mrr_date(?string $value): ?DateTimeImmutable {
    if($value===null||trim($value)==='')return null;
    try{return new DateTimeImmutable(trim($value),new DateTimeZone('UTC'));}catch(Throwable){return null;}
}

function p50_mrr_iso(?string $value): ?string {
    $date=p50_mrr_date($value);
    return $date?$date->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM):null;
}

function p50_mrr_readiness(PDO $pdo,DateTimeImmutable $now): array {
    $now=$now->setTimezone(new DateTimeZone('UTC'));
    $required=['p50_metric_runs','p50_metric_jobs','p50_metric_captures'];
    $missing=array_values(array_filter($required,static fn(string $table): bool=>!p50_metrics_table_exists($pdo,$table)));
    $base=[
        'version'=>P50_MR_READINESS_VERSION,'ready'=>false,'state'=>'blocked','reason'=>'schema_missing',
        'checkedAt'=>$now->format(DATE_ATOM),'missingTables'=>$missing,
        'p1'=>['runUuid'=>null,'startedAt'=>null,'finishedAt'=>null,'ageMinutes'=>null,'activeJobs'=>0,'failedJobs'=>0,'futureRunsIgnored'=>0],
        'latestUsableCaptureAt'=>null,'latestRankingFinishedAt'=>null,
    ];
    if($missing)return $base;

    $futureCutoff=$now->modify('+'.P50_MR_READINESS_FUTURE_TOLERANCE_MINUTES.' minutes')->format('Y-m-d H:i:s');
    $futureStmt=$pdo->prepare("SELECT COUNT(*) FROM p50_metric_runs
        WHERE collector='metrics_orchestrator_v1' AND trigger_type='dispatch_p1'
          AND status='success' AND finished_at IS NOT NULL AND finished_at>?");
    $futureStmt->execute([$futureCutoff]);
    $futureRunsIgnored=(int)$futureStmt->fetchColumn();
    $base['p1']['futureRunsIgnored']=$futureRunsIgnored;

    $stmt=$pdo->prepare("SELECT run_uuid,started_at,finished_at FROM p50_metric_runs
        WHERE collector='metrics_orchestrator_v1' AND trigger_type='dispatch_p1'
          AND status='success' AND finished_at IS NOT NULL AND finished_at<=?
        ORDER BY finished_at DESC,id DESC LIMIT 1");
    $stmt->execute([$futureCutoff]);$p1=$stmt->fetch();
    if(!is_array($p1))return array_replace($base,['reason'=>$futureRunsIgnored>0?'p1_future_timestamp':'p1_not_observed','missingTables'=>[]]);

    $finishedAt=p50_mrr_date((string)$p1['finished_at']);
    if(!$finishedAt)return array_replace($base,['reason'=>'p1_invalid_timestamp','missingTables'=>[]]);
    $ageMinutes=max(0,(int)floor(($now->getTimestamp()-$finishedAt->getTimestamp())/60));
    $p1State=[
        'runUuid'=>(string)$p1['run_uuid'],'startedAt'=>p50_mrr_iso((string)$p1['started_at']),
        'finishedAt'=>$finishedAt->format(DATE_ATOM),'ageMinutes'=>$ageMinutes,
        'activeJobs'=>0,'failedJobs'=>0,'futureRunsIgnored'=>$futureRunsIgnored,
    ];
    $base['p1']=$p1State;$base['missingTables']=[];
    if($ageMinutes>P50_MR_READINESS_MAX_P1_AGE_MINUTES)return array_replace($base,['reason'=>'p1_stale']);

    $active=$pdo->query("SELECT COUNT(*) FROM p50_metric_jobs WHERE priority=50 AND status IN ('pending','running','retry_wait')")->fetchColumn();
    $failedStmt=$pdo->prepare("SELECT COUNT(*) FROM p50_metric_jobs WHERE priority=50 AND status='failed' AND updated_at>=?");
    $failedStmt->execute([(string)$p1['started_at']]);
    $activeJobs=(int)$active;$failedJobs=(int)$failedStmt->fetchColumn();
    $base['p1']['activeJobs']=$activeJobs;$base['p1']['failedJobs']=$failedJobs;
    if($activeJobs>0)return array_replace($base,['state'=>'waiting','reason'=>'collection_pending']);

    $latestCapture=$pdo->query("SELECT MAX(captured_at) FROM p50_metric_captures WHERE quality_status='usable'")->fetchColumn();
    $latestCaptureDate=$latestCapture?p50_mrr_date((string)$latestCapture):null;
    $base['latestUsableCaptureAt']=$latestCaptureDate?$latestCaptureDate->format(DATE_ATOM):null;
    if(!$latestCaptureDate)return array_replace($base,['reason'=>'no_usable_captures']);

    $latestRanking=null;
    if(p50_metrics_table_exists($pdo,'p50_metric_ranking_runs')){
        $rankingStmt=$pdo->prepare("SELECT finished_at FROM p50_metric_ranking_runs
            WHERE algorithm_version=? AND status='success' AND finished_at IS NOT NULL
            ORDER BY finished_at DESC,id DESC LIMIT 1");
        $rankingStmt->execute([defined('P50_MR_ALGORITHM_VERSION')?P50_MR_ALGORITHM_VERSION:'MR-V1.0']);
        $latestRanking=$rankingStmt->fetchColumn()?:null;
    }
    $latestRankingDate=$latestRanking?p50_mrr_date((string)$latestRanking):null;
    $base['latestRankingFinishedAt']=$latestRankingDate?$latestRankingDate->format(DATE_ATOM):null;
    if($latestRankingDate&&$latestCaptureDate<=$latestRankingDate)return array_replace($base,['state'=>'idle','reason'=>'no_new_captures']);

    return array_replace($base,[
        'ready'=>true,'state'=>$failedJobs>0?'ready_degraded':'ready',
        'reason'=>$failedJobs>0?'ready_with_partial_failures':'ready',
    ]);
}
