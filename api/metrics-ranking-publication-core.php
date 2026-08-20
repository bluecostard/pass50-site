<?php
declare(strict_types=1);

require_once __DIR__.'/metrics-ranking-core.php';
require_once __DIR__.'/profile-tombstone-core.php';

const P50_MRP_SIMULATION_VERSION='PUBSIM-V1.1';
const P50_MRP_MAX_RUN_AGE_HOURS=6;

function p50_mrp_period(string $period): string {
    return array_key_exists($period,p50_mr_periods())?$period:'2H';
}

function p50_mrp_canonicalize(mixed $value): mixed {
    if(!is_array($value))return $value;
    if(array_is_list($value))return array_map('p50_mrp_canonicalize',$value);
    ksort($value,SORT_STRING);
    foreach($value as $key=>$item)$value[$key]=p50_mrp_canonicalize($item);
    return $value;
}

function p50_mrp_fingerprint(array $value): string {
    return hash('sha256',json_encode(p50_mrp_canonicalize($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}

function p50_mrp_public_state(PDO $pdo): array {
    $stmt=$pdo->query("SELECT data,updated_at FROM app_state WHERE id='public' LIMIT 1");
    $row=$stmt->fetch();
    if(!$row)return ['state'=>[],'updatedAt'=>null,'exists'=>false];
    $state=json_decode((string)$row['data'],true);
    return ['state'=>is_array($state)?$state:[],'updatedAt'=>$row['updated_at']??null,'exists'=>is_array($state)];
}

function p50_mrp_public_profile_index(array $state): array {
    $profiles=[];$duplicates=[];$invalid=0;
    foreach((array)($state['profiles']??[]) as $profile){
        if(!is_array($profile)){$invalid++;continue;}
        $profileId=trim((string)($profile['id']??''));
        if($profileId===''){$invalid++;continue;}
        if(isset($profiles[$profileId])){$duplicates[$profileId]=true;continue;}
        $profiles[$profileId]=$profile;
    }
    return ['profiles'=>$profiles,'duplicateIds'=>array_keys($duplicates),'invalidCount'=>$invalid];
}

function p50_mrp_public_rows(array $state,string $period): array {
    $period=p50_mrp_period($period);$profileIndex=p50_mrp_public_profile_index($state);$rankable=[];
    $tombstoned=array_fill_keys(p50_tombstone_ids($state),true);
    foreach($profileIndex['profiles'] as $profileId=>$profile){
        if(isset($tombstoned[p50_normalize_profile_id($profileId)]))continue;
        if(array_key_exists('alive',$profile)&&empty($profile['alive']))continue;
        $score=$profile['scores'][$period]??null;
        if(!is_int($score)&&!is_float($score)&&!is_numeric($score))continue;
        if((float)$score<=0)continue;
        $rankable[]=[
            'profileId'=>$profileId,'name'=>(string)($profile['name']??$profileId),
            'handle'=>(string)($profile['handle']??''),'region'=>(string)($profile['region']??''),
            'score'=>(float)$score,
        ];
    }
    usort($rankable,static fn($a,$b)=>$b['score']<=>$a['score']?:strcmp($a['name'],$b['name'])?:strcmp($a['profileId'],$b['profileId']));
    foreach($rankable as $position=>$row)$rankable[$position]['rank']=$position+1;
    return ['rows'=>$rankable,'profileIndex'=>$profileIndex['profiles'],'duplicateIds'=>$profileIndex['duplicateIds'],'invalidCount'=>$profileIndex['invalidCount']];
}

function p50_mrp_latest_successful_run(PDO $pdo,string $period): ?array {
    if(!p50_metrics_table_exists($pdo,'p50_metric_ranking_runs'))return null;
    $stmt=$pdo->prepare("SELECT run_uuid,algorithm_version,trigger_type,periods_json,finished_at
        FROM p50_metric_ranking_runs WHERE algorithm_version=? AND status='success' AND finished_at IS NOT NULL
        ORDER BY finished_at DESC,id DESC LIMIT 50");
    $stmt->execute([P50_MR_ALGORITHM_VERSION]);
    foreach($stmt->fetchAll() as $run){
        $periods=json_decode((string)$run['periods_json'],true)?:[];
        if(in_array($period,$periods,true))return [
            'runUuid'=>(string)$run['run_uuid'],'algorithmVersion'=>(string)$run['algorithm_version'],
            'triggerType'=>(string)$run['trigger_type'],'finishedAt'=>(string)$run['finished_at'],
        ];
    }
    return null;
}

function p50_mrp_experimental_rows(PDO $pdo,string $period): array {
    $period=p50_mrp_period($period);
    if(!p50_metrics_table_exists($pdo,'p50_metric_ranking_current'))return ['rows'=>[],'runUuids'=>[],'duplicateIds'=>[],'duplicateRanks'=>[]];
    $registryAvailable=p50_metrics_table_exists($pdo,'p50_profile_registry');
    $identity=$registryAvailable?"r.public_name,r.handle,r.region":"c.profile_id public_name,'' handle,'' region";
    $join=$registryAvailable?'LEFT JOIN p50_profile_registry r ON r.profile_id=c.profile_id':'';
    $runColumn=p50_metrics_column_exists($pdo,'p50_metric_ranking_current','run_uuid')?'c.run_uuid':"'' run_uuid";
    $stmt=$pdo->prepare("SELECT c.profile_id,$runColumn,c.rank_position,c.score,c.confidence,c.coverage,c.classable,c.exclusion_reasons_json,$identity
        FROM p50_metric_ranking_current c $join
        WHERE c.algorithm_version=? AND c.period_key=?
        ORDER BY c.classable DESC,c.rank_position IS NULL,c.rank_position,c.score DESC,c.profile_id");
    $stmt->execute([P50_MR_ALGORITHM_VERSION,$period]);$rows=[];$seenIds=[];$seenRanks=[];$seenRunUuids=[];$duplicateIds=[];$duplicateRanks=[];
    foreach($stmt->fetchAll() as $row){
        $profileId=(string)$row['profile_id'];$runUuid=trim((string)($row['run_uuid']??''));
        if($runUuid!=='')$seenRunUuids[$runUuid]=true;
        if(isset($seenIds[$profileId]))$duplicateIds[$profileId]=true;
        $seenIds[$profileId]=true;
        $rank=$row['rank_position']===null?null:(int)$row['rank_position'];
        if((bool)$row['classable']&&$rank!==null){
            if(isset($seenRanks[$rank]))$duplicateRanks[$rank]=true;
            $seenRanks[$rank]=true;
        }
        $rows[]=[
            'profileId'=>$profileId,'runUuid'=>$runUuid,'name'=>(string)($row['public_name']??$profileId),'handle'=>(string)($row['handle']??''),
            'region'=>(string)($row['region']??''),'rank'=>$rank,'score'=>$row['score']===null?null:(float)$row['score'],
            'confidence'=>(float)$row['confidence'],'coverage'=>(float)$row['coverage'],'classable'=>(bool)$row['classable'],
            'exclusionReasons'=>json_decode((string)$row['exclusion_reasons_json'],true)?:[],
        ];
    }
    return ['rows'=>$rows,'runUuids'=>array_keys($seenRunUuids),'duplicateIds'=>array_keys($duplicateIds),'duplicateRanks'=>array_keys($duplicateRanks)];
}

function p50_mrp_median(array $values): ?float {
    $values=array_values(array_filter($values,static fn($value)=>is_int($value)||is_float($value)||is_numeric($value)));
    if(!$values)return null;sort($values,SORT_NUMERIC);$middle=intdiv(count($values),2);
    return count($values)%2?$values[$middle]:($values[$middle-1]+$values[$middle])/2;
}

function p50_mrp_spearman(array $pairs): ?float {
    $count=count($pairs);if($count<3)return null;$sum=0.0;
    foreach($pairs as $pair){$difference=(float)$pair[0]-(float)$pair[1];$sum+=$difference*$difference;}
    $denominator=$count*($count*$count-1);if($denominator===0)return null;
    return max(-1.0,min(1.0,1-(6*$sum/$denominator)));
}

function p50_mrp_compare(array $publicRows,array $experimentalRows): array {
    $candidateRows=array_values(array_filter($experimentalRows,static function($row){
        $score=$row['score']??null;
        return (is_int($score)||is_float($score)||is_numeric($score))&&(float)$score>0;
    }));
    usort($candidateRows,static function($a,$b){
        $ar=$a['rank']??null;$br=$b['rank']??null;
        if($ar!==null&&$br!==null)return (int)$ar<=>(int)$br?:strcmp((string)$a['profileId'],(string)$b['profileId']);
        if($ar!==null)return -1;
        if($br!==null)return 1;
        return ((float)$b['score']<=>(float)$a['score'])?:strcmp((string)$a['profileId'],(string)$b['profileId']);
    });
    foreach($candidateRows as $index=>&$row)$row['rank']=$index+1;
    unset($row);
    $public=[];foreach($publicRows as $row)$public[(string)$row['profileId']]=$row;
    $candidate=[];foreach($candidateRows as $row)$candidate[(string)$row['profileId']]=$row;
    $profileIds=array_values(array_unique([...array_keys($public),...array_keys($candidate)]));
    $movements=[];$counts=['entries'=>0,'exits'=>0,'up'=>0,'down'=>0,'stable'=>0];$absolute=[];$pairs=[];$scoreChanges=[];
    foreach($profileIds as $profileId){
        $before=$public[$profileId]??null;$after=$candidate[$profileId]??null;
        if($before===null){$type='entry';$counts['entries']++;}
        elseif($after===null){$type='exit';$counts['exits']++;}
        else{
            $delta=(int)$before['rank']-(int)$after['rank'];
            if($delta>0){$type='up';$counts['up']++;}
            elseif($delta<0){$type='down';$counts['down']++;}
            else{$type='stable';$counts['stable']++;}
            $absolute[]=abs($delta);$pairs[]=[(int)$before['rank'],(int)$after['rank']];
            $scoreChanges[]=abs((float)$after['score']-(float)$before['score']);
        }
        $rankDelta=$before!==null&&$after!==null?(int)$before['rank']-(int)$after['rank']:null;
        $movements[]=[
            'profileId'=>$profileId,'name'=>(string)($after['name']??$before['name']??$profileId),'type'=>$type,
            'publicRank'=>$before['rank']??null,'candidateRank'=>$after['rank']??null,'rankDelta'=>$rankDelta,
            'publicScore'=>$before['score']??null,'candidateScore'=>$after['score']??null,
            'scoreDelta'=>$before!==null&&$after!==null?(float)$after['score']-(float)$before['score']:null,
            'confidence'=>$after['confidence']??null,'coverage'=>$after['coverage']??null,
        ];
    }
    usort($movements,static function($a,$b){
        $ar=$a['candidateRank']??PHP_INT_MAX;$br=$b['candidateRank']??PHP_INT_MAX;
        return $ar<=>$br?:($a['publicRank']??PHP_INT_MAX)<=>($b['publicRank']??PHP_INT_MAX)?:strcmp($a['profileId'],$b['profileId']);
    });
    $publicTop10=array_fill_keys(array_map(static fn($row)=>(string)$row['profileId'],array_slice($publicRows,0,10)),true);
    $candidateTop10=array_fill_keys(array_map(static fn($row)=>(string)$row['profileId'],array_slice($candidateRows,0,10)),true);
    $publicTop50=array_fill_keys(array_map(static fn($row)=>(string)$row['profileId'],array_slice($publicRows,0,50)),true);
    $candidateTop50=array_fill_keys(array_map(static fn($row)=>(string)$row['profileId'],array_slice($candidateRows,0,50)),true);
    return [
        'publicCount'=>count($publicRows),'candidateCount'=>count($candidateRows),'commonCount'=>count(array_intersect_key($public,$candidate)),
        'counts'=>$counts,'top10Retention'=>$publicTop10?count(array_intersect_key($publicTop10,$candidateTop10))/count($publicTop10)*100:null,
        'top50Retention'=>$publicTop50?count(array_intersect_key($publicTop50,$candidateTop50))/count($publicTop50)*100:null,
        'medianAbsoluteRankMovement'=>p50_mrp_median($absolute),'maximumAbsoluteRankMovement'=>$absolute?max($absolute):null,
        'medianAbsoluteScoreChange'=>p50_mrp_median($scoreChanges),'spearman'=>p50_mrp_spearman($pairs),
        'movements'=>$movements,'candidateRows'=>$candidateRows,
    ];
}

function p50_mrp_gate(string $key,string $status,string $message,mixed $value=null): array {
    return ['key'=>$key,'status'=>$status,'message'=>$message,'value'=>$value];
}

function p50_mrp_simulate(PDO $pdo,string $period='2H',int $limit=200,?DateTimeImmutable $now=null): array {
    $period=p50_mrp_period($period);$limit=max(1,min(500,$limit));$now=$now??p50_metrics_now_utc();
    $publicEnvelope=p50_mrp_public_state($pdo);$state=$publicEnvelope['state'];$public=p50_mrp_public_rows($state,$period);
    $experimental=p50_mrp_experimental_rows($pdo,$period);$latestRun=p50_mrp_latest_successful_run($pdo,$period);
    $tombstoned=array_fill_keys(p50_tombstone_ids($state),true);
    $experimental['rows']=array_values(array_filter($experimental['rows'],static fn($row)=>!isset($tombstoned[p50_normalize_profile_id($row['profileId']??'')])));
    $comparison=p50_mrp_compare($public['rows'],$experimental['rows']);
    $orphans=[];foreach($comparison['candidateRows'] as $row)if(!isset($public['profileIndex'][(string)$row['profileId']]))$orphans[]=(string)$row['profileId'];
    $runAgeHours=null;
    if($latestRun&&$latestRun['finishedAt']){
        $finishedAt=p50_metrics_parse_utc((string)$latestRun['finishedAt']);
        if($finishedAt)$runAgeHours=max(0,($now->getTimestamp()-$finishedAt->getTimestamp())/3600);
    }
    $exitRatio=$comparison['publicCount']?$comparison['counts']['exits']/$comparison['publicCount']*100:0.0;
    $entryRatio=$comparison['publicCount']?$comparison['counts']['entries']/$comparison['publicCount']*100:0.0;
    $gates=[
        p50_mrp_gate('public_state',$publicEnvelope['exists']&&count($public['profileIndex'])>0?'pass':'block','État public lisible et non vide.',count($public['profileIndex'])),
        p50_mrp_gate('public_profile_ids',!$public['duplicateIds']?'pass':'block','Identifiants publics uniques.',$public['duplicateIds']),
        p50_mrp_gate('public_ranking_non_empty',$comparison['publicCount']>0?'pass':'block','Le classement public contient au moins un profil classable.',$comparison['publicCount']),
        p50_mrp_gate('experimental_profile_ids',!$experimental['duplicateIds']?'pass':'block','Identifiants expérimentaux uniques.',$experimental['duplicateIds']),
        p50_mrp_gate('experimental_ranks',!$experimental['duplicateRanks']?'pass':'block','Rangs expérimentaux uniques.',$experimental['duplicateRanks']),
        p50_mrp_gate('successful_run',$latestRun!==null?'pass':'block','Un cycle MR-V1.0 réussi couvre la période.',$latestRun['runUuid']??null),
        p50_mrp_gate('candidate_run_consistency',$latestRun!==null&&count($experimental['runUuids'])===1&&$experimental['runUuids'][0]===($latestRun['runUuid']??null)?'pass':'block','Toutes les lignes candidates proviennent du dernier cycle réussi.',$experimental['runUuids']),
        p50_mrp_gate('candidate_non_empty',$comparison['candidateCount']>0?'pass':'block','Le candidat contient au moins un profil classable.',$comparison['candidateCount']),
        // Warn (pas block) : l’apply ignore déjà les profils absents de app_state.
        // Un block ici refusait toute publication dès qu’un score expérimental orphelin apparaissait.
        p50_mrp_gate('candidate_profiles_exist',!$orphans?'pass':'warn','Profils candidats absents de app_state (ignorés à la publication).',$orphans),
        p50_mrp_gate('run_freshness',$runAgeHours!==null&&$runAgeHours<=P50_MRP_MAX_RUN_AGE_HOURS?'pass':'block','Le calcul expérimental a moins de six heures.',$runAgeHours),
        p50_mrp_gate('exit_ratio',$exitRatio<=20?'pass':'warn','Part des sorties par rapport au classement public.',$exitRatio),
        p50_mrp_gate('entry_ratio',$entryRatio<=20?'pass':'warn','Part des entrées par rapport au classement public.',$entryRatio),
        p50_mrp_gate('maximum_rank_movement',($comparison['maximumAbsoluteRankMovement']??0)<=20?'pass':'warn','Mouvement de rang maximal observé.',$comparison['maximumAbsoluteRankMovement']),
    ];
    $blocked=(bool)array_filter($gates,static fn($gate)=>$gate['status']==='block');
    $warnings=(bool)array_filter($gates,static fn($gate)=>$gate['status']==='warn');
    $publicFingerprint=p50_mrp_fingerprint(['period'=>$period,'stateRevision'=>(int)($state['stateRevision']??0),'rows'=>$public['rows']]);
    $candidateFingerprint=p50_mrp_fingerprint(['period'=>$period,'algorithmVersion'=>P50_MR_ALGORITHM_VERSION,'runUuid'=>$latestRun['runUuid']??null,'rows'=>$comparison['candidateRows']]);
    return [
        'simulationVersion'=>P50_MRP_SIMULATION_VERSION,'algorithmVersion'=>P50_MR_ALGORITHM_VERSION,'selectedPeriod'=>$period,
        'generatedAt'=>$now->format(DATE_ATOM),'status'=>$blocked?'blocked':($warnings?'review':'ready'),
        'publication'=>[
            'mode'=>'simulation','publicationEnabled'=>false,'automaticPublicationEnabled'=>false,
            'appStateWriteAttempted'=>false,'backupCreated'=>false,'rollbackAvailable'=>false,
            'nextPhase'=>'controlled_publication_with_backup_and_rollback',
        ],
        'source'=>[
            'publicStateRevision'=>(int)($state['stateRevision']??0),'publicUpdatedAt'=>$publicEnvelope['updatedAt'],
            'publicFingerprint'=>$publicFingerprint,'experimentalRun'=>$latestRun,'candidateFingerprint'=>$candidateFingerprint,
        ],
        'scope'=>[
            'selectedPeriodOnly'=>true,'publicProfileMetadataChanges'=>0,'publicStateWrites'=>0,
            'candidateDerivedFromScoredExperimentalRows'=>true,
        ],
        'summary'=>array_diff_key($comparison,['movements'=>true,'candidateRows'=>true]),
        'gates'=>$gates,'orphanCandidateProfileIds'=>$orphans,
        'movements'=>array_slice($comparison['movements'],0,$limit),
        'candidateTop'=>array_slice($comparison['candidateRows'],0,min($limit,100)),
        'limitations'=>[
            'noBackupInSimulation'=>true,'noRollbackInSimulation'=>true,'noPeriodicPublication'=>true,
            'publicRankingUsesServerScoreEligibility'=>true,
        ],
    ];
}
