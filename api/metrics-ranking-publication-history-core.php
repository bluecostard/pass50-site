<?php
declare(strict_types=1);

require_once __DIR__.'/metrics-ranking-publication-core.php';

const P50_MRPH_HISTORY_VERSION='PUBHIST-V1.0';
const P50_MRPH_MIN_DISTINCT_CYCLES=3;
const P50_MRPH_MAX_LATEST_AGE_HOURS=6;

function p50_mrph_schema_sql(): string {
    return "CREATE TABLE IF NOT EXISTS p50_metric_publication_simulations (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      simulation_uuid CHAR(36) CHARACTER SET ascii NOT NULL,
      dispatch_id VARCHAR(120) CHARACTER SET ascii NOT NULL,
      history_version VARCHAR(24) NOT NULL,simulation_version VARCHAR(24) NOT NULL,
      algorithm_version VARCHAR(24) NOT NULL,period_key VARCHAR(8) NOT NULL,status VARCHAR(24) NOT NULL,
      experimental_run_uuid CHAR(36) CHARACTER SET ascii NULL,public_state_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
      public_fingerprint CHAR(64) CHARACTER SET ascii NOT NULL,candidate_fingerprint CHAR(64) CHARACTER SET ascii NOT NULL,
      public_count INT UNSIGNED NOT NULL DEFAULT 0,candidate_count INT UNSIGNED NOT NULL DEFAULT 0,
      entries_count INT UNSIGNED NOT NULL DEFAULT 0,exits_count INT UNSIGNED NOT NULL DEFAULT 0,
      up_count INT UNSIGNED NOT NULL DEFAULT 0,down_count INT UNSIGNED NOT NULL DEFAULT 0,stable_count INT UNSIGNED NOT NULL DEFAULT 0,
      blocked_gate_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,warning_gate_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      public_state_writes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      median_rank_movement DECIMAL(10,3) NULL,maximum_rank_movement INT UNSIGNED NULL,top10_retention DECIMAL(7,3) NULL,
      report_json LONGTEXT NOT NULL,generated_at DATETIME NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_p50_mrph_simulation_uuid(simulation_uuid),UNIQUE KEY uq_p50_mrph_dispatch_id(dispatch_id),
      INDEX idx_p50_mrph_period_created(period_key,created_at),INDEX idx_p50_mrph_status_created(status,created_at),
      INDEX idx_p50_mrph_run(experimental_run_uuid),INDEX idx_p50_mrph_public_revision(public_state_revision,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}

function p50_mrph_ensure_schema(PDO $pdo): array {
    $pdo->exec(p50_mrph_schema_sql());
    return ['status'=>p50_metrics_table_exists($pdo,'p50_metric_publication_simulations')?'applied':'missing','table'=>'p50_metric_publication_simulations'];
}

function p50_mrph_assert_read_only_report(array $report): void {
    $publication=(array)($report['publication']??[]);$scope=(array)($report['scope']??[]);
    if(($publication['mode']??null)!=='simulation')throw new InvalidArgumentException('Rapport hors mode simulation.');
    if(!empty($publication['publicationEnabled'])||!empty($publication['automaticPublicationEnabled'])||!empty($publication['appStateWriteAttempted']))throw new InvalidArgumentException('Rapport autorisant une publication.');
    if((int)($scope['publicStateWrites']??-1)!==0)throw new InvalidArgumentException('Rapport avec écriture publique.');
}

function p50_mrph_report_values(array $report): array {
    p50_mrph_assert_read_only_report($report);
    $summary=(array)($report['summary']??[]);$counts=(array)($summary['counts']??[]);$source=(array)($report['source']??[]);$scope=(array)($report['scope']??[]);
    $gates=(array)($report['gates']??[]);$blocked=0;$warnings=0;
    foreach($gates as $gate){
        if(!is_array($gate))continue;
        if(($gate['status']??null)==='block')$blocked++;
        elseif(($gate['status']??null)==='warn')$warnings++;
    }
    $run=(array)($source['experimentalRun']??[]);
    return [
        'simulationVersion'=>(string)($report['simulationVersion']??''),'algorithmVersion'=>(string)($report['algorithmVersion']??''),
        'period'=>(string)($report['selectedPeriod']??''),'status'=>(string)($report['status']??'blocked'),
        'experimentalRunUuid'=>trim((string)($run['runUuid']??''))?:null,
        'publicStateRevision'=>max(0,(int)($source['publicStateRevision']??0)),
        'publicFingerprint'=>(string)($source['publicFingerprint']??''),'candidateFingerprint'=>(string)($source['candidateFingerprint']??''),
        'publicCount'=>max(0,(int)($summary['publicCount']??0)),'candidateCount'=>max(0,(int)($summary['candidateCount']??0)),
        'entries'=>max(0,(int)($counts['entries']??0)),'exits'=>max(0,(int)($counts['exits']??0)),
        'up'=>max(0,(int)($counts['up']??0)),'down'=>max(0,(int)($counts['down']??0)),'stable'=>max(0,(int)($counts['stable']??0)),
        'blockedGates'=>$blocked,'warningGates'=>$warnings,'publicStateWrites'=>max(0,(int)($scope['publicStateWrites']??0)),
        'medianMovement'=>isset($summary['medianAbsoluteRankMovement'])&&is_numeric($summary['medianAbsoluteRankMovement'])?(float)$summary['medianAbsoluteRankMovement']:null,
        'maximumMovement'=>isset($summary['maximumAbsoluteRankMovement'])&&is_numeric($summary['maximumAbsoluteRankMovement'])?max(0,(int)$summary['maximumAbsoluteRankMovement']):null,
        'top10Retention'=>isset($summary['top10Retention'])&&is_numeric($summary['top10Retention'])?(float)$summary['top10Retention']:null,
        'generatedAt'=>p50_metrics_timestamp((string)($report['generatedAt']??'now')),
    ];
}

function p50_mrph_store(PDO $pdo,array $report,string $dispatchId): array {
    $dispatchId=trim($dispatchId);
    if($dispatchId===''||strlen($dispatchId)>120||!preg_match('/^[A-Za-z0-9._-]+$/',$dispatchId))throw new InvalidArgumentException('dispatchId invalide.');
    p50_mrph_ensure_schema($pdo);$values=p50_mrph_report_values($report);
    if($values['simulationVersion']===''||$values['algorithmVersion']===''||!array_key_exists($values['period'],p50_mr_periods()))throw new InvalidArgumentException('Rapport de simulation incomplet.');
    foreach(['publicFingerprint','candidateFingerprint'] as $key)if(!preg_match('/^[a-f0-9]{64}$/',$values[$key]))throw new InvalidArgumentException('Empreinte de simulation invalide.');
    $simulationUuid=p50_mr_uuid();$reportJson=p50_mr_json($report);
    $sql="INSERT INTO p50_metric_publication_simulations(
      simulation_uuid,dispatch_id,history_version,simulation_version,algorithm_version,period_key,status,
      experimental_run_uuid,public_state_revision,public_fingerprint,candidate_fingerprint,
      public_count,candidate_count,entries_count,exits_count,up_count,down_count,stable_count,
      blocked_gate_count,warning_gate_count,public_state_writes,median_rank_movement,maximum_rank_movement,top10_retention,report_json,generated_at
    ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)";
    $stmt=$pdo->prepare($sql);
    $stmt->execute([
        $simulationUuid,$dispatchId,P50_MRPH_HISTORY_VERSION,$values['simulationVersion'],$values['algorithmVersion'],$values['period'],$values['status'],
        $values['experimentalRunUuid'],$values['publicStateRevision'],$values['publicFingerprint'],$values['candidateFingerprint'],
        $values['publicCount'],$values['candidateCount'],$values['entries'],$values['exits'],$values['up'],$values['down'],$values['stable'],
        $values['blockedGates'],$values['warningGates'],$values['publicStateWrites'],$values['medianMovement'],$values['maximumMovement'],$values['top10Retention'],$reportJson,$values['generatedAt'],
    ]);
    $id=(int)$pdo->lastInsertId();
    if($id<=0){$lookup=$pdo->prepare('SELECT id FROM p50_metric_publication_simulations WHERE dispatch_id=? LIMIT 1');$lookup->execute([$dispatchId]);$id=(int)$lookup->fetchColumn();}
    $lookup=$pdo->prepare('SELECT id,simulation_uuid,dispatch_id,period_key,status,experimental_run_uuid,public_state_revision,public_fingerprint,candidate_fingerprint,public_state_writes,generated_at,created_at FROM p50_metric_publication_simulations WHERE id=? LIMIT 1');
    $lookup->execute([$id]);$stored=$lookup->fetch();
    if(!$stored)throw new RuntimeException('Simulation non historisée.');
    if(!hash_equals((string)$stored['public_fingerprint'],$values['publicFingerprint'])||!hash_equals((string)$stored['candidate_fingerprint'],$values['candidateFingerprint']))throw new RuntimeException('Collision d’idempotence de simulation.');
    if((int)$stored['public_state_writes']!==0)throw new RuntimeException('Historique contenant une écriture publique.');
    return [
        'id'=>(int)$stored['id'],'simulationUuid'=>(string)$stored['simulation_uuid'],'dispatchId'=>(string)$stored['dispatch_id'],
        'period'=>(string)$stored['period_key'],'status'=>(string)$stored['status'],'experimentalRunUuid'=>$stored['experimental_run_uuid']?:null,
        'publicStateRevision'=>(int)$stored['public_state_revision'],'publicStateWrites'=>(int)$stored['public_state_writes'],
        'generatedAt'=>(string)$stored['generated_at'],'createdAt'=>(string)$stored['created_at'],
    ];
}

function p50_mrph_recent(PDO $pdo,string $period='2H',int $limit=24): array {
    p50_mrph_ensure_schema($pdo);$period=p50_mrp_period($period);$limit=max(1,min(100,$limit));
    $stmt=$pdo->prepare("SELECT id,simulation_uuid,dispatch_id,status,experimental_run_uuid,public_state_revision,public_fingerprint,candidate_fingerprint,
      public_count,candidate_count,entries_count,exits_count,up_count,down_count,stable_count,blocked_gate_count,warning_gate_count,public_state_writes,
      median_rank_movement,maximum_rank_movement,top10_retention,generated_at,created_at
      FROM p50_metric_publication_simulations WHERE period_key=? ORDER BY generated_at DESC,id DESC LIMIT $limit");
    $stmt->execute([$period]);$rows=[];
    foreach($stmt->fetchAll() as $row)$rows[]=[
        'id'=>(int)$row['id'],'simulationUuid'=>(string)$row['simulation_uuid'],'dispatchId'=>(string)$row['dispatch_id'],'status'=>(string)$row['status'],
        'experimentalRunUuid'=>$row['experimental_run_uuid']?:null,'publicStateRevision'=>(int)$row['public_state_revision'],
        'publicFingerprint'=>(string)$row['public_fingerprint'],'candidateFingerprint'=>(string)$row['candidate_fingerprint'],
        'publicCount'=>(int)$row['public_count'],'candidateCount'=>(int)$row['candidate_count'],
        'counts'=>['entries'=>(int)$row['entries_count'],'exits'=>(int)$row['exits_count'],'up'=>(int)$row['up_count'],'down'=>(int)$row['down_count'],'stable'=>(int)$row['stable_count']],
        'blockedGateCount'=>(int)$row['blocked_gate_count'],'warningGateCount'=>(int)$row['warning_gate_count'],'publicStateWrites'=>(int)$row['public_state_writes'],
        'medianRankMovement'=>$row['median_rank_movement']===null?null:(float)$row['median_rank_movement'],
        'maximumRankMovement'=>$row['maximum_rank_movement']===null?null:(int)$row['maximum_rank_movement'],
        'top10Retention'=>$row['top10_retention']===null?null:(float)$row['top10_retention'],
        'generatedAt'=>(string)$row['generated_at'],'createdAt'=>(string)$row['created_at'],
    ];
    return $rows;
}

function p50_mrph_distinct_recent(array $history,int $sampleSize): array {
    $sampleSize=max(1,min(24,$sampleSize));$selected=[];$seen=[];
    foreach($history as $row){
        if(!is_array($row))continue;
        $runUuid=trim((string)($row['experimentalRunUuid']??''));
        $key=$runUuid!==''?'run:'.$runUuid:'simulation:'.(string)($row['simulationUuid']??count($selected));
        if(isset($seen[$key]))continue;
        $seen[$key]=true;$selected[]=$row;
        if(count($selected)>=$sampleSize)break;
    }
    return $selected;
}

function p50_mrph_stability(PDO $pdo,string $period='2H',int $sampleSize=3,?DateTimeImmutable $now=null): array {
    $period=p50_mrp_period($period);$sampleSize=max(P50_MRPH_MIN_DISTINCT_CYCLES,min(24,$sampleSize));$now=$now??new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $history=p50_mrph_recent($pdo,$period,max(24,min(100,$sampleSize*8)));$rows=p50_mrph_distinct_recent($history,$sampleSize);
    $runUuids=[];$revisions=[];$publicFingerprints=[];$blockedReports=0;$reviewReports=0;$writeAnomalies=0;
    foreach($rows as $row){
        if($row['experimentalRunUuid'])$runUuids[(string)$row['experimentalRunUuid']]=true;
        $revisions[(string)$row['publicStateRevision']]=true;$publicFingerprints[(string)$row['publicFingerprint']]=true;
        if($row['status']==='blocked')$blockedReports++;elseif($row['status']==='review')$reviewReports++;
        if((int)$row['publicStateWrites']!==0)$writeAnomalies++;
    }
    $latestAgeHours=null;$latest=$history[0]??null;
    if($latest){$latestDate=new DateTimeImmutable((string)$latest['generatedAt'],new DateTimeZone('UTC'));$latestAgeHours=max(0,($now->getTimestamp()-$latestDate->getTimestamp())/3600);}
    $enoughReports=count($rows)>=$sampleSize;$enoughRuns=count($runUuids)>=P50_MRPH_MIN_DISTINCT_CYCLES;$publicStable=count($revisions)<=1&&count($publicFingerprints)<=1;
    $freshStatus=$latestAgeHours===null?'wait':($latestAgeHours<=P50_MRPH_MAX_LATEST_AGE_HOURS?'pass':'block');
    $gates=[
        ['key'=>'minimum_reports','status'=>$enoughReports?'pass':'wait','message'=>'Nombre minimal de recalculs distincts récents.','value'=>count($rows)],
        ['key'=>'distinct_experimental_runs','status'=>$enoughRuns?'pass':'wait','message'=>'Au moins trois recalculs MR-V1.0 distincts.','value'=>count($runUuids)],
        ['key'=>'public_baseline_stable','status'=>$publicStable?'pass':'wait','message'=>'Révision et empreinte publiques stables pendant l’observation.','value'=>['revisions'=>array_keys($revisions),'fingerprints'=>count($publicFingerprints)]],
        ['key'=>'no_blocked_reports','status'=>$blockedReports===0?'pass':'block','message'=>'Aucun rapport bloqué dans l’échantillon distinct.','value'=>$blockedReports],
        ['key'=>'latest_report_fresh','status'=>$freshStatus,'message'=>'Dernier rapport âgé de moins de six heures.','value'=>$latestAgeHours],
        ['key'=>'read_only_history','status'=>$writeAnomalies===0?'pass':'block','message'=>'Aucune anomalie d’écriture publique dans l’historique.','value'=>$writeAnomalies],
        ['key'=>'warnings','status'=>$reviewReports===0?'pass':'warn','message'=>'Rapports nécessitant une revue.','value'=>$reviewReports],
    ];
    $blocked=(bool)array_filter($gates,static fn($gate)=>$gate['status']==='block');$waiting=(bool)array_filter($gates,static fn($gate)=>$gate['status']==='wait');$warnings=(bool)array_filter($gates,static fn($gate)=>$gate['status']==='warn');
    $state=$blocked?'blocked':($waiting?'collecting':($warnings?'review':'ready'));
    $publishable=in_array($state,['ready','review'],true)&&!$blocked;
    global $config;$m=(array)(($config??[])['metrics']??[]);
    $automaticEnabled=filter_var($m['ranking_automatic_publication_enabled']??(getenv('PASS50_RANKING_AUTOMATIC_PUBLICATION_ENABLED')?:false),FILTER_VALIDATE_BOOLEAN);
    $publicationEnabled=filter_var($m['ranking_publication_enabled']??(getenv('PASS50_RANKING_PUBLICATION_ENABLED')?:false),FILTER_VALIDATE_BOOLEAN);
    return [
        'historyVersion'=>P50_MRPH_HISTORY_VERSION,'period'=>$period,'sampleSize'=>$sampleSize,'observedReports'=>count($rows),'rawObservedReports'=>count($history),'distinctExperimentalRuns'=>count($runUuids),
        'state'=>$state,'controlledPublicationEligible'=>$publishable,'automaticPublicationEligible'=>$publishable&&$publicationEnabled&&$automaticEnabled,
        'latestReportAgeHours'=>$latestAgeHours,'publicStateRevisions'=>array_map('intval',array_keys($revisions)),
        'latest'=>$latest,'gates'=>$gates,'recent'=>$rows,
    ];
}
