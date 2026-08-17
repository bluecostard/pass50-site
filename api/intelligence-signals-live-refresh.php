<?php
declare(strict_types=1);

require_once __DIR__.'/intelligence-signals-core.php';

const P50_INTELLIGENCE_SIGNALS_LIVE_REFRESH='PASS50-INTELLIGENCE-SIGNALS-LIVE-REFRESH-V1.0';

function p50_is_live_refresh(int $limit=20,int $maxAgeMinutes=60): array {
    p50_is_ensure_schema();
    $limit=max(1,min(40,$limit));
    $maxAgeMinutes=max(15,min(360,$maxAgeMinutes));
    $import=p50_is_import_all();
    $currentIds=(array)($import['currentIds']??[]);
    if(!$currentIds)return [
        'version'=>P50_INTELLIGENCE_SIGNALS_LIVE_REFRESH,
        'requested'=>0,'processed'=>0,'errors'=>[],'reason'=>'no_profiles',
    ];

    $sql="SELECT ranked.profile_id,ranked.signal_score,ranked.last_signal_at,latest.period_end
      FROM (
        SELECT profile_id,MAX(signal_score) signal_score,MAX(occurred_at) last_signal_at
        FROM p50_signal_events
        WHERE status<>'rejected' AND occurred_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)
        GROUP BY profile_id
      ) ranked
      LEFT JOIN (
        SELECT s.profile_id,s.period_end
        FROM p50_intelligence_snapshots s
        JOIN (SELECT profile_id,MAX(id) latest_id FROM p50_intelligence_snapshots GROUP BY profile_id) x ON x.latest_id=s.id
      ) latest ON BINARY latest.profile_id=BINARY ranked.profile_id
      JOIN p50_profile_registry r ON BINARY r.profile_id=BINARY ranked.profile_id AND r.alive=1
      WHERE latest.period_end IS NULL OR latest.period_end<DATE_SUB(UTC_TIMESTAMP(),INTERVAL $maxAgeMinutes MINUTE)
      ORDER BY ranked.signal_score DESC,ranked.last_signal_at DESC
      LIMIT $limit";
    $rows=db()->query($sql)->fetchAll();
    $processed=[];$errors=[];
    foreach($rows as $row){
        $profileId=(string)($row['profile_id']??'');
        if($profileId===''||!isset($currentIds[$profileId]))continue;
        try{
            p50_intelligence_run_profile($profileId);
            $processed[]=$profileId;
        }catch(Throwable $error){
            $errors[]=['profileId'=>$profileId,'error'=>substr($error->getMessage(),0,180)];
        }
    }
    return [
        'version'=>P50_INTELLIGENCE_SIGNALS_LIVE_REFRESH,
        'requested'=>count($rows),'processed'=>count($processed),'processedProfileIds'=>$processed,
        'errors'=>$errors,'manualSignalsImported'=>(int)($import['manualImported']??0),
        'activitySignalsImported'=>(int)($import['activityImported']??0),
        'liveSignalsImported'=>(int)($import['liveImported']??0),
    ];
}
