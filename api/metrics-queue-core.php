<?php
declare(strict_types=1);

require_once __DIR__.'/metrics-orchestrator-core.php';

const P50_METRICS_QUEUE_VERSION='1.0.0';

function p50_moq_snapshot(PDO $pdo): array {
    $empty=[
        'version'=>P50_METRICS_QUEUE_VERSION,
        'remaining'=>0,
        'pending'=>0,
        'running'=>0,
        'retryWait'=>0,
        'p1Remaining'=>0,
        'p1Pending'=>0,
        'p1Running'=>0,
        'p1RetryWait'=>0,
        'p1NextAttemptAt'=>null,
        'p1WaitSeconds'=>0,
        'p1NextJob'=>null,
        'serverTime'=>gmdate(DATE_ATOM),
    ];
    if(!p50_metrics_table_exists($pdo,'p50_metric_jobs'))return $empty;

    $row=$pdo->query("SELECT
      SUM(status IN ('pending','running','retry_wait')) remaining,
      SUM(status='pending') pending_count,
      SUM(status='running') running_count,
      SUM(status='retry_wait') retry_wait_count,
      SUM(priority=50 AND status IN ('pending','running','retry_wait')) p1_remaining,
      SUM(priority=50 AND status='pending') p1_pending,
      SUM(priority=50 AND status='running') p1_running,
      SUM(priority=50 AND status='retry_wait') p1_retry_wait,
      MIN(CASE WHEN priority=50 AND status='retry_wait' THEN next_attempt_at END) p1_next_attempt_at
      FROM p50_metric_jobs")->fetch()?:[];

    $nextAt=trim((string)($row['p1_next_attempt_at']??''))?:null;
    $waitSeconds=0;
    if($nextAt!==null){
        $nextTimestamp=strtotime($nextAt.' UTC');
        if($nextTimestamp!==false)$waitSeconds=max(0,$nextTimestamp-time());
    }

    $nextStmt=$pdo->query("SELECT platform,scope_id,status,attempts,max_attempts,next_attempt_at,last_error
      FROM p50_metric_jobs
      WHERE priority=50 AND status IN ('pending','running','retry_wait')
      ORDER BY CASE status WHEN 'running' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END,
        COALESCE(next_attempt_at,scheduled_at),id LIMIT 1");
    $next=$nextStmt->fetch()?:null;
    if(is_array($next))$next=[
        'platform'=>(string)$next['platform'],
        'profileId'=>(string)$next['scope_id'],
        'status'=>(string)$next['status'],
        'attempts'=>(int)$next['attempts'],
        'maxAttempts'=>(int)$next['max_attempts'],
        'nextAttemptAt'=>$next['next_attempt_at']?:null,
        'message'=>p50_metrics_safe_error((string)($next['last_error']??''))?:null,
    ];

    return [
        'version'=>P50_METRICS_QUEUE_VERSION,
        'remaining'=>(int)($row['remaining']??0),
        'pending'=>(int)($row['pending_count']??0),
        'running'=>(int)($row['running_count']??0),
        'retryWait'=>(int)($row['retry_wait_count']??0),
        'p1Remaining'=>(int)($row['p1_remaining']??0),
        'p1Pending'=>(int)($row['p1_pending']??0),
        'p1Running'=>(int)($row['p1_running']??0),
        'p1RetryWait'=>(int)($row['p1_retry_wait']??0),
        'p1NextAttemptAt'=>$nextAt,
        'p1WaitSeconds'=>$waitSeconds,
        'p1NextJob'=>$next,
        'serverTime'=>gmdate(DATE_ATOM),
    ];
}
