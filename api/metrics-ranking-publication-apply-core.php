<?php
declare(strict_types=1);

/**
 * Publication contrôlée MR-V1.0 → classement public (app_state.id='public').
 * - Backup complet avant écriture
 * - Garde-fous simulation (bootstrap assouplit entrées/sorties au 1er passage)
 * - Modes: preview | controlled | automatic
 */
require_once __DIR__.'/metrics-ranking-publication-core.php';
require_once __DIR__.'/metrics-orchestrator-core.php';
require_once __DIR__.'/data-engine-core.php';

const P50_MRP_APPLY_VERSION='PUBAPPLY-V1.0';
const P50_MRP_APPLY_LOCK='pass50_metrics_ranking_publication_apply_v1';
const P50_MRP_APPLY_PERIODS=['2H','24H','48H','7J','15J'];
const P50_MRP_APPLY_STALE_HOURS=2.5;

function p50_mrp_apply_config(): array {
    $cfg=p50_mo_config();
    global $config;$m=(array)($config['metrics']??[]);
    $publication=filter_var($m['ranking_publication_enabled']??(getenv('PASS50_RANKING_PUBLICATION_ENABLED')?:false),FILTER_VALIDATE_BOOLEAN);
    $automatic=filter_var($m['ranking_automatic_publication_enabled']??(getenv('PASS50_RANKING_AUTOMATIC_PUBLICATION_ENABLED')?:false),FILTER_VALIDATE_BOOLEAN);
    $bootstrap=filter_var($m['ranking_publication_bootstrap_allowed']??(getenv('PASS50_RANKING_BOOTSTRAP_ALLOWED')?:'true'),FILTER_VALIDATE_BOOLEAN);
    return [
        'orchestratorEnabled'=>(bool)$cfg['enabled'],
        'publicationEnabled'=>$publication,
        'automaticPublicationEnabled'=>$publication&&$automatic,
        'bootstrapAllowed'=>$bootstrap,
        'cronSecret'=>(string)$cfg['cronSecret'],
    ];
}

/** Santé publication pour l’admin (pas besoin d’ouvrir GitHub). */
function p50_mrp_apply_health(PDO $pdo): array {
    $cfg=p50_mrp_apply_config();
    $last=null;
    if(function_exists('p50_metrics_table_exists')&&p50_metrics_table_exists($pdo,'p50_metric_publication_applies')){
        $stmt=$pdo->query("SELECT apply_uuid,public_revision_after,profiles_updated,scores_written,generated_at
          FROM p50_metric_publication_applies
          WHERE status='applied' AND public_revision_after>0
          ORDER BY id DESC LIMIT 1");
        $row=$stmt?$stmt->fetch():false;
        if($row){
            $generatedAt=(string)$row['generated_at'];
            $ts=strtotime($generatedAt.' UTC');
            $ageHours=$ts===false?null:max(0,(time()-$ts)/3600);
            $last=[
                'applyUuid'=>(string)$row['apply_uuid'],
                'revision'=>(int)$row['public_revision_after'],
                'profilesUpdated'=>(int)$row['profiles_updated'],
                'scoresWritten'=>(int)$row['scores_written'],
                'generatedAt'=>gmdate('c',$ts?:time()),
                'ageHours'=>$ageHours===null?null:round($ageHours,3),
            ];
        }
    }
    $age=$last['ageHours']??null;
    $flagsOn=!empty($cfg['publicationEnabled'])&&!empty($cfg['automaticPublicationEnabled']);
    $stale=$age!==null&&$age>P50_MRP_APPLY_STALE_HOURS;
    if(!$flagsOn)$status='flags_off';
    elseif($last===null)$status='never_published';
    elseif($stale)$status='stale';
    else $status='fresh';
    $labels=[
        'fresh'=>'Classement public à jour',
        'stale'=>'Classement public en retard',
        'flags_off'=>'Publication automatique désactivée',
        'never_published'=>'Aucune écriture publique trouvée',
    ];
    return [
        'status'=>$status,
        'label'=>$labels[$status]??$status,
        'ok'=>$status==='fresh',
        'stale'=>$stale,
        'staleAfterHours'=>P50_MRP_APPLY_STALE_HOURS,
        'publicationEnabled'=>(bool)$cfg['publicationEnabled'],
        'automaticPublicationEnabled'=>(bool)$cfg['automaticPublicationEnabled'],
        'lastApplied'=>$last,
    ];
}

function p50_mrp_apply_schema_sql(): array {
    return [
        "CREATE TABLE IF NOT EXISTS p50_metric_publication_applies (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          apply_uuid CHAR(36) CHARACTER SET ascii NOT NULL,
          dispatch_id VARCHAR(120) CHARACTER SET ascii NOT NULL,
          mode VARCHAR(24) NOT NULL,status VARCHAR(24) NOT NULL,
          algorithm_version VARCHAR(24) NOT NULL,run_uuid CHAR(36) CHARACTER SET ascii NOT NULL,
          periods_json LONGTEXT NOT NULL,
          public_revision_before BIGINT UNSIGNED NOT NULL DEFAULT 0,
          public_revision_after BIGINT UNSIGNED NOT NULL DEFAULT 0,
          public_fingerprint_before CHAR(64) CHARACTER SET ascii NOT NULL,
          candidate_fingerprint CHAR(64) CHARACTER SET ascii NOT NULL,
          profiles_updated INT UNSIGNED NOT NULL DEFAULT 0,
          scores_written INT UNSIGNED NOT NULL DEFAULT 0,
          entries_count INT UNSIGNED NOT NULL DEFAULT 0,
          exits_count INT UNSIGNED NOT NULL DEFAULT 0,
          bootstrap TINYINT(1) NOT NULL DEFAULT 0,
          backup_json LONGTEXT NOT NULL,
          report_json LONGTEXT NOT NULL,
          applied_by VARCHAR(120) NOT NULL DEFAULT '',
          error_message VARCHAR(500) NULL,
          generated_at DATETIME NOT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uq_p50_mrpa_apply_uuid(apply_uuid),
          UNIQUE KEY uq_p50_mrpa_dispatch_id(dispatch_id),
          INDEX idx_p50_mrpa_status_created(status,created_at),
          INDEX idx_p50_mrpa_run(run_uuid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

function p50_mrp_apply_ensure_schema(PDO $pdo): array {
    foreach(p50_mrp_apply_schema_sql() as $sql)$pdo->exec($sql);
    return ['status'=>p50_metrics_table_exists($pdo,'p50_metric_publication_applies')?'applied':'missing','table'=>'p50_metric_publication_applies'];
}

function p50_mrp_apply_has_prior_success(PDO $pdo): bool {
    if(!p50_metrics_table_exists($pdo,'p50_metric_publication_applies'))return false;
    $count=(int)$pdo->query("SELECT COUNT(*) FROM p50_metric_publication_applies WHERE status='applied'")->fetchColumn();
    return $count>0;
}

function p50_mrp_apply_public_score(float $score): float {
    return round(max(0,min(100,$score)),1);
}

/** updated_by → users.id (CHAR36). Les acteurs cron/système passent en NULL. */
function p50_mrp_apply_state_actor(?string $appliedBy): ?string {
    $appliedBy=trim((string)$appliedBy);
    if($appliedBy!==''&&preg_match('/^[0-9a-fA-F-]{36}$/',$appliedBy))return $appliedBy;
    return null;
}

function p50_mrp_apply_relax_gates(array $report,bool $bootstrap): array {
    if(!$bootstrap)return $report;
    $gates=[];
    foreach((array)($report['gates']??[]) as $gate){
        if(!is_array($gate))continue;
        $key=(string)($gate['key']??'');
        if(in_array($key,['exit_ratio','entry_ratio','maximum_rank_movement'],true)&&($gate['status']??'')==='block'){
            $gate['status']='warn';
            $gate['message']=($gate['message']??'').' (assoupli en bootstrap 1er passage)';
            $gate['bootstrapRelaxed']=true;
        }
        $gates[]=$gate;
    }
    $blocked=(bool)array_filter($gates,static fn($g)=>($g['status']??'')==='block');
    $warnings=(bool)array_filter($gates,static fn($g)=>($g['status']??'')==='warn');
    $report['gates']=$gates;
    $report['status']=$blocked?'blocked':($warnings?'review':'ready');
    $report['bootstrap']=true;
    return $report;
}

function p50_mrp_apply_plan_period(PDO $pdo,string $period,bool $bootstrap,?DateTimeImmutable $now=null): array {
    $period=p50_mrp_period($period);
    $report=p50_mrp_simulate($pdo,$period,500,$now);
    $report=p50_mrp_apply_relax_gates($report,$bootstrap);
    $mutations=[];$entries=0;$exits=0;$updates=0;
    foreach((array)($report['movements']??[]) as $movement){
        if(!is_array($movement))continue;
        $profileId=trim((string)($movement['profileId']??''));
        if($profileId==='')continue;
        $type=(string)($movement['type']??'');
        if($type==='exit'){
            $mutations[]=['profileId'=>$profileId,'period'=>$period,'action'=>'clear','score'=>null];
            $exits++;
        }elseif(in_array($type,['entry','up','down','stable'],true)&&isset($movement['candidateScore'])&&is_numeric($movement['candidateScore'])){
            $mutations[]=['profileId'=>$profileId,'period'=>$period,'action'=>'set','score'=>p50_mrp_apply_public_score((float)$movement['candidateScore'])];
            if($type==='entry')$entries++;else $updates++;
        }
    }
    $blockedGates=[];
    foreach((array)($report['gates']??[]) as $gate){
        if(is_array($gate)&&(($gate['status']??'')==='block'))$blockedGates[]=(string)($gate['key']??'');
    }
    $blockedGates=array_values(array_filter($blockedGates));
    return [
        'period'=>$period,
        'report'=>$report,
        'mutations'=>$mutations,
        'counts'=>['entries'=>$entries,'exits'=>$exits,'updates'=>$updates,'mutations'=>count($mutations)],
        'runUuid'=>(string)(($report['source']['experimentalRun']['runUuid']??'')?:''),
        'publicFingerprint'=>(string)($report['source']['publicFingerprint']??''),
        'candidateFingerprint'=>(string)($report['source']['candidateFingerprint']??''),
        'publicStateRevision'=>(int)($report['source']['publicStateRevision']??0),
        'status'=>(string)$report['status'],
        'blockedGates'=>$blockedGates,
    ];
}

/** Périodes sans candidat classable : on les saute au lieu de bloquer toute la publication. */
function p50_mrp_apply_is_skippable_plan(array $plan): bool {
    if(($plan['status']??'')!=='blocked')return false;
    $gates=array_values(array_unique(array_map('strval',(array)($plan['blockedGates']??[]))));
    if(!$gates)return false;
    // Sans candidat / sans run : exit_ratio et mouvements extrêmes sont mécaniques → skip période.
    if(!in_array('candidate_non_empty',$gates,true)&&!in_array('successful_run',$gates,true))return false;
    $extra=array_values(array_diff($gates,['candidate_non_empty','successful_run','exit_ratio','entry_ratio','maximum_rank_movement']));
    return $extra===[];
}

function p50_mrp_apply_preview(PDO $pdo,array $periods=null,?DateTimeImmutable $now=null,bool $forceBootstrap=false): array {
    $cfg=p50_mrp_apply_config();
    p50_mrp_apply_ensure_schema($pdo);
    $now=$now??new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $periods=$periods?:P50_MRP_APPLY_PERIODS;
    $bootstrap=$cfg['bootstrapAllowed']&&($forceBootstrap||!p50_mrp_apply_has_prior_success($pdo));
    $plans=[];$publishPlans=[];$blocked=false;$runUuid=null;$totalMutations=0;$entries=0;$exits=0;$skipped=[];
    foreach($periods as $period){
        if(!array_key_exists($period,p50_mr_periods()))continue;
        $plan=p50_mrp_apply_plan_period($pdo,$period,$bootstrap,$now);
        if(p50_mrp_apply_is_skippable_plan($plan)){
            $plan['status']='skipped';
            $plan['skipReason']=implode(',',(array)$plan['blockedGates']);
            $plans[$period]=$plan;
            $skipped[]=$period;
            continue;
        }
        $plans[$period]=$plan;
        if($plan['status']==='blocked'){$blocked=true;continue;}
        // ready + review (avertissements bootstrap) sont publiables.
        if(!in_array($plan['status'],['ready','review'],true))continue;
        $mut=(int)$plan['counts']['mutations'];
        if($mut<=0)continue;
        $publishPlans[$period]=$plan;
        $totalMutations+=$mut;
        $entries+=(int)$plan['counts']['entries'];
        $exits+=(int)$plan['counts']['exits'];
        $planRun=(string)$plan['runUuid'];
        if($planRun===''){$blocked=true;continue;}
        if($runUuid===null)$runUuid=$planRun;
        elseif($runUuid!==$planRun)$blocked=true;
    }
    $eligible=!$blocked&&$runUuid!==null&&$totalMutations>0&&$publishPlans!==[]&&$cfg['publicationEnabled'];
    $autoEligible=$eligible&&$cfg['automaticPublicationEnabled'];
    return [
        'ok'=>true,
        'version'=>P50_MRP_APPLY_VERSION,
        'algorithmVersion'=>P50_MR_ALGORITHM_VERSION,
        'generatedAt'=>$now->format(DATE_ATOM),
        'config'=>[
            'publicationEnabled'=>$cfg['publicationEnabled'],
            'automaticPublicationEnabled'=>$cfg['automaticPublicationEnabled'],
            'bootstrapAllowed'=>$cfg['bootstrapAllowed'],
            'orchestratorEnabled'=>$cfg['orchestratorEnabled'],
        ],
        'bootstrap'=>$bootstrap,
        'status'=>$blocked?'blocked':($eligible?($bootstrap?'review':'ready'):'blocked'),
        'publicationEligible'=>$eligible,
        'automaticPublicationEligible'=>$autoEligible,
        'runUuid'=>$runUuid,
        'periods'=>array_keys($publishPlans),
        'allPeriods'=>array_keys($plans),
        'publishPlans'=>$publishPlans,
        'summary'=>[
            'mutations'=>$totalMutations,
            'entries'=>$entries,
            'exits'=>$exits,
            'blockedPeriods'=>array_values(array_filter(array_keys($plans),static fn($p)=>($plans[$p]['status']??'')==='blocked')),
            'skippedPeriods'=>$skipped,
            'publishablePeriods'=>array_keys($publishPlans),
            'reasons'=>array_values(array_filter(array_map(static function($period) use ($plans){
                $plan=$plans[$period]??[];
                if(($plan['status']??'')==='blocked')return $period.':'.implode(',',(array)($plan['blockedGates']??['blocked']));
                if(($plan['status']??'')==='skipped')return $period.':skipped:'.(string)($plan['skipReason']??'');
                return null;
            },array_keys($plans)))),
        ],
        'plans'=>$plans,
        'nextPhase'=>$eligible?'apply_with_backup':'resolve_gates_or_enable_publication',
        'health'=>p50_mrp_apply_health($pdo),
    ];
}

function p50_mrp_apply_mutate_state(array $state,array $plans,string $runUuid): array {
    $index=[];
    foreach((array)($state['profiles']??[]) as $i=>$profile){
        if(!is_array($profile))continue;
        $id=trim((string)($profile['id']??''));
        if($id!=='')$index[$id]=$i;
    }
    $profilesUpdated=[];$scoresWritten=0;$primaryPeriod='2H';
    foreach($plans as $period=>$plan){
        foreach((array)($plan['mutations']??[]) as $mutation){
            if(!is_array($mutation))continue;
            $profileId=(string)$mutation['profileId'];
            if(!isset($index[$profileId]))continue;
            $i=$index[$profileId];
            $scores=is_array($state['profiles'][$i]['scores']??null)?$state['profiles'][$i]['scores']:[];
            if(($mutation['action']??'')==='clear'){
                unset($scores[$period]);
            }else{
                $scores[$period]=p50_mrp_apply_public_score((float)$mutation['score']);
                $scoresWritten++;
            }
            $state['profiles'][$i]['scores']=$scores;
            if($period===$primaryPeriod){
                if(isset($scores[$primaryPeriod])){
                    $state['profiles'][$i]['score']=$scores[$primaryPeriod];
                    $state['profiles'][$i]['eligible']=true;
                    $state['profiles'][$i]['classable']=true;
                    $score=(float)$scores[$primaryPeriod];
                    $badges=array_values(array_filter((array)($state['profiles'][$i]['badges']??[]),static fn($b)=>!in_array($b,['HOT','UP','VIRAL'],true)));
                    if($score>=88)$badges[]='HOT';
                    if($score>=82)$badges[]='UP';
                    $state['profiles'][$i]['badges']=array_values(array_unique($badges));
                }else{
                    // Sortie 2H : ne plus classer sur le score principal, conserver l’historique des autres périodes.
                    unset($state['profiles'][$i]['score']);
                    $state['profiles'][$i]['classable']=false;
                }
            }
            $engine=is_array($state['profiles'][$i]['dataEngine']??null)?$state['profiles'][$i]['dataEngine']:[];
            $engine['algorithmVersion']=P50_MR_ALGORITHM_VERSION;
            $engine['publishedAt']=gmdate('c');
            $engine['metricRankingRunUuid']=$runUuid;
            $engine['autoScore']=true;
            $engine['scoreStatus']='published_mr_v1';
            $state['profiles'][$i]['dataEngine']=$engine;
            $profilesUpdated[$profileId]=true;
        }
    }
    $state['metricsRankingMeta']=[
        'version'=>P50_MRP_APPLY_VERSION,
        'algorithmVersion'=>P50_MR_ALGORITHM_VERSION,
        'runUuid'=>$runUuid,
        'publishedAt'=>gmdate('c'),
        'periods'=>array_keys($plans),
    ];
    $state['publishedAt']=gmdate('c');
    return ['state'=>$state,'profilesUpdated'=>count($profilesUpdated),'scoresWritten'=>$scoresWritten];
}

function p50_mrp_apply_execute(PDO $pdo,array $options=[]): array {
    $cfg=p50_mrp_apply_config();
    $mode=trim((string)($options['mode']??'controlled'));
    if(!in_array($mode,['controlled','automatic'],true))$mode='controlled';
    $dispatchId=trim((string)($options['dispatchId']??''));
    $appliedBy=trim((string)($options['appliedBy']??''));
    $confirm=!(empty($options['confirm']));
    $forceBootstrap=!empty($options['bootstrap']);
    $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));

    if(!$cfg['publicationEnabled'])throw new RuntimeException('Publication du classement désactivée (metrics.ranking_publication_enabled).');
    if($mode==='automatic'&&!$cfg['automaticPublicationEnabled'])throw new RuntimeException('Publication automatique désactivée.');
    if(!$confirm)throw new InvalidArgumentException('Confirmation explicite requise.');
    if($dispatchId===''||strlen($dispatchId)>120||!preg_match('/^[A-Za-z0-9._-]+$/',$dispatchId))throw new InvalidArgumentException('dispatchId invalide.');

    p50_mrp_apply_ensure_schema($pdo);
    $dup=$pdo->prepare("SELECT apply_uuid,status,public_revision_after FROM p50_metric_publication_applies WHERE dispatch_id=? LIMIT 1");
    $dup->execute([$dispatchId]);
    if($existing=$dup->fetch()){
        if((string)$existing['status']==='applied'){
            return [
                'ok'=>true,'idempotent'=>true,'status'=>'applied',
                'applyUuid'=>(string)$existing['apply_uuid'],
                'publicStateRevision'=>(int)$existing['public_revision_after'],
                'publicStateWrites'=>1,
            ];
        }
        throw new RuntimeException('Ce dispatchId a déjà échoué. Relancez avec un nouvel identifiant.');
    }

    if((int)p50_metrics_value($pdo,"SELECT GET_LOCK(?,10)",[P50_MRP_APPLY_LOCK])!==1){
        throw new RuntimeException('Une publication de classement est déjà en cours.');
    }

    $applyUuid=p50_mr_uuid();
    try{
        $prior=p50_mrp_apply_has_prior_success($pdo);
        $bootstrap=($forceBootstrap||!$prior)&&$cfg['bootstrapAllowed'];
        if($mode==='automatic'&&$forceBootstrap&&!$cfg['bootstrapAllowed']){
            throw new RuntimeException('Bootstrap automatique refusé.');
        }

        // forceBootstrap doit assouplir exit/entry/movement dans la preview, sinon la recovery reste bloquée.
        $preview=p50_mrp_apply_preview($pdo,P50_MRP_APPLY_PERIODS,$now,$forceBootstrap);
        $reasons=(array)($preview['summary']['reasons']??[]);
        $blockedPeriods=(array)($preview['summary']['blockedPeriods']??[]);
        if(($preview['status']??'')==='blocked'||empty($preview['runUuid'])||empty($preview['publicationEligible'])){
            throw new RuntimeException('Garde-fous de publication non satisfaits: '.implode(',', $reasons?:($blockedPeriods?:['blocked'])));
        }
        if((int)($preview['summary']['mutations']??0)<=0)throw new RuntimeException('Aucune mutation de score à publier.');

        $runUuid=(string)$preview['runUuid'];
        $plans=(array)($preview['publishPlans']??[]);
        if(!$plans)throw new RuntimeException('Aucune période publiable.');
        $anchorPeriod=(string)(array_key_exists('24H',$plans)?'24H':array_key_first($plans));
        $anchor=$plans[$anchorPeriod];
        $fingerprintBefore=(string)($anchor['publicFingerprint']??'');
        $candidateFp=(string)($anchor['candidateFingerprint']??'');
        $revisionBefore=(int)($anchor['publicStateRevision']??0);

        $pdo->beginTransaction();
        $state=p50_de_load_public_state_for_update();
        if(!$state)throw new RuntimeException('État public introuvable.');
        $currentRevision=(int)($state['stateRevision']??0);
        if($currentRevision!==$revisionBefore){
            throw new RuntimeException('Révision publique changée pendant la publication ('.$currentRevision.' ≠ '.$revisionBefore.').');
        }
        $publicNow=p50_mrp_public_rows($state,$anchorPeriod);
        $fpNow=p50_mrp_fingerprint(['period'=>$anchorPeriod,'stateRevision'=>$currentRevision,'rows'=>$publicNow['rows']]);
        if($fingerprintBefore===''||!hash_equals($fingerprintBefore,$fpNow)){
            throw new RuntimeException('Empreinte publique incohérente au moment de l’écriture.');
        }

        $backupJson=p50_mr_json($state);
        $mutated=p50_mrp_apply_mutate_state($state,$plans,$runUuid);
        $newState=$mutated['state'];
        $newState['stateRevision']=$currentRevision+1;
        $stateActor=p50_mrp_apply_state_actor($appliedBy);
        $stmt=$pdo->prepare("UPDATE app_state SET data=?,updated_by=?,updated_at=NOW() WHERE id='public'");
        $stmt->execute([json_encode($newState,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$stateActor]);
        if($stmt->rowCount()===0){
            $ins=$pdo->prepare("INSERT INTO app_state(id,data,updated_by) VALUES('public',?,?)");
            $ins->execute([json_encode($newState,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$stateActor]);
        }

        $report=[
            'version'=>P50_MRP_APPLY_VERSION,'mode'=>$mode,'bootstrap'=>$bootstrap,
            'runUuid'=>$runUuid,'periods'=>array_keys($plans),'skippedPeriods'=>(array)($preview['summary']['skippedPeriods']??[]),
            'summary'=>$preview['summary'],'anchorPeriod'=>$anchorPeriod,
        ];
        $insert=$pdo->prepare("INSERT INTO p50_metric_publication_applies(
            apply_uuid,dispatch_id,mode,status,algorithm_version,run_uuid,periods_json,
            public_revision_before,public_revision_after,public_fingerprint_before,candidate_fingerprint,
            profiles_updated,scores_written,entries_count,exits_count,bootstrap,backup_json,report_json,applied_by,generated_at
          ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $insert->execute([
            $applyUuid,$dispatchId,$bootstrap?'bootstrap':$mode,'applied',P50_MR_ALGORITHM_VERSION,$runUuid,
            p50_mr_json(array_keys($plans)),$revisionBefore,$currentRevision+1,$fingerprintBefore,$candidateFp,
            (int)$mutated['profilesUpdated'],(int)$mutated['scoresWritten'],
            (int)($preview['summary']['entries']??0),(int)($preview['summary']['exits']??0),
            $bootstrap?1:0,$backupJson,p50_mr_json($report),mb_substr($appliedBy,0,120),
            $now->format('Y-m-d H:i:s'),
        ]);
        $pdo->commit();

        // Snapshot ranking history (best-effort, hors transaction critique)
        try{p50_de_capture_snapshots('2H');}catch(Throwable){}

        return [
            'ok'=>true,
            'version'=>P50_MRP_APPLY_VERSION,
            'status'=>'applied',
            'mode'=>$bootstrap?'bootstrap':$mode,
            'bootstrap'=>$bootstrap,
            'applyUuid'=>$applyUuid,
            'dispatchId'=>$dispatchId,
            'runUuid'=>$runUuid,
            'algorithmVersion'=>P50_MR_ALGORITHM_VERSION,
            'periods'=>array_keys($plans),
            'skippedPeriods'=>(array)($preview['summary']['skippedPeriods']??[]),
            'publicStateRevision'=>$currentRevision+1,
            'publicStateWrites'=>1,
            'profilesUpdated'=>(int)$mutated['profilesUpdated'],
            'scoresWritten'=>(int)$mutated['scoresWritten'],
            'entries'=>(int)($preview['summary']['entries']??0),
            'exits'=>(int)($preview['summary']['exits']??0),
            'backupCreated'=>true,
            'rollbackAvailable'=>true,
            'generatedAt'=>$now->format(DATE_ATOM),
        ];
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        try{
            $pdo->prepare("INSERT INTO p50_metric_publication_applies(
                apply_uuid,dispatch_id,mode,status,algorithm_version,run_uuid,periods_json,
                public_revision_before,public_revision_after,public_fingerprint_before,candidate_fingerprint,
                profiles_updated,scores_written,entries_count,exits_count,bootstrap,backup_json,report_json,applied_by,error_message,generated_at
              ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                $applyUuid,$dispatchId,$mode,'failed',P50_MR_ALGORITHM_VERSION,'00000000-0000-4000-8000-000000000000',
                p50_mr_json([]),0,0,str_repeat('0',64),str_repeat('0',64),0,0,0,0,0,'{}','{}',
                mb_substr($appliedBy,0,120),p50_mr_safe_error($error),$now->format('Y-m-d H:i:s'),
            ]);
        }catch(Throwable){}
        throw $error;
    }finally{
        try{p50_metrics_value($pdo,"SELECT RELEASE_LOCK(?)",[P50_MRP_APPLY_LOCK]);}catch(Throwable){}
    }
}

function p50_mrp_apply_rollback(PDO $pdo,string $applyUuid,string $appliedBy=''): array {
    $cfg=p50_mrp_apply_config();
    if(!$cfg['publicationEnabled'])throw new RuntimeException('Publication désactivée.');
    p50_mrp_apply_ensure_schema($pdo);
    $stmt=$pdo->prepare("SELECT * FROM p50_metric_publication_applies WHERE apply_uuid=? AND status='applied' LIMIT 1");
    $stmt->execute([trim($applyUuid)]);
    $row=$stmt->fetch();
    if(!$row)throw new InvalidArgumentException('Publication introuvable pour rollback.');
    $backup=json_decode((string)$row['backup_json'],true);
    if(!is_array($backup)||empty($backup['profiles']))throw new RuntimeException('Backup invalide.');
    if((int)p50_metrics_value($pdo,"SELECT GET_LOCK(?,10)",[P50_MRP_APPLY_LOCK])!==1){
        throw new RuntimeException('Rollback impossible : publication en cours.');
    }
    try{
        $pdo->beginTransaction();
        $state=p50_de_load_public_state_for_update();
        $currentRevision=(int)($state['stateRevision']??0);
        if($currentRevision!==(int)$row['public_revision_after']){
            throw new RuntimeException('Rollback refusé : d’autres écritures publiques sont intervenues.');
        }
        $backup['stateRevision']=$currentRevision+1;
        $pdo->prepare("UPDATE app_state SET data=?,updated_by=?,updated_at=NOW() WHERE id='public'")
            ->execute([json_encode($backup,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),p50_mrp_apply_state_actor($appliedBy)]);
        $pdo->prepare("UPDATE p50_metric_publication_applies SET status='rolled_back' WHERE id=?")->execute([(int)$row['id']]);
        $pdo->commit();
        return ['ok'=>true,'status'=>'rolled_back','applyUuid'=>$applyUuid,'publicStateRevision'=>$currentRevision+1,'publicStateWrites'=>1];
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $error;
    }finally{
        try{p50_metrics_value($pdo,"SELECT RELEASE_LOCK(?)",[P50_MRP_APPLY_LOCK]);}catch(Throwable){}
    }
}
