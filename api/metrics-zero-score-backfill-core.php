<?php
declare(strict_types=1);

require_once __DIR__.'/metrics-ranking-publication-apply-core.php';

const P50_MZB_VERSION='ZERO-SCORE-BACKFILL-V1.1';
const P50_MZB_LOCK='pass50_zero_score_backfill_v1';
const P50_MZB_PERIODS=['2H','24H','48H','7J','15J'];

function p50_mzb_state(PDO $pdo,bool $forUpdate=false): array {
    $sql="SELECT data FROM app_state WHERE id='public' LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $raw=$pdo->query($sql)->fetchColumn();
    $state=$raw?json_decode((string)$raw,true):null;
    if(!is_array($state))throw new RuntimeException('État public invalide.');
    return $state;
}

function p50_mzb_score_missing(mixed $value): bool {
    return !is_numeric($value)||(float)$value<=0.0;
}

function p50_mzb_zero_profile_ids(array $state,string $anchor='24H'): array {
    $ids=[];
    foreach((array)($state['profiles']??[]) as $profile){
        if(!is_array($profile)||empty($profile['id']))continue;
        $scores=is_array($profile['scores']??null)?$profile['scores']:[];
        if(p50_mzb_score_missing($scores[$anchor]??null))$ids[]=(string)$profile['id'];
    }
    return array_values(array_unique($ids));
}

function p50_mzb_dispatch(PDO $pdo,string $dispatchId): array {
    p50_metrics_ensure_schema($pdo);
    $state=p50_mzb_state($pdo);
    $ids=p50_mzb_zero_profile_ids($state);
    $summary=[
        'zeroProfiles'=>count($ids),'profilesWithVerifiedSources'=>0,'operationalProfiles'=>0,
        'jobsCreated'=>0,'duplicateJobs'=>0,'skippedConfiguration'=>0,'skippedAuthorization'=>0,
        'verifiedLinks'=>0,'operationalLinks'=>0,'publicStateWrites'=>0,
    ];
    if(!$ids)return ['ok'=>true,'version'=>P50_MZB_VERSION,'dispatchId'=>$dispatchId,'summary'=>$summary,'remaining'=>0];

    $placeholders=implode(',',array_fill(0,count($ids),'?'));
    $stmt=$pdo->prepare("SELECT r.profile_id,s.platform FROM p50_profile_registry r JOIN p50_social_links s ON BINARY s.profile_id=BINARY r.profile_id
      WHERE r.alive=1 AND r.profile_id IN ($placeholders) AND s.status='verified' AND s.confidence>=?
      AND s.platform IN ('YouTube','X','TikTok','Instagram','Facebook','Snapchat') ORDER BY r.profile_id,s.platform LIMIT 3000");
    $stmt->execute([...$ids,p50_mc_threshold()]);
    $rows=p50_mo_unique_candidate_rows(array_merge($stmt->fetchAll(),p50_mo_oauth_youtube_rows($pdo,$ids),p50_mo_oauth_meta_rows($pdo,$ids)));
    $summary['verifiedLinks']=count($rows);
    $withSources=[];$operational=[];
    foreach($rows as $row){
        $profileId=(string)$row['profile_id'];$platform=(string)$row['platform'];$withSources[$profileId]=true;
        $access=p50_mc_public_access($platform,$profileId);
        if(empty($access['configured'])){$summary['skippedConfiguration']++;continue;}
        if(empty($access['authorized'])){$summary['skippedAuthorization']++;continue;}
        $operational[$profileId]=true;$summary['operationalLinks']++;
        $active=$pdo->prepare("SELECT COUNT(*) FROM p50_metric_jobs WHERE scope_type='profile' AND scope_id=? AND platform=? AND status IN ('pending','running','retry_wait')");
        $active->execute([$profileId,$platform]);
        if((int)$active->fetchColumn()>0){$summary['duplicateJobs']++;continue;}
        $job=p50_mo_enqueue_profile($pdo,$profileId,$platform,'p0',[
            'reason'=>'zero_score_backfill_'.$dispatchId,'priorityOverride'=>5,'contentLimit'=>5,
            'dispatchId'=>$dispatchId,'now'=>gmdate('c'),
        ]);
        $summary[$job['created']?'jobsCreated':'duplicateJobs']++;
    }
    $summary['profilesWithVerifiedSources']=count($withSources);
    $summary['operationalProfiles']=count($operational);
    return ['ok'=>true,'version'=>P50_MZB_VERSION,'dispatchId'=>$dispatchId,'summary'=>$summary,'remaining'=>p50_mzb_remaining($pdo,$dispatchId)];
}

function p50_mzb_remaining(PDO $pdo,string $dispatchId): int {
    $needle='%\"dispatchId\":\"'.str_replace(['%','_'],['\\%','\\_'],$dispatchId).'\"%';
    return (int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_jobs WHERE payload_json LIKE ? ESCAPE '\\\\' AND status IN ('pending','running','retry_wait')",[$needle]);
}

function p50_mzb_work(PDO $pdo,string $dispatchId): array {
    $work=p50_metrics_process_next_job($pdo);
    return ['ok'=>true,'version'=>P50_MZB_VERSION,'dispatchId'=>$dispatchId,'work'=>$work,'remaining'=>p50_mzb_remaining($pdo,$dispatchId),'publicStateWrites'=>0];
}

function p50_mzb_calculate(PDO $pdo,string $dispatchId): array {
    $result=p50_mr_calculate($pdo,P50_MZB_PERIODS,'zero_score_backfill',['dispatchId'=>$dispatchId]);
    return ['ok'=>true,'version'=>P50_MZB_VERSION,'dispatchId'=>$dispatchId,'ranking'=>$result,'publicStateWrites'=>0];
}

function p50_mzb_positive_map(array $state): array {
    $map=[];
    foreach((array)($state['profiles']??[]) as $profile){
        if(!is_array($profile)||empty($profile['id']))continue;
        $scores=is_array($profile['scores']??null)?$profile['scores']:[];
        foreach(P50_MZB_PERIODS as $period){
            $value=$scores[$period]??null;
            if(is_numeric($value)&&(float)$value>0)$map[(string)$profile['id'].'|'.$period]=(float)$value;
        }
    }
    ksort($map);return $map;
}

function p50_mzb_assert_preserved(array $before,array $after): void {
    foreach($before as $key=>$value){
        if(!array_key_exists($key,$after)||abs((float)$after[$key]-(float)$value)>0.0001){
            throw new RuntimeException('Protection des scores existants déclenchée.');
        }
    }
}

function p50_mzb_ensure_audit_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS p50_metric_zero_score_backfills (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,run_uuid CHAR(36) CHARACTER SET ascii NOT NULL,
      dispatch_id VARCHAR(120) CHARACTER SET ascii NOT NULL,status VARCHAR(24) NOT NULL,
      zero_profiles_before INT UNSIGNED NOT NULL DEFAULT 0,profiles_updated INT UNSIGNED NOT NULL DEFAULT 0,
      scores_written INT UNSIGNED NOT NULL DEFAULT 0,positive_scores_preserved INT UNSIGNED NOT NULL DEFAULT 0,
      backup_json LONGTEXT NOT NULL,report_json LONGTEXT NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_p50_mzb_dispatch(dispatch_id),UNIQUE KEY uq_p50_mzb_run(run_uuid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function p50_mzb_apply(PDO $pdo,string $dispatchId): array {
    p50_mzb_ensure_audit_schema($pdo);
    $existing=$pdo->prepare("SELECT report_json FROM p50_metric_zero_score_backfills WHERE dispatch_id=? LIMIT 1");
    $existing->execute([$dispatchId]);$prior=$existing->fetchColumn();
    if($prior){$decoded=json_decode((string)$prior,true);if(is_array($decoded))return $decoded+['duplicate'=>true];}
    if((int)p50_metrics_value($pdo,'SELECT GET_LOCK(?,10)',[P50_MZB_LOCK])!==1)throw new RuntimeException('Rattrapage déjà en cours.');
    $runUuid=p50_mr_uuid();
    try{
        $pdo->beginTransaction();$state=p50_mzb_state($pdo,true);$backup=$state;
        $zeroIds=array_fill_keys(p50_mzb_zero_profile_ids($state),true);$positiveBefore=p50_mzb_positive_map($state);
        $stmt=$pdo->prepare("SELECT profile_id,period_key,score,latest_capture_at FROM p50_metric_ranking_current
          WHERE algorithm_version=? AND classable=1 AND score IS NOT NULL AND score>0 AND period_key IN ('2H','24H','48H','7J','15J')");
        $stmt->execute([P50_MR_ALGORITHM_VERSION]);$candidates=[];
        foreach($stmt->fetchAll() as $row)$candidates[(string)$row['profile_id']][(string)$row['period_key']]=$row;
        $profilesUpdated=[];$scoresWritten=0;
        foreach((array)($state['profiles']??[]) as $index=>$profile){
            if(!is_array($profile)||empty($profile['id'])||!isset($zeroIds[(string)$profile['id']]))continue;
            $id=(string)$profile['id'];$scores=is_array($profile['scores']??null)?$profile['scores']:[];$latest='';
            foreach(P50_MZB_PERIODS as $period){
                if(!p50_mzb_score_missing($scores[$period]??null))continue;
                $candidate=$candidates[$id][$period]??null;if(!$candidate||!is_numeric($candidate['score']))continue;
                $value=p50_mrp_apply_public_score((float)$candidate['score']);if($value<=0)continue;
                $scores[$period]=$value;$scoresWritten++;$profilesUpdated[$id]=true;
                $captured=(string)($candidate['latest_capture_at']??'');if($captured>$latest)$latest=$captured;
            }
            if(!isset($profilesUpdated[$id]))continue;
            $profile['scores']=$scores;if($latest!=='')$profile['lastCollectedAt']=gmdate('c',strtotime($latest));
            $engine=is_array($profile['dataEngine']??null)?$profile['dataEngine']:[];
            $engine['zeroScoreBackfillVersion']=P50_MZB_VERSION;$engine['zeroScoreBackfilledAt']=gmdate('c');$profile['dataEngine']=$engine;
            $state['profiles'][$index]=$profile;
        }
        $positiveAfter=p50_mzb_positive_map($state);p50_mzb_assert_preserved($positiveBefore,$positiveAfter);
        $writes=0;if($scoresWritten>0){
            $state['stateRevision']=max(0,(int)($state['stateRevision']??0))+1;$state['publishedAt']=gmdate('c');
            $pdo->prepare("UPDATE app_state SET data=?,updated_by=NULL,updated_at=NOW() WHERE id='public'")->execute([json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);$writes=1;
        }
        $report=['ok'=>true,'version'=>P50_MZB_VERSION,'dispatchId'=>$dispatchId,'runUuid'=>$runUuid,'zeroProfilesBefore'=>count($zeroIds),'profilesUpdated'=>count($profilesUpdated),'scoresWritten'=>$scoresWritten,'positiveScoresPreserved'=>count($positiveBefore),'publicStateWrites'=>$writes,'stateRevision'=>(int)($state['stateRevision']??0)];
        $pdo->prepare("INSERT INTO p50_metric_zero_score_backfills(run_uuid,dispatch_id,status,zero_profiles_before,profiles_updated,scores_written,positive_scores_preserved,backup_json,report_json) VALUES(?,?,'applied',?,?,?,?,?,?)")
          ->execute([$runUuid,$dispatchId,count($zeroIds),count($profilesUpdated),$scoresWritten,count($positiveBefore),json_encode($backup,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
        $pdo->commit();return $report;
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    finally{try{p50_metrics_value($pdo,'SELECT RELEASE_LOCK(?)',[P50_MZB_LOCK]);}catch(Throwable){}}
}
