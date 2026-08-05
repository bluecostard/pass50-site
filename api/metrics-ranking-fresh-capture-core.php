<?php
declare(strict_types=1);

require_once __DIR__.'/metrics-ranking-core.php';

const P50_MR_FRESH_CAPTURE_GATE_VERSION='MR-FRESH-CAPTURE-V1.0';

function p50_mr_latest_usable_capture_after(PDO $pdo,DateTimeImmutable $after,DateTimeImmutable $now): ?DateTimeImmutable {
    if(!p50_metrics_table_exists($pdo,'p50_metric_captures'))return null;
    $stmt=$pdo->prepare("SELECT MAX(observed_at) FROM p50_metric_captures
        WHERE quality_status='usable' AND confidence>=70 AND profile_id<>''
          AND observed_at>? AND observed_at<=?");
    $stmt->execute([$after->format('Y-m-d H:i:s'),$now->format('Y-m-d H:i:s')]);
    $value=$stmt->fetchColumn();
    if(!is_string($value)||trim($value)==='')return null;
    return new DateTimeImmutable($value,new DateTimeZone('UTC'));
}

function p50_mr_calculate_if_due_with_fresh_captures(PDO $pdo,DateTimeImmutable $now,int $minimumMinutes,string $dispatchId): array {
    $minimumMinutes=max(60,min(240,$minimumMinutes));
    p50_mr_ensure_schema($pdo);
    $stmt=$pdo->prepare("SELECT finished_at FROM p50_metric_ranking_runs
        WHERE algorithm_version=? AND status='success' AND finished_at IS NOT NULL
        ORDER BY finished_at DESC,id DESC LIMIT 1");
    $stmt->execute([P50_MR_ALGORITHM_VERSION]);
    $latest=$stmt->fetchColumn();
    if(!$latest)return p50_mr_calculate_if_due($pdo,$now,$minimumMinutes,$dispatchId);

    $finishedAt=new DateTimeImmutable((string)$latest,new DateTimeZone('UTC'));
    if($finishedAt<=$now->modify("-$minimumMinutes minutes")){
        return p50_mr_calculate_if_due($pdo,$now,$minimumMinutes,$dispatchId);
    }

    $latestCapture=p50_mr_latest_usable_capture_after($pdo,$finishedAt,$now);
    if($latestCapture===null)return [
        'ok'=>true,'skipped'=>true,'reason'=>'recent_success',
        'latestFinishedAt'=>$finishedAt->format(DATE_ATOM),
        'algorithmVersion'=>P50_MR_ALGORITHM_VERSION,
        'freshCaptureGateVersion'=>P50_MR_FRESH_CAPTURE_GATE_VERSION,
    ];

    $result=p50_mr_calculate($pdo,array_keys(p50_mr_periods()),'cron_2h',[
        'scheduled'=>true,'cadence'=>'2h','dispatchId'=>$dispatchId,
    ]);
    return array_merge([
        'skipped'=>false,
        'freshCaptureOverride'=>true,
        'freshCaptureGateVersion'=>P50_MR_FRESH_CAPTURE_GATE_VERSION,
        'latestPreviousFinishedAt'=>$finishedAt->format(DATE_ATOM),
        'latestUsableCaptureAt'=>$latestCapture->format(DATE_ATOM),
    ],$result);
}
