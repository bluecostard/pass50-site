<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-orchestrator-core.php';
require __DIR__.'/metrics-collector-readiness-core.php';

header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['error'=>'Méthode refusée.'],405);
$contentType=strtolower(trim((string)($_SERVER['CONTENT_TYPE']??'')));
if(!preg_match('~^application/json(?:\s*;\s*charset=[A-Za-z0-9._-]+)?$~',$contentType))json_response(['error'=>'Type de contenu refusé.'],415);
$length=(int)($_SERVER['CONTENT_LENGTH']??0);
if($length>16384)json_response(['error'=>'Corps trop volumineux.'],413);
$raw=file_get_contents('php://input');
if($raw===false||strlen($raw)>16384)json_response(['error'=>'Corps invalide.'],413);

$cfg=p50_mo_config();
$secret=(string)$cfg['cronSecret'];
if(!$cfg['enabled'])json_response(['error'=>'Orchestrateur métrique désactivé.'],503);
if(strlen($secret)<32)json_response(['error'=>'Cron métrique non configuré.'],503);
$timestamp=trim((string)($_SERVER['HTTP_X_P50_TIMESTAMP']??''));
$signature=strtolower(trim((string)($_SERVER['HTTP_X_P50_SIGNATURE']??'')));
if(!preg_match('/^\d{10}$/',$timestamp)||abs(time()-(int)$timestamp)>300)json_response(['error'=>'Horodatage refusé.'],401);
if(!p50_mo_verify_cron_signature($secret,$timestamp,$raw,$signature))json_response(['error'=>'Signature refusée.'],401);

$input=json_decode($raw,true);
if(!is_array($input))json_response(['error'=>'JSON invalide.'],422);
$keys=array_keys($input);sort($keys);
if($keys!==['action','dispatchId'])json_response(['error'=>'Corps JSON invalide.'],422);
if(($input['action']??null)!=='probe')json_response(['error'=>'Action invalide.'],422);
if(!is_string($input['dispatchId']??null))json_response(['error'=>'dispatchId invalide.'],422);
$dispatchId=trim($input['dispatchId']);
if($dispatchId===''||strlen($dispatchId)>120||!preg_match('/^[A-Za-z0-9._-]+$/',$dispatchId))json_response(['error'=>'dispatchId invalide.'],422);

function p50_multinet_preview(PDO $pdo,string $cadence,string $dispatchId): array {
    try{
        $preview=p50_mo_dispatch($pdo,$cadence,$dispatchId.'-'.$cadence,['preview'=>true,'source'=>'cron_hmac']);
        $byPlatform=[];
        foreach((array)($preview['candidates']??[]) as $candidate){
            $platform=(string)($candidate['platform']??'');
            if($platform!=='')$byPlatform[$platform]=($byPlatform[$platform]??0)+1;
        }
        ksort($byPlatform);
        return [
            'ok'=>true,
            'summary'=>$preview['summary']??[],
            'candidateCount'=>count((array)($preview['candidates']??[])),
            'candidatesByPlatform'=>$byPlatform,
        ];
    }catch(Throwable $error){
        $safe=p50_metrics_safe_error($error);
        error_log('PASS50 '.$cadence.' readiness: '.$safe);
        return ['ok'=>false,'error'=>$safe,'candidateCount'=>0,'candidatesByPlatform'=>[]];
    }
}

try{
    $pdo=db();
    $readiness=p50_mcr_status($pdo);
    $collectors=p50_metrics_collectors_status($pdo);
    $sanitizedCollectors=[];
    foreach(['youtube','x','tiktok','instagram','facebook','snapchat'] as $key){
        $row=(array)($collectors[$key]??[]);
        $sanitizedCollectors[$key]=[
            'configured'=>(bool)($row['configured']??false),
            'authorized'=>(bool)($row['authorized']??false),
            'mode'=>(string)($row['mode']??'unknown'),
            'authorizationRequired'=>(bool)($row['authorizationRequired']??false),
            'accounts'=>$row['accounts']??null,
            'contents'=>$row['contents']??null,
            'captures'=>$row['captures']??null,
            'usableCaptures'=>$row['usableCaptures']??null,
            'latestCaptureAt'=>$row['latestCaptureAt']??null,
            'captures24h'=>$row['captures24h']??null,
            'lastStatus'=>$row['lastStatus']??null,
            'lastError'=>$row['lastError']??null,
            'unavailableProfiles'=>(int)($row['unavailableProfiles']??0),
        ];
    }
    json_response([
        'ok'=>true,
        'version'=>'MULTINET-ACTIVATION-V1.1',
        'runtime'=>[
            'orchestrator'=>defined('P50_METRICS_ORCHESTRATOR_VERSION')?P50_METRICS_ORCHESTRATOR_VERSION:null,
            'tiktokBridge'=>defined('P50_TIKTOK_METRICS_BRIDGE_VERSION')?P50_TIKTOK_METRICS_BRIDGE_VERSION:null,
        ],
        'dispatchId'=>$dispatchId,
        'generatedAt'=>gmdate('c'),
        'readiness'=>[
            'platforms'=>$readiness['platforms']??[],
            'requirements'=>$readiness['requirements']??[],
        ],
        'collectors'=>$sanitizedCollectors,
        'p0Preview'=>p50_multinet_preview($pdo,'p0',$dispatchId),
        'p1Preview'=>p50_multinet_preview($pdo,'p1',$dispatchId),
        'secretsExposed'=>false,
        'publicStateWrites'=>0,
    ]);
}catch(Throwable $error){
    error_log('PASS50 collector readiness cron: '.p50_metrics_safe_error($error));
    json_response(['error'=>'Diagnostic multi-réseaux interrompu.'],500);
}
