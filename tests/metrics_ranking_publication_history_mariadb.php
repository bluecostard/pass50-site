<?php
declare(strict_types=1);

require dirname(__DIR__).'/api/metrics-ranking-publication-history-core.php';

$dsn=getenv('P50_TEST_DSN')?:'mysql:host=127.0.0.1;port=3306;dbname=pass50_test;charset=utf8mb4';
$user=getenv('P50_TEST_DB_USER')?:'root';
$password=getenv('P50_TEST_DB_PASSWORD')?:'root';
$pdo=new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);

$pdo->exec('DROP TABLE IF EXISTS p50_metric_publication_simulations');
$pdo->exec('DROP TABLE IF EXISTS app_state');
$pdo->exec("CREATE TABLE app_state(id VARCHAR(32) PRIMARY KEY,data LONGTEXT NOT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
$pdo->prepare("INSERT INTO app_state(id,data) VALUES('public',?)")->execute(['{"stateRevision":7,"profiles":[]}']);

$report=static function(string $runUuid,string $generatedAt,string $status='ready',bool $blocked=false,int $publicWrites=0): array {
    return [
        'simulationVersion'=>P50_MRP_SIMULATION_VERSION,
        'algorithmVersion'=>P50_MR_ALGORITHM_VERSION,
        'selectedPeriod'=>'2H',
        'generatedAt'=>$generatedAt,
        'status'=>$status,
        'publication'=>[
            'mode'=>'simulation','publicationEnabled'=>false,'automaticPublicationEnabled'=>false,
            'appStateWriteAttempted'=>$publicWrites>0,'backupCreated'=>false,'rollbackAvailable'=>false,
        ],
        'source'=>[
            'publicStateRevision'=>7,'publicFingerprint'=>str_repeat('a',64),
            'candidateFingerprint'=>hash('sha256',$runUuid),
            'experimentalRun'=>['runUuid'=>$runUuid,'algorithmVersion'=>P50_MR_ALGORITHM_VERSION,'finishedAt'=>$generatedAt],
        ],
        'scope'=>['selectedPeriodOnly'=>true,'publicProfileMetadataChanges'=>0,'publicStateWrites'=>$publicWrites],
        'summary'=>[
            'publicCount'=>10,'candidateCount'=>10,'commonCount'=>10,
            'counts'=>['entries'=>0,'exits'=>0,'up'=>2,'down'=>2,'stable'=>6],
            'top10Retention'=>100.0,'top50Retention'=>100.0,
            'medianAbsoluteRankMovement'=>1.0,'maximumAbsoluteRankMovement'=>3,
            'medianAbsoluteScoreChange'=>1.2,'spearman'=>0.98,
        ],
        'gates'=>[
            ['key'=>'public_state','status'=>$blocked?'block':'pass','message'=>'État public lisible.','value'=>10],
        ],
        'orphanCandidateProfileIds'=>[],
        'movements'=>[],
        'candidateTop'=>[],
    ];
};

$assert=static function(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);};
$before=(string)$pdo->query("SELECT data FROM app_state WHERE id='public'")->fetchColumn();

$reports=[
    ['11111111-1111-4111-8111-111111111111','2026-07-30T08:00:00+00:00','dispatch-history-1'],
    ['22222222-2222-4222-8222-222222222222','2026-07-30T10:00:00+00:00','dispatch-history-2'],
    ['33333333-3333-4333-8333-333333333333','2026-07-30T12:00:00+00:00','dispatch-history-3'],
];
$stored=[];
foreach($reports as [$runUuid,$at,$dispatchId])$stored[]=p50_mrph_store($pdo,$report($runUuid,$at),$dispatchId);
$duplicate=p50_mrph_store($pdo,$report($reports[2][0],$reports[2][1]),$reports[2][2]);

$assert($stored[2]['id']===$duplicate['id'],'Le même dispatchId doit rester idempotent.');
$assert((int)$pdo->query('SELECT COUNT(*) FROM p50_metric_publication_simulations')->fetchColumn()===3,'Trois audits uniques attendus.');
$assert((int)$pdo->query('SELECT SUM(public_state_writes) FROM p50_metric_publication_simulations')->fetchColumn()===0,'Aucune écriture publique ne doit être historisée.');

$stability=p50_mrph_stability($pdo,'2H',3,new DateTimeImmutable('2026-07-30T12:05:00+00:00'));
$assert($stability['state']==='ready','Trois recalculs distincts et cohérents doivent être prêts.');
$assert($stability['controlledPublicationEligible']===true,'Le passage contrôlé doit devenir éligible.');
$assert($stability['automaticPublicationEligible']===false,'La publication automatique doit rester interdite.');
$assert($stability['distinctExperimentalRuns']===3,'Trois runUuid distincts attendus.');

$rejected=false;
try{p50_mrph_store($pdo,$report('44444444-4444-4444-8444-444444444444','2026-07-30T12:06:00+00:00','ready',false,1),'dispatch-write-refused');}
catch(InvalidArgumentException){$rejected=true;}
$assert($rejected,'Un rapport déclarant une écriture publique doit être refusé.');

p50_mrph_store($pdo,$report('55555555-5555-4555-8555-555555555555','2026-07-30T12:10:00+00:00','blocked',true),'dispatch-history-4');
$blocked=p50_mrph_stability($pdo,'2H',3,new DateTimeImmutable('2026-07-30T12:15:00+00:00'));
$assert($blocked['state']==='blocked','Un rapport bloqué récent doit bloquer la stabilité.');
$assert($blocked['controlledPublicationEligible']===false,'Le passage contrôlé doit être refusé après une anomalie.');

$after=(string)$pdo->query("SELECT data FROM app_state WHERE id='public'")->fetchColumn();
$assert($before===$after,'Le journal de simulation a modifié app_state.');

echo "Metrics ranking publication history MariaDB: OK\n";
