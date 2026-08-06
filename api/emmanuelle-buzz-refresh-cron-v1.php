<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-orchestrator-core.php';
require __DIR__.'/data-engine-core.php';
require __DIR__.'/content-intelligence-core.php';

const P50_EK_BUZZ_REFRESH_VERSION='EMMANUELLE-BUZZ-REFRESH-V1.0';
const P50_EK_PROFILE_ID='census-emmanuelle-keita';
header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['error'=>'Méthode refusée.'],405);
$raw=file_get_contents('php://input');
if($raw===false||strlen($raw)>16384)json_response(['error'=>'Corps invalide.'],413);
$cfg=p50_mo_config();$secret=(string)$cfg['cronSecret'];
if(!$cfg['enabled']||strlen($secret)<32)json_response(['error'=>'Cron non configuré.'],503);
$timestamp=trim((string)($_SERVER['HTTP_X_P50_TIMESTAMP']??''));
$signature=strtolower(trim((string)($_SERVER['HTTP_X_P50_SIGNATURE']??'')));
if(!preg_match('/^\d{10}$/',$timestamp)||abs(time()-(int)$timestamp)>300)json_response(['error'=>'Horodatage refusé.'],401);
if(!p50_mo_verify_cron_signature($secret,$timestamp,$raw,$signature))json_response(['error'=>'Signature refusée.'],401);
$input=json_decode($raw,true);if(!is_array($input))json_response(['error'=>'JSON invalide.'],422);
$dispatchId=trim((string)($input['dispatchId']??''));
if($dispatchId===''||strlen($dispatchId)>120||!preg_match('/^[A-Za-z0-9._-]+$/',$dispatchId))json_response(['error'=>'dispatchId invalide.'],422);

$pdo=db();p50_de_ensure_schema();p50_de_sync_registry_from_state();p50_ci_ensure_schema($pdo);
$profiles=p50_de_registry_profiles(P50_EK_PROFILE_ID,1,0,false);
if(!$profiles)json_response(['error'=>'Fiche Emmanuelle Keita introuvable.'],404);
$profile=$profiles[0];
$before=p50_de_activity_events(P50_EK_PROFILE_ID,false,100);
$warnings=[];$collect=[];
try{$collect['youtube']=p50_de_collect_youtube_activity($profile);}catch(Throwable $e){$warnings[]='YouTube : '.$e->getMessage();}
try{$collect['social']=p50_de_collect_social_activity($profile);}catch(Throwable $e){$warnings[]='Réseaux : '.$e->getMessage();}
$after=p50_de_activity_events(P50_EK_PROFILE_ID,false,100);
$recent=array_values(array_filter($after,static function($event){
    $date=(string)($event['published_at']??$event['collected_at']??'');
    if($date==='')return false;
    try{return new DateTimeImmutable($date)>=new DateTimeImmutable('-7 days');}catch(Throwable){return false;}
}));
$newsSync=p50_ci_sync_official_news($pdo,800);
$trends=p50_ci_calculate_trends($pdo);
$signalWritten=false;
if($recent){
    $pdo->beginTransaction();
    try{
        $state=p50_de_load_public_state_for_update();
        if($state){
            $state['signals']=is_array($state['signals']??null)?$state['signals']:[];
            $exists=false;
            foreach($state['signals'] as $signal){
                if((string)($signal['profileId']??'')===P50_EK_PROFILE_ID&&str_starts_with((string)($signal['id']??''),'auto_ek_buzz_')){$exists=true;break;}
            }
            if(!$exists){
                $state['signals'][]=['id'=>'auto_ek_buzz_'.gmdate('YmdHi'),'profileId'=>P50_EK_PROFILE_ID,
                    'title'=>'Activité récente détectée autour d’Emmanuelle Keita','platforms'=>array_values(array_unique(array_map(static fn($e)=>(string)($e['platform']??'Web'),$recent))),
                    'confidence'=>'élevée','status'=>'pending','createdAt'=>(int)round(microtime(true)*1000),
                    'source'=>'collecte automatique','evidenceCount'=>count($recent)];
                p50_de_save_public_state($state,null,true);$signalWritten=true;
            }
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$warnings[]='Signal : '.$e->getMessage();}
}
json_response(['ok'=>true,'version'=>P50_EK_BUZZ_REFRESH_VERSION,'dispatchId'=>$dispatchId,'profileId'=>P50_EK_PROFILE_ID,
    'eventsBefore'=>count($before),'eventsAfter'=>count($after),'recentEvents'=>count($recent),'signalWritten'=>$signalWritten,
    'collection'=>$collect,'newsSync'=>$newsSync,'trendRun'=>$trends,'warnings'=>$warnings,'publicStateWrites'=>$signalWritten?1:0]);
