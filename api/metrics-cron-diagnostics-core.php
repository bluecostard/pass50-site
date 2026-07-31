<?php
declare(strict_types=1);

const P50_METRICS_WORK_DIAGNOSTICS_VERSION='WORK-DIAG-V1.0';

function p50_mcd_error_code(?string $message): ?string {
    $value=strtolower(trim((string)$message));
    if($value==='')return null;
    $signals=[
        'quota_exceeded'=>['quota_exceeded','quota exceeded'],
        'rate_limited'=>['rate_limited','rate limited','http 429','too many requests'],
        'authorization_required'=>['authorization_required','réautorisation','reauthorization'],
        'configuration_missing'=>['configuration_missing','non configuré','not configured'],
        'unsupported_account_type'=>['unsupported_account_type'],
        'forbidden'=>['forbidden','http 403'],
        'not_found'=>['introuvable','not found','http 404'],
        'timeout'=>['timeout','timed out'],
        'network_error'=>['network','réseau','curl'],
        'http_server_error'=>['http 500','http 502','http 503','http 504','http 5'],
        'unavailable_or_blocked'=>['unavailable_or_blocked','indisponible','bloqué'],
        'invalid_source'=>['lien','source officielle vérifiée introuvable','tâche métrique invalide'],
    ];
    foreach($signals as $code=>$needles)foreach($needles as $needle)if(str_contains($value,$needle))return $code;
    return 'collector_error';
}

function p50_mcd_job(PDO $pdo,string $jobUuid): ?array {
    if($jobUuid===''||!p50_metrics_table_exists($pdo,'p50_metric_jobs'))return null;
    $stmt=$pdo->prepare("SELECT job_uuid,scope_id,platform,status,attempts,max_attempts,last_error,payload_json FROM p50_metric_jobs WHERE job_uuid=? LIMIT 1");
    $stmt->execute([$jobUuid]);$row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function p50_mcd_work(PDO $pdo,array $work): ?array {
    if((int)($work['processed']??0)!==1)return null;
    $jobUuid=trim((string)($work['jobUuid']??''));$job=p50_mcd_job($pdo,$jobUuid);$result=is_array($work['result']??null)?$work['result']:[];
    $payload=$job?json_decode((string)($job['payload_json']??''),true):[];$payload=is_array($payload)?$payload:[];
    $profileId=trim((string)($result['profileId']??$payload['profileId']??$job['scope_id']??''));
    $platform=p50_mc_platform((string)($result['platform']??$payload['platform']??$job['platform']??''));
    $messages=array_map('strval',(array)($result['errors']??[]));
    if(!empty($job['last_error']))$messages[]=(string)$job['last_error'];
    $codes=[];foreach($messages as $message){$code=p50_mcd_error_code($message);if($code!==null)$codes[$code]=true;}
    return [
        'version'=>P50_METRICS_WORK_DIAGNOSTICS_VERSION,
        'jobUuid'=>$jobUuid?:null,'profileId'=>$profileId?:null,'platform'=>$platform?:null,
        'jobStatus'=>(string)($work['status']??$job['status']??'unknown'),
        'collectorStatus'=>(string)($result['status']??($work['status']??'unknown')),
        'attempts'=>(int)($job['attempts']??0),'maxAttempts'=>(int)($job['max_attempts']??0),
        'accountFound'=>(bool)($result['accountFound']??false),'contentsFound'=>(int)($result['contentsFound']??0),
        'capturesRecorded'=>(int)($result['capturesRecorded']??0),'duplicatesSkipped'=>(int)($result['duplicatesSkipped']??0),
        'quarantined'=>(int)($result['quarantined']??0),'unavailableMetrics'=>(int)($result['unavailableMetrics']??0),
        'requestsAttempted'=>(int)($result['requestsAttempted']??0),'requestsSucceeded'=>(int)($result['requestsSucceeded']??0),
        'rateLimited'=>(bool)($result['rateLimited']??false),'errorCodes'=>array_keys($codes),
    ];
}
