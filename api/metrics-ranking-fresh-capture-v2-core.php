<?php
declare(strict_types=1);

require_once __DIR__.'/metrics-ranking-core.php';

const P50_MR_FRESH_CAPTURE_GATE_V2_VERSION='MR-FRESH-CAPTURE-V2.0';

function p50_mr_v2_latest_usable_capture_recorded_after(PDO $pdo,DateTimeImmutable $after,DateTimeImmutable $now): ?DateTimeImmutable {
    if(!p50_metrics_table_exists($pdo,'p50_metric_captures'))return null;
    $stmt=$pdo->prepare("SELECT MAX(captured_at) FROM p50_metric_captures
        WHERE quality_status='usable' AND confidence>=70
          AND captured_at>? AND captured_at<=?");
    $stmt->execute([$after->format('Y-m-d H:i:s'),$now->format('Y-m-d H:i:s')]);
    $value=$stmt->fetchColumn();
    if(!is_string($value)||trim($value)==='')return null;
    return new DateTimeImmutable($value,new DateTimeZone('UTC'));
}

function p50_mr_v2_calculate_if_due(PDO $pdo,DateTimeImmutable $now,int $minimumMinutes,string $dispatchId): array {
    $minimumMinutes=max(60,min(240,$minimumMinutes));
    p50_mr_ensure_schema($pdo);
    $stmt=$pdo->prepare("SELECT finished_at FROM p50_metric_ranking_runs
        WHERE algorithm_version=? AND status='success' AND finished_at IS NOT NULL
        ORDER BY finished_at DESC,id DESC LIMIT 1");
    $stmt->execute([P50_MR_ALGORITHM_VERSION]);
    $latest=$stmt->fetchColumn();
    if(!$latest){
        $result=p50_mr_calculate_if_due($pdo,$now,$minimumMinutes,$dispatchId);
        return array_merge(['freshCaptureGateVersion'=>P50_MR_FRESH_CAPTURE_GATE_V2_VERSION],$result);
    }

    $finishedAt=new DateTimeImmutable((string)$latest,new DateTimeZone('UTC'));
    if($finishedAt<=$now->modify("-$minimumMinutes minutes")){
        $result=p50_mr_calculate_if_due($pdo,$now,$minimumMinutes,$dispatchId);
        return array_merge(['freshCaptureGateVersion'=>P50_MR_FRESH_CAPTURE_GATE_V2_VERSION],$result);
    }

    $latestRecordedAt=p50_mr_v2_latest_usable_capture_recorded_after($pdo,$finishedAt,$now);
    if($latestRecordedAt===null)return [
        'ok'=>true,'skipped'=>true,'reason'=>'recent_success',
        'latestFinishedAt'=>$finishedAt->format(DATE_ATOM),
        'algorithmVersion'=>P50_MR_ALGORITHM_VERSION,
        'freshCaptureGateVersion'=>P50_MR_FRESH_CAPTURE_GATE_V2_VERSION,
    ];

    $result=p50_mr_calculate($pdo,array_keys(p50_mr_periods()),'cron_2h',[
        'scheduled'=>true,'cadence'=>'2h','dispatchId'=>$dispatchId,
    ]);
    return array_merge([
        'skipped'=>false,
        'freshCaptureOverride'=>true,
        'freshCaptureGateVersion'=>P50_MR_FRESH_CAPTURE_GATE_V2_VERSION,
        'latestPreviousFinishedAt'=>$finishedAt->format(DATE_ATOM),
        'latestUsableCaptureRecordedAt'=>$latestRecordedAt->format(DATE_ATOM),
    ],$result);
}

/** Recalcul forcé (admin / recovery watchdog) — ignore recent_success et readiness P1. */
function p50_mr_v2_force_calculate(PDO $pdo,string $dispatchId): array {
    p50_mr_ensure_schema($pdo);
    $result=p50_mr_calculate($pdo,array_keys(p50_mr_periods()),'cron_2h',[
        'scheduled'=>true,'cadence'=>'2h','dispatchId'=>$dispatchId,'forced'=>true,
    ]);
    return array_merge([
        'skipped'=>false,
        'forced'=>true,
        'freshCaptureGateVersion'=>P50_MR_FRESH_CAPTURE_GATE_V2_VERSION,
    ],$result);
}
