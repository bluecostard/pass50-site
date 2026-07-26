<?php
declare(strict_types=1);

require_once __DIR__.'/data-engine-core.php';
require_once __DIR__.'/radar-core.php';

const P50_INTELLIGENCE_WEIGHTS = [
    'views'=>35,
    'likes'=>20,
    'comments'=>20,
    'publications'=>15,
    'followers'=>10,
];

function p50_intelligence_ensure_schema(): void {
    static $done=false;
    if($done)return;
    p50_de_ensure_schema();
    p50_radar_ensure_schema();
    db()->exec("CREATE TABLE IF NOT EXISTS p50_intelligence_snapshots (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        profile_id VARCHAR(100) NOT NULL,
        growth_index TINYINT UNSIGNED NOT NULL,
        buzz_index TINYINT UNSIGNED NOT NULL,
        confidence_level VARCHAR(16) NOT NULL,
        main_signal VARCHAR(64) NOT NULL,
        metrics_json LONGTEXT NOT NULL,
        period_start DATETIME NOT NULL,
        period_end DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_p50_intelligence_period(profile_id,period_start,period_end),
        INDEX idx_p50_intelligence_created(created_at),
        INDEX idx_p50_intelligence_growth(growth_index,confidence_level),
        INDEX idx_p50_intelligence_buzz(buzz_index,confidence_level)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done=true;
}

function p50_intelligence_change(float $recent,float $previous): float {
    if($previous<=0.0)return $recent>0.0?200.0:0.0;
    return max(-100.0,min(200.0,(($recent-$previous)/$previous)*100.0));
}

function p50_intelligence_confidence(int $captureCount,int $metricCount,bool $recentData): string {
    if($captureCount>=4&&$metricCount>=3&&$recentData)return 'élevée';
    if($captureCount>=2&&$metricCount>=2)return 'moyenne';
    return 'faible';
}

/**
 * Fonction pure et testable. Chaque observation contient recent, previous et
 * éventuellement baseline (moyenne habituelle ramenée à 24 heures).
 */
function p50_intelligence_calculate(array $observations,int $captureCount,bool $recentData,array $context=[]): array {
    $weightedChange=0.0;$availableWeight=0.0;$variations=[];$metricCount=0;
    foreach(P50_INTELLIGENCE_WEIGHTS as $metric=>$weight){
        if(!isset($observations[$metric])||!array_key_exists('recent',$observations[$metric])||!array_key_exists('previous',$observations[$metric]))continue;
        $recent=(float)$observations[$metric]['recent'];$previous=(float)$observations[$metric]['previous'];
        $variation=p50_intelligence_change($recent,$previous);
        $variations[$metric]=$variation;$weightedChange+=$variation*$weight;$availableWeight+=$weight;$metricCount++;
    }
    $globalVariation=$availableWeight>0?$weightedChange/$availableWeight:0.0;
    $growth=(int)round(max(0.0,min(100.0,50.0+$globalVariation/2.0)));

    $buzzWeights=['comments'=>40,'likes'=>25,'views'=>20];
    $buzzWeighted=0.0;$buzzWeight=0.0;$acceleratingSignals=0;
    foreach($buzzWeights as $metric=>$weight){
        if(!isset($observations[$metric]['recent'],$observations[$metric]['baseline']))continue;
        $recent=(float)$observations[$metric]['recent'];$baseline=(float)$observations[$metric]['baseline'];
        $acceleration=$baseline<=0.0?($recent>0.0?2.0:0.0):$recent/$baseline;
        $score=max(0.0,min(100.0,($acceleration-1.0)*50.0));
        $buzzWeighted+=$score*$weight;$buzzWeight+=$weight;
        if($acceleration>=1.5&&$recent>0)$acceleratingSignals++;
    }
    $concentration=max(0.0,min(1.0,(float)($context['interactionConcentration']??0.0)));
    $platformCount=max(0,(int)($context['activePlatforms']??0));
    $buzzWeighted+=($concentration>=0.6?100.0:$concentration*100.0)*10.0;$buzzWeight+=10.0;
    if($platformCount>0){$buzzWeighted+=min(100.0,($platformCount-1)*50.0)*5.0;$buzzWeight+=5.0;}
    $buzz=$buzzWeight>0?(int)round($buzzWeighted/$buzzWeight):0;
    $recentInteractions=(float)($observations['views']['recent']??0)+(float)($observations['likes']['recent']??0)+(float)($observations['comments']['recent']??0);
    $commentVolume=(float)($observations['comments']['recent']??0);
    $enoughVolume=$recentInteractions>=100.0||$commentVolume>=10.0;
    if(!$enoughVolume||$acceleratingSignals<2)$buzz=min($buzz,69);

    $confidence=p50_intelligence_confidence($captureCount,$metricCount,$recentData);
    $labels=['views'=>'vues','likes'=>'likes','comments'=>'commentaires','publications'=>'publications','followers'=>'abonnés'];
    $mainMetric='';$mainVariation=0.0;
    foreach($variations as $metric=>$variation){
        if($mainMetric===''||abs($variation)>abs($mainVariation)){$mainMetric=$metric;$mainVariation=$variation;}
    }
    if($confidence==='faible')$explanation='Données insuffisantes pour une conclusion fiable.';
    elseif($buzz>=70)$explanation='Accélération inhabituelle de plusieurs métriques sur les dernières 24 heures.';
    elseif($globalVariation<=-20)$explanation='Ralentissement de l’activité et de l’engagement.';
    elseif($growth>=65)$explanation='Progression régulière sur plusieurs métriques.';
    else $explanation='Activité globalement stable sur les dernières 24 heures.';

    return [
        'growthIndex'=>$growth,
        'buzzIndex'=>max(0,min(100,$buzz)),
        'confidenceLevel'=>$confidence,
        'globalVariation'=>round($globalVariation,1),
        'mainSignal'=>$mainMetric!==''?$mainMetric:'insufficient_data',
        'mainVariation'=>$mainMetric!==''?round($mainVariation,1):null,
        'mainVariationLabel'=>$mainMetric!==''?sprintf('%s %+.1f %%',$labels[$mainMetric]??$mainMetric,$mainVariation):'Données insuffisantes',
        'metricCount'=>$metricCount,
        'captureCount'=>$captureCount,
        'variations'=>$variations,
        'explanation'=>$explanation,
    ];
}

function p50_intelligence_period(?DateTimeImmutable $now=null): array {
    $now=($now??new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    $hour=$now->setTime((int)$now->format('H'),0,0);
    $end=$hour->modify('+1 hour')->modify('-1 second');
    return [
        'start'=>$end->modify('-24 hours')->modify('+1 second'),
        'previousStart'=>$end->modify('-48 hours')->modify('+1 second'),
        'baselineStart'=>$end->modify('-7 days')->modify('+1 second'),
        'end'=>$end,
    ];
}

function p50_intelligence_profile_observations(string $profileId,array $period): array {
    $stmt=db()->prepare('SELECT platform,content_key,metric_deltas,captured_at FROM p50_radar_metric_captures WHERE profile_id=? AND captured_at>=? AND captured_at<=? ORDER BY captured_at,id');
    $stmt->execute([$profileId,$period['baselineStart']->format('Y-m-d H:i:s'),$period['end']->format('Y-m-d H:i:s')]);
    $rows=$stmt->fetchAll();$sums=['recent'=>[],'previous'=>[],'baseline'=>[]];$content=[];$platforms=[];$latest=null;
    foreach($rows as $row){
        $captured=new DateTimeImmutable((string)$row['captured_at'],new DateTimeZone('UTC'));
        $bucket=$captured>=$period['start']?'recent':($captured>=$period['previousStart']?'previous':'baseline');
        $deltas=decode_json_column($row['metric_deltas']??null,[]);
        foreach(['views','likes','comments','followers'] as $metric){
            if(!array_key_exists($metric,$deltas)||!is_numeric($deltas[$metric]))continue;
            $value=max(0,(float)$deltas[$metric]);$sums[$bucket][$metric]=($sums[$bucket][$metric]??0)+$value;
            if($bucket==='recent'&&in_array($metric,['views','likes','comments'],true)){
                $key=(string)$row['content_key'];$content[$key]=($content[$key]??0)+$value;
                if($value>0)$platforms[(string)$row['platform']]=true;
            }
        }
        if($latest===null||$captured>$latest)$latest=$captured;
    }
    $eventStmt=db()->prepare("SELECT
        SUM(CASE WHEN COALESCE(published_at,collected_at)>=? THEN 1 ELSE 0 END) recent_count,
        SUM(CASE WHEN COALESCE(published_at,collected_at)>=? AND COALESCE(published_at,collected_at)<? THEN 1 ELSE 0 END) previous_count,
        SUM(CASE WHEN COALESCE(published_at,collected_at)>=? AND COALESCE(published_at,collected_at)<? THEN 1 ELSE 0 END) baseline_count
        FROM p50_activity_events WHERE profile_id=? AND COALESCE(published_at,collected_at)>=? AND COALESCE(published_at,collected_at)<=?");
    $eventStmt->execute([
        $period['start']->format('Y-m-d H:i:s'),
        $period['previousStart']->format('Y-m-d H:i:s'),$period['start']->format('Y-m-d H:i:s'),
        $period['baselineStart']->format('Y-m-d H:i:s'),$period['previousStart']->format('Y-m-d H:i:s'),
        $profileId,$period['baselineStart']->format('Y-m-d H:i:s'),$period['end']->format('Y-m-d H:i:s'),
    ]);
    $events=$eventStmt->fetch()?:[];$observations=[];
    foreach(['views','likes','comments','followers'] as $metric){
        if(!array_key_exists($metric,$sums['recent'])&&!array_key_exists($metric,$sums['previous']))continue;
        $observations[$metric]=[
            'recent'=>(float)($sums['recent'][$metric]??0),
            'previous'=>(float)($sums['previous'][$metric]??0),
            'baseline'=>(float)($sums['baseline'][$metric]??0)/5.0,
        ];
    }
    if($rows||array_sum(array_map('intval',$events))>0){
        $observations['publications']=[
            'recent'=>(float)($events['recent_count']??0),
            'previous'=>(float)($events['previous_count']??0),
            'baseline'=>(float)($events['baseline_count']??0)/5.0,
        ];
    }
    $total=array_sum($content);$concentration=$total>0?max($content)/$total:0.0;
    return [
        'observations'=>$observations,
        'captureCount'=>count($rows),
        'recentData'=>$latest!==null&&$latest>=$period['previousStart'],
        'context'=>['interactionConcentration'=>$concentration,'activePlatforms'=>count($platforms)],
    ];
}

function p50_intelligence_run_profile(string $profileId,?DateTimeImmutable $now=null): array {
    p50_intelligence_ensure_schema();$period=p50_intelligence_period($now);
    $input=p50_intelligence_profile_observations($profileId,$period);
    $analysis=p50_intelligence_calculate($input['observations'],$input['captureCount'],$input['recentData'],$input['context']);
    $metrics=['observations'=>$input['observations'],'context'=>$input['context']]+$analysis;
    db()->prepare("INSERT INTO p50_intelligence_snapshots(profile_id,growth_index,buzz_index,confidence_level,main_signal,metrics_json,period_start,period_end,created_at)
        VALUES(?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE growth_index=VALUES(growth_index),buzz_index=VALUES(buzz_index),confidence_level=VALUES(confidence_level),main_signal=VALUES(main_signal),metrics_json=VALUES(metrics_json),created_at=NOW()")
        ->execute([$profileId,$analysis['growthIndex'],$analysis['buzzIndex'],$analysis['confidenceLevel'],$analysis['mainSignal'],json_encode($metrics,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$period['start']->format('Y-m-d H:i:s'),$period['end']->format('Y-m-d H:i:s')]);
    return $analysis+[
        'profileId'=>$profileId,
        'periodStart'=>$period['start']->format('c'),
        'periodEnd'=>$period['end']->format('c'),
    ];
}

function p50_intelligence_empty_diagnostics(): array {
    return ['profilesAnalyzed'=>0,'profilesIgnored'=>0,'strongTrends'=>0,'buzzDetected'=>0,'declinesDetected'=>0,'errors'=>0];
}

function p50_intelligence_add_diagnostic(array &$diagnostics,array $analysis): void {
    $diagnostics['profilesAnalyzed']++;
    if(($analysis['confidenceLevel']??'faible')==='faible')$diagnostics['profilesIgnored']++;
    $trusted=in_array($analysis['confidenceLevel']??'', ['moyenne','élevée'],true);
    if($trusted&&($analysis['growthIndex']??0)>=65)$diagnostics['strongTrends']++;
    if($trusted&&($analysis['buzzIndex']??0)>=70)$diagnostics['buzzDetected']++;
    if($trusted&&($analysis['globalVariation']??0)<=-20)$diagnostics['declinesDetected']++;
}

function p50_intelligence_dashboard(): array {
    p50_intelligence_ensure_schema();
    $rows=db()->query("SELECT s.*,r.public_name FROM p50_intelligence_snapshots s
        JOIN p50_profile_registry r ON r.profile_id=s.profile_id
        WHERE s.created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)
        ORDER BY s.created_at DESC,s.id DESC")->fetchAll();
    $state=p50_de_load_public_state();$photos=[];
    foreach((array)($state['profiles']??[]) as $profile)$photos[(string)($profile['id']??'')]=(string)($profile['photoUrl']??$profile['photoCandidateUrl']??'');
    $latest=[];
    foreach($rows as $row)if(!isset($latest[$row['profile_id']]))$latest[$row['profile_id']]=$row;
    $items=[];
    foreach($latest as $row){
        $metrics=decode_json_column($row['metrics_json']??null,[]);
        $items[]=[
            'profileId'=>(string)$row['profile_id'],'name'=>(string)$row['public_name'],'photo'=>$photos[$row['profile_id']]??'',
            'growthIndex'=>(int)$row['growth_index'],'buzzIndex'=>(int)$row['buzz_index'],'confidenceLevel'=>(string)$row['confidence_level'],
            'globalVariation'=>(float)($metrics['globalVariation']??0),'mainVariation'=>(string)($metrics['mainVariationLabel']??'Données insuffisantes'),
            'mainSignal'=>(string)$row['main_signal'],'explanation'=>(string)($metrics['explanation']??'Données insuffisantes pour une conclusion fiable.'),
            'periodStart'=>gmdate('c',strtotime((string)$row['period_start'])),'periodEnd'=>gmdate('c',strtotime((string)$row['period_end'])),
        ];
    }
    $trusted=static fn(array $item): bool=>in_array($item['confidenceLevel'],['moyenne','élevée'],true);
    $trends=array_values(array_filter($items,static fn(array $item): bool=>$trusted($item)&&$item['growthIndex']>=65));
    $buzz=array_values(array_filter($items,static fn(array $item): bool=>$trusted($item)&&$item['buzzIndex']>=70));
    $declines=array_values(array_filter($items,static fn(array $item): bool=>$trusted($item)&&$item['globalVariation']<=-20));
    usort($trends,static fn($a,$b)=>$b['growthIndex']<=>$a['growthIndex']);
    usort($buzz,static fn($a,$b)=>$b['buzzIndex']<=>$a['buzzIndex']);
    usort($declines,static fn($a,$b)=>$a['globalVariation']<=>$b['globalVariation']);
    return ['generatedAt'=>gmdate('c'),'periodLabel'=>'Dernières 24 heures comparées aux 24 heures précédentes','strongTrends'=>array_slice($trends,0,10),'buzzDetected'=>array_slice($buzz,0,10),'declines'=>array_slice($declines,0,10)];
}
