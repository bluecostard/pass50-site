<?php
declare(strict_types=1);

require_once __DIR__.'/metrics-schema-core.php';

const P50_MR_ALGORITHM_VERSION='MR-V1.5';
const P50_MR_LOCK='pass50_metrics_ranking_experimental_v1';
const P50_MR_MIN_PERCENTILE_POOL=20;
/** Plafond score « présence audience » (sans delta contenu dans la fenêtre) — évite la falaise ~23e en 24H. */
const P50_MR_AUDIENCE_PRESENCE_CAP_SHORT = 30.0;
const P50_MR_AUDIENCE_PRESENCE_CAP_LONG = 35.0;

function p50_mr_periods(): array {
    return ['2H'=>2,'24H'=>24,'48H'=>48,'7J'=>168,'15J'=>360];
}

function p50_mr_weights(): array {
    return [
        'audience'=>0.05,'reach'=>0.28,'engagementVolume'=>0.18,
        'engagementRate'=>0.16,'velocity'=>0.18,'acceleration'=>0.12,'live'=>0.03,
    ];
}

function p50_mr_platform_weights(): array { return [0.70,0.20,0.10]; }
function p50_mr_freshness_hours(): array { return ['2H'=>6,'24H'=>6,'48H'=>8,'7J'=>18,'15J'=>18]; }

function p50_mr_window_hours(string $periodKey,int $hours): int { return $periodKey==='2H'?3:$hours; }
function p50_mr_classability_thresholds(string $periodKey): array {
    return $periodKey==='2H'?['coverage'=>5.0,'confidence'=>35.0]:['coverage'=>30.0,'confidence'=>40.0];
}

/** Cap audience seule par période (0–100). Le mélange dynamique garde audience=0.05. */
function p50_mr_audience_presence_cap(string $periodKey): float {
    return in_array($periodKey,['2H','24H','48H'],true)?P50_MR_AUDIENCE_PRESENCE_CAP_SHORT:P50_MR_AUDIENCE_PRESENCE_CAP_LONG;
}

/**
 * Score de base quand seule l’audience est mesurable (pas de reach/engagement dans la fenêtre).
 * MR-V1.4 : percentile×0.05 → ~3–5 % dès le ~23e en 24H. MR-V1.5 : échelle 0–30 % (prod avant migration).
 */
function p50_mr_audience_only_base(float $audiencePercentile,string $periodKey): float {
    $cap=p50_mr_audience_presence_cap($periodKey);
    return max(0.0,min(100.0,$audiencePercentile/100.0*$cap));
}


function p50_mr_profile_coverage(array $platforms,?float $weightedCoverage): float {
    $best=0.0;
    foreach($platforms as $platform)$best=max($best,(float)($platform['coverage']??0));
    return max(0.0,min(100.0,max((float)($weightedCoverage??0),$best)));
}

function p50_mr_has_recent_activity(array $platforms): bool {
    foreach($platforms as $platform){
        $raw=(array)($platform['raw']??[]);$features=(array)($raw['features']??[]);
        if((float)($raw['reachRaw']??0)>0)return true;
        if((float)($features['engagementVolume']??0)>0)return true;
        if((float)($features['live']??0)>0)return true;
        if(!empty($raw['publishedInsideWindowFallback']))return true;
    }
    return false;
}

function p50_mr_schema_sql(): array {
    return [
        "CREATE TABLE IF NOT EXISTS p50_metric_ranking_runs (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          run_uuid CHAR(36) CHARACTER SET ascii NOT NULL,algorithm_version VARCHAR(24) NOT NULL,
          trigger_type VARCHAR(32) NOT NULL,status VARCHAR(24) NOT NULL,
          periods_json LONGTEXT NOT NULL,profiles_considered INT UNSIGNED NOT NULL DEFAULT 0,
          classable_count INT UNSIGNED NOT NULL DEFAULT 0,scores_written INT UNSIGNED NOT NULL DEFAULT 0,
          error_message VARCHAR(500) NULL,metadata_json LONGTEXT NOT NULL,
          started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,finished_at DATETIME NULL,
          UNIQUE KEY uq_p50_mr_run_uuid(run_uuid),
          INDEX idx_p50_mr_run_algorithm(algorithm_version,started_at),
          INDEX idx_p50_mr_run_status(status,started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS p50_metric_ranking_current (
          algorithm_version VARCHAR(24) NOT NULL,period_key VARCHAR(8) NOT NULL,
          profile_id VARCHAR(100) NOT NULL,run_uuid CHAR(36) CHARACTER SET ascii NOT NULL,
          rank_position INT UNSIGNED NULL,score DECIMAL(7,3) NULL,base_score DECIMAL(7,3) NULL,
          confidence DECIMAL(7,3) NOT NULL,coverage DECIMAL(7,3) NOT NULL,
          classable TINYINT(1) NOT NULL,editorial_eligible TINYINT(1) NOT NULL,
          platform_count SMALLINT UNSIGNED NOT NULL,content_count INT UNSIGNED NOT NULL,
          capture_count INT UNSIGNED NOT NULL,latest_capture_at DATETIME NULL,
          components_json LONGTEXT NOT NULL,raw_features_json LONGTEXT NOT NULL,
          exclusion_reasons_json LONGTEXT NOT NULL,previous_rank INT UNSIGNED NULL,
          rank_delta INT NULL,calculated_at DATETIME NOT NULL,
          PRIMARY KEY(algorithm_version,period_key,profile_id),
          INDEX idx_p50_mr_current_run(run_uuid),
          INDEX idx_p50_mr_current_period(period_key,classable,rank_position),
          INDEX idx_p50_mr_current_profile(profile_id,calculated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS p50_metric_ranking_snapshots (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          run_uuid CHAR(36) CHARACTER SET ascii NOT NULL,algorithm_version VARCHAR(24) NOT NULL,
          period_key VARCHAR(8) NOT NULL,profile_id VARCHAR(100) NOT NULL,
          rank_position INT UNSIGNED NOT NULL,score DECIMAL(7,3) NOT NULL,
          confidence DECIMAL(7,3) NOT NULL,coverage DECIMAL(7,3) NOT NULL,
          previous_rank INT UNSIGNED NULL,rank_delta INT NULL,captured_at DATETIME NOT NULL,
          UNIQUE KEY uq_p50_mr_snapshot(run_uuid,period_key,profile_id),
          INDEX idx_p50_mr_snapshot_period(algorithm_version,period_key,rank_position)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS p50_metric_ranking_period_runs (
          run_uuid CHAR(36) CHARACTER SET ascii NOT NULL,algorithm_version VARCHAR(24) NOT NULL,
          period_key VARCHAR(8) NOT NULL,profiles_considered INT UNSIGNED NOT NULL,
          classable_count INT UNSIGNED NOT NULL,excluded_count INT UNSIGNED NOT NULL,
          average_score DECIMAL(7,3) NULL,median_score DECIMAL(7,3) NULL,top_score DECIMAL(7,3) NULL,
          average_confidence DECIMAL(7,3) NOT NULL,average_coverage DECIMAL(7,3) NOT NULL,
          threshold_excluded_count INT UNSIGNED NOT NULL,hard_excluded_count INT UNSIGNED NOT NULL,
          other_excluded_count INT UNSIGNED NOT NULL,exclusion_summary_json LONGTEXT NOT NULL,
          calculated_at DATETIME NOT NULL,
          PRIMARY KEY(run_uuid,period_key),
          INDEX idx_p50_mr_period_algorithm(algorithm_version,period_key,calculated_at),
          INDEX idx_p50_mr_period_classable(period_key,classable_count),
          INDEX idx_p50_mr_period_run(run_uuid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

function p50_mr_schema_status(PDO $pdo): array {
    $tables=['p50_metric_ranking_runs','p50_metric_ranking_current','p50_metric_ranking_snapshots'];
    $present=[];foreach($tables as $table)$present[$table]=p50_metrics_table_exists($pdo,$table);
    return ['status'=>count(array_filter($present))===count($tables)?'applied':'missing','tables'=>$present];
}

function p50_mr_ensure_schema(PDO $pdo): array {
    foreach(p50_mr_schema_sql() as $sql)$pdo->exec($sql);
    return p50_mr_schema_status($pdo);
}

function p50_mr_uuid(): string {
    $data=random_bytes(16);$data[6]=chr((ord($data[6])&0x0f)|0x40);$data[8]=chr((ord($data[8])&0x3f)|0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($data),4));
}

function p50_mr_json(array $value,bool $strict=true): string {
    if($strict)p50_metrics_assert_safe($value,'ranking');
    else $value=p50_metrics_redact_unsafe($value);
    if(!is_array($value))$value=[];
    return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}

function p50_mr_safe_error(Throwable $error): string {
    $message=preg_replace('~https?://\\S+~i','[url]',$error->getMessage())??'';
    $message=preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,}/i','[email]',$message)??'';
    $message=preg_replace('/Bearer\\s+\\S+/i','Bearer [redacted]',$message)??'';
    $message=preg_replace('/\\b(token|secret|password|cookie)\\s*[=:]\\s*\\S+/i','$1=[redacted]',$message)??'';
    $message=trim($message)!==''?$message:'Échec sans détail';
    return function_exists('mb_substr')?mb_substr($message,0,500,'UTF-8'):substr($message,0,500);
}

function p50_mr_run_metadata(array $metadata=[]): array {
    $safe=['readOnlyCanonicalInputs'=>true,'publicPublication'=>false];
    if(array_key_exists('scheduled',$metadata))$safe['scheduled']=(bool)$metadata['scheduled'];
    if(($metadata['cadence']??null)==='2h')$safe['cadence']='2h';
    $dispatchId=is_string($metadata['dispatchId']??null)?trim($metadata['dispatchId']):'';
    if($dispatchId!==''&&strlen($dispatchId)<=120&&preg_match('/^[A-Za-z0-9._-]+$/',$dispatchId))$safe['dispatchId']=$dispatchId;
    return $safe;
}

function p50_mr_median(array $values): ?float {
    $numeric=[];foreach($values as $value)if(is_int($value)||is_float($value)||is_numeric($value))$numeric[]=(float)$value;
    if(!$numeric)return null;sort($numeric,SORT_NUMERIC);$count=count($numeric);$middle=intdiv($count,2);
    return $count%2===1?$numeric[$middle]:($numeric[$middle-1]+$numeric[$middle])/2;
}

function p50_mr_period_summary(array $rows): array {
    $profiles=count($rows);$classable=0;$scores=[];$confidence=[];$coverage=[];$exclusions=[];
    $thresholdExcluded=0;$hardExcluded=0;$otherExcluded=0;$thresholdReasons=['coverage_below_30'=>true,'confidence_below_40'=>true,'coverage_below_5'=>true,'confidence_below_35'=>true];
    foreach($rows as $row){
        $confidence[]=(float)($row['confidence']??0);$coverage[]=(float)($row['coverage']??0);
        if(!empty($row['classable'])){$classable++;if(($row['score']??null)!==null)$scores[]=(float)$row['score'];continue;}
        $reasons=array_values(array_unique(array_map('strval',(array)($row['exclusionReasons']??[]))));
        foreach($reasons as $reason)$exclusions[$reason]=($exclusions[$reason]??0)+1;
        $hasThreshold=(bool)array_filter($reasons,static fn($reason)=>isset($thresholdReasons[$reason]));
        $hasHard=(bool)array_filter($reasons,static fn($reason)=>!isset($thresholdReasons[$reason]));
        if($hasHard)$hardExcluded++;elseif($hasThreshold)$thresholdExcluded++;else $otherExcluded++;
    }
    ksort($exclusions);
    return [
        'profilesConsidered'=>$profiles,'classableCount'=>$classable,'excludedCount'=>$profiles-$classable,
        'averageScore'=>$scores?array_sum($scores)/count($scores):null,'medianScore'=>p50_mr_median($scores),
        'topScore'=>$scores?max($scores):null,
        'averageConfidence'=>$confidence?array_sum($confidence)/count($confidence):0.0,
        'averageCoverage'=>$coverage?array_sum($coverage)/count($coverage):0.0,
        'thresholdExcludedCount'=>$thresholdExcluded,'hardExcludedCount'=>$hardExcluded,
        'otherExcludedCount'=>$otherExcluded,'exclusionSummary'=>$exclusions,
    ];
}

function p50_mr_percentiles(array $values): array {
    $numeric=[];foreach($values as $key=>$value)if(is_int($value)||is_float($value))$numeric[(string)$key]=(float)$value;
    $count=count($numeric);
    if($count===0)return [];
    if($count===1)return [array_key_first($numeric)=>50.0];
    if($count===2){
        $keys=array_keys($numeric);$first=$numeric[$keys[0]];$second=$numeric[$keys[1]];
        if($first===$second)return [$keys[0]=>50.0,$keys[1]=>50.0];
        return $first<$second?[$keys[0]=>25.0,$keys[1]=>75.0]:[$keys[0]=>75.0,$keys[1]=>25.0];
    }
    $sorted=array_values($numeric);sort($sorted,SORT_NUMERIC);$n=count($sorted);$out=[];
    foreach($numeric as $key=>$value){
        $positions=[];foreach($sorted as $index=>$candidate)if($candidate===$value)$positions[]=$index;
        $out[$key]=(array_sum($positions)/count($positions))/($n-1)*100;
    }
    return $out;
}

/** Percentiles globaux quand le vivier dynamique est trop petit — évite qu'une poignée de profils monopolisent le haut du classement. */
function p50_mr_assign_feature_percentiles(array &$raw,array $weights,int $minPool=P50_MR_MIN_PERCENTILE_POOL): void {
    foreach(array_keys($weights) as $feature){
        $values=[];
        foreach($raw as $key=>$item){
            if(!empty($item['raw']['availability'][$feature]))$values[$key]=$item['raw']['features'][$feature];
        }
        if(!$values)continue;
        if(count($values)<$minPool){
            foreach(p50_mr_percentiles($values) as $key=>$percentile)$raw[$key]['percentiles'][$feature]=$percentile;
            continue;
        }
        $byPlatform=[];
        foreach($raw as $key=>$item){
            if(!empty($item['raw']['availability'][$feature]))$byPlatform[$item['account']['platform']][$key]=$item['raw']['features'][$feature];
        }
        foreach($byPlatform as $platformValues){
            foreach(p50_mr_percentiles($platformValues) as $key=>$percentile)$raw[$key]['percentiles'][$feature]=$percentile;
        }
    }
}

function p50_mr_metric_delta(array $captures,string $metric,DateTimeImmutable $start,DateTimeImmutable $end,?DateTimeImmutable $publishedAt): array {
    $usable=[];
    foreach($captures as $capture){
        if(!array_key_exists($metric,$capture)||$capture[$metric]===null||!is_numeric($capture[$metric]))continue;
        $at=p50_metrics_parse_utc((string)$capture['observed_at']);if(!$at)continue;
        if($at>$end)continue;$usable[]=['id'=>(int)$capture['id'],'at'=>$at,'observed_at'=>(string)$capture['observed_at'],'value'=>(float)$capture[$metric],'confidence'=>(float)$capture['confidence']];
    }
    usort($usable,fn($a,$b)=>$a['at']<=>$b['at']);
    if(!$usable)return ['available'=>false,'value'=>null,'publishedInsideWindowFallback'=>false];
    $reference=null;$firstInside=null;$last=null;
    foreach($usable as $item){
        if($item['at']<=$start)$reference=$item;
        elseif($item['at']<=$end){if($firstInside===null)$firstInside=$item;$last=$item;}
    }
    if($last===null)return ['available'=>false,'value'=>null,'publishedInsideWindowFallback'=>false];
    $fallback=false;
    if($reference===null){
        if($publishedAt!==null&&$publishedAt>=$start&&$publishedAt<=$end&&$last['at']>$publishedAt){$fallback=true;}
        elseif($firstInside!==null&&$firstInside['id']!==$last['id'])$reference=$firstInside;
        else return ['available'=>false,'value'=>null,'publishedInsideWindowFallback'=>false];
    }
    if(!$fallback&&$reference['id']===$last['id'])return ['available'=>false,'value'=>null,'publishedInsideWindowFallback'=>false];
    $referenceValue=$fallback?0.0:$reference['value'];
    if($last['value']<$referenceValue)return ['available'=>false,'value'=>null,'publishedInsideWindowFallback'=>$fallback,'counterDecreased'=>true];
    $used=[$last['id']=>['id'=>$last['id'],'confidence'=>$last['confidence'],'observed_at'=>$last['observed_at']]];
    if(!$fallback)$used[$reference['id']]=['id'=>$reference['id'],'confidence'=>$reference['confidence'],'observed_at'=>$reference['observed_at']];
    return [
        'available'=>true,'value'=>max(0.0,$last['value']-$referenceValue),
        'publishedInsideWindowFallback'=>$fallback,'captures'=>array_values($used),
    ];
}

function p50_mr_load(PDO $pdo,DateTimeImmutable $now): array {
    $eligibleColumn=p50_metrics_column_exists($pdo,'p50_profile_registry','editorial_eligible')?'editorial_eligible':'eligible';
    if(!p50_metrics_column_exists($pdo,'p50_profile_registry',$eligibleColumn))$eligibleColumn='alive';
    $profileColumns=['handle'=>'\'\'','region'=>'\'\'','category'=>'\'\''];
    foreach(array_keys($profileColumns) as $column)if(p50_metrics_column_exists($pdo,'p50_profile_registry',$column))$profileColumns[$column]="`$column`";
    $profiles=$pdo->query("SELECT profile_id,public_name,{$profileColumns['handle']} handle,{$profileColumns['region']} region,{$profileColumns['category']} category,alive,`$eligibleColumn` editorial_eligible FROM p50_profile_registry ORDER BY profile_id")->fetchAll();
    $accounts=$pdo->query("SELECT id,profile_id,platform,status,confidence,source_type FROM p50_metric_accounts WHERE status='active' AND profile_id<>'' ORDER BY id")->fetchAll();
    $contents=$pdo->query("SELECT id,account_id,profile_id,platform,published_at,status,confidence FROM p50_metric_contents WHERE status='active' AND profile_id<>'' ORDER BY id")->fetchAll();
    $cutoff=$now->modify('-360 hours')->format('Y-m-d H:i:s');
    $stmt=$pdo->prepare("SELECT id,account_id,content_id,profile_id,platform,captured_at observed_at,followers,views,likes,comments,shares,saves,live_viewers,confidence
      FROM p50_metric_captures WHERE quality_status='usable' AND confidence>=70 AND profile_id<>'' AND captured_at<=? AND captured_at>=? ORDER BY captured_at,id");
    $stmt->execute([$now->format('Y-m-d H:i:s'),$cutoff]);$captures=$stmt->fetchAll();
    $stmt=$pdo->prepare("SELECT c.id,c.account_id,c.content_id,c.profile_id,c.platform,c.captured_at observed_at,c.followers,c.views,c.likes,c.comments,c.shares,c.saves,c.live_viewers,c.confidence
      FROM p50_metric_captures c JOIN (
        SELECT account_id,COALESCE(content_id,0) series_content,MAX(captured_at) captured_at
        FROM p50_metric_captures WHERE quality_status='usable' AND confidence>=70 AND profile_id<>'' AND captured_at<?
        GROUP BY account_id,COALESCE(content_id,0)
      ) r ON r.account_id=c.account_id AND r.series_content=COALESCE(c.content_id,0) AND r.captured_at=c.captured_at
      WHERE c.quality_status='usable' AND c.confidence>=70 ORDER BY c.captured_at,c.id");
    $stmt->execute([$cutoff]);foreach($stmt->fetchAll() as $capture)$captures[]=$capture;
    $verifiedOfficialIds=[];
    if(p50_metrics_table_exists($pdo,'p50_social_links')){
        $linkStmt=$pdo->prepare("SELECT DISTINCT profile_id FROM p50_social_links WHERE status='verified' AND confidence>=70 AND profile_id<>''");
        $linkStmt->execute();
        foreach($linkStmt->fetchAll(PDO::FETCH_COLUMN) as $profileId)$verifiedOfficialIds[(string)$profileId]=true;
    }
    return compact('profiles','accounts','contents','captures','verifiedOfficialIds');
}

function p50_mr_is_official_account(array $account): bool {
    if((string)$account['status']!=='active'||(int)$account['confidence']<70)return false;
    $source=strtolower(trim((string)$account['source_type']));
    if($source==='legacy_social_link'||$source==='verified_social_link')return true;
    return preg_match('/(?:^unknown$|^legacy_unknown$|candidate|unverified)/i',$source)!==1;
}

function p50_mr_platform_raw(array $account,array $contents,array $captures,DateTimeImmutable $start,DateTimeImmutable $end): array {
    $accountCaptures=array_values(array_filter($captures,fn($c)=>(int)$c['account_id']===(int)$account['id']&&$c['content_id']===null));
    $latestFollower=null;foreach($accountCaptures as $capture){$observedAt=p50_metrics_parse_utc((string)$capture['observed_at']);if($capture['followers']===null||!is_numeric($capture['followers'])||!$observedAt||$observedAt>$end)continue;if($latestFollower===null||(string)$capture['observed_at']>(string)$latestFollower['observed_at'])$latestFollower=$capture;}
    $features=['audience'=>$latestFollower?log1p((float)$latestFollower['followers']):null,'reach'=>null,'engagementVolume'=>null,'engagementRate'=>null,'velocity'=>null,'acceleration'=>null,'live'=>null];
    $availability=array_fill_keys(array_keys($features),false);if($latestFollower)$availability['audience']=true;
    $viewSum=0.0;$interaction=0.0;$velocity=0.0;$viewAvailable=false;$interactionAvailable=false;$contentCount=0;$usedCaptures=[];$fallback=false;
    $remember=function(array $capture)use(&$usedCaptures): void {$usedCaptures[(int)$capture['id']]=['confidence'=>(float)$capture['confidence'],'observed_at'=>(string)$capture['observed_at']];};
    $rememberDelta=function(array $delta)use($remember): void {foreach($delta['captures']??[] as $capture)$remember($capture);};
    foreach($contents as $content){
        if((int)$content['account_id']!==(int)$account['id'])continue;
        $series=array_values(array_filter($captures,fn($c)=>(int)($c['content_id']??0)===(int)$content['id']));
        $published=$content['published_at']?p50_metrics_parse_utc((string)$content['published_at']):null;
        $deltas=[];foreach(['views','likes','comments','shares','saves'] as $metric)$deltas[$metric]=p50_mr_metric_delta($series,$metric,$start,$end,$published);
        $measured=array_filter($deltas,fn($delta)=>$delta['available']);
        if(!$measured)continue;$contentCount++;
        foreach($measured as $delta){$rememberDelta($delta);$fallback=$fallback||($delta['publishedInsideWindowFallback']??false);}
        if($deltas['views']['available']){
            $views=(float)$deltas['views']['value'];$viewSum+=$views;$viewAvailable=true;
            $activeStart=$published&&$published>$start?$published:$start;$hours=max(1.0,($end->getTimestamp()-$activeStart->getTimestamp())/3600);$velocity+=$views/$hours;
        }
        $weighted=0.0;$hasInteraction=false;
        foreach(['likes'=>1,'comments'=>3,'shares'=>5,'saves'=>4] as $metric=>$weight)if($deltas[$metric]['available']){$weighted+=(float)$deltas[$metric]['value']*$weight;$hasInteraction=true;}
        if($hasInteraction){$interaction+=$weighted;$interactionAvailable=true;}
    }
    if($viewAvailable){$features['reach']=log1p($viewSum);$features['velocity']=log1p($velocity);$availability['reach']=$availability['velocity']=true;}
    if($interactionAvailable){$features['engagementVolume']=log1p($interaction);$availability['engagementVolume']=true;}
    if($viewAvailable&&$interactionAvailable){$features['engagementRate']=min(1.0,$interaction/max($viewSum,1));$availability['engagementRate']=true;}
    $middle=$start->modify('+'.intdiv($end->getTimestamp()-$start->getTimestamp(),2).' seconds');
    $oldReach=0.0;$newReach=0.0;$oldMeasured=false;$newMeasured=false;$accelerationCaptures=[];
    foreach($contents as $content){
        if((int)$content['account_id']!==(int)$account['id'])continue;$series=array_values(array_filter($captures,fn($c)=>(int)($c['content_id']??0)===(int)$content['id']));
        $published=$content['published_at']?p50_metrics_parse_utc((string)$content['published_at']):null;
        $old=p50_mr_metric_delta($series,'views',$start,$middle,$published);$new=p50_mr_metric_delta($series,'views',$middle,$end,$published);
        if($old['available']){$oldReach+=(float)$old['value'];$oldMeasured=true;$accelerationCaptures[]=$old;}if($new['available']){$newReach+=(float)$new['value'];$newMeasured=true;$accelerationCaptures[]=$new;}
    }
    if($oldMeasured&&$newMeasured){$features['acceleration']=log((1+$newReach)/(1+$oldReach));$availability['acceleration']=true;foreach($accelerationCaptures as $delta)$rememberDelta($delta);}
    $liveMax=null;$liveCapture=null;foreach($accountCaptures as $capture){$at=p50_metrics_parse_utc((string)$capture['observed_at']);if(!$at||$at<$start||$at>$end||$capture['live_viewers']===null||!is_numeric($capture['live_viewers']))continue;$value=(float)$capture['live_viewers'];if($liveMax===null||$value>$liveMax||($value===$liveMax&&(string)$capture['observed_at']>(string)$liveCapture['observed_at'])){$liveMax=$value;$liveCapture=$capture;}}
    if($liveMax!==null){$features['live']=log1p($liveMax);$availability['live']=true;}
    if($liveCapture)$remember($liveCapture);if($latestFollower)$remember($latestFollower);
    $captureCount=count($usedCaptures);$quality=$captureCount?array_sum(array_column($usedCaptures,'confidence'))/$captureCount:0;$latestAt=null;
    foreach($usedCaptures as $capture)if($latestAt===null||$capture['observed_at']>$latestAt)$latestAt=$capture['observed_at'];
    return ['features'=>$features,'availability'=>$availability,'reachRaw'=>$viewAvailable?$viewSum:null,'contentCount'=>$contentCount,'captureCount'=>$captureCount,'latestCaptureAt'=>$latestAt,'quality'=>$quality,'publishedInsideWindowFallback'=>$fallback];
}

function p50_mr_period_rows(array $loaded,string $periodKey,int $hours,DateTimeImmutable $now,array $previousRanks): array {
    $windowHours=p50_mr_window_hours($periodKey,$hours);$start=$now->modify("-$windowHours hours");$weights=p50_mr_weights();$freshLimit=p50_mr_freshness_hours()[$periodKey];$thresholds=p50_mr_classability_thresholds($periodKey);
    $accountsByProfile=[];foreach($loaded['accounts'] as $account)$accountsByProfile[$account['profile_id']][]=$account;
    $raw=[];
    foreach($loaded['profiles'] as $profile)foreach($accountsByProfile[$profile['profile_id']]??[] as $account){
        if(!p50_mr_is_official_account($account))continue;
        $key=$profile['profile_id'].'|'.$account['platform'];
        $raw[$key]=['profile'=>$profile,'account'=>$account,'raw'=>p50_mr_platform_raw($account,$loaded['contents'],$loaded['captures'],$start,$now)];
    }
    p50_mr_assign_feature_percentiles($raw,$weights);
    $platformsByProfile=[];
    foreach($raw as $item){
        $available=$item['percentiles']??[];$weightSum=0.0;$dynamicWeightSum=0.0;$dynamicWeighted=0.0;
        foreach($available as $feature=>$percentile){
            $weightSum+=$weights[$feature];
            if($feature==='audience')continue;
            $dynamicWeightSum+=$weights[$feature];$dynamicWeighted+=$percentile*$weights[$feature];
        }
        $audiencePercentile=$available['audience']??null;
        if($dynamicWeightSum<=0){
            if($audiencePercentile===null)continue;
            $base=p50_mr_audience_only_base((float)$audiencePercentile,$periodKey);
        }else{
            $dynamicBase=$dynamicWeighted/$dynamicWeightSum;
            $base=$dynamicBase*(1-$weights['audience'])+($audiencePercentile===null?0.0:$audiencePercentile*$weights['audience']);
        }
        $coverage=$weightSum*100;$quality=max(0,min(100,$item['raw']['quality']));
        $latest=$item['raw']['latestCaptureAt'];$parsedLatest=p50_metrics_parse_utc($latest);$age=$parsedLatest?max(0,($now->getTimestamp()-$parsedLatest->getTimestamp())/3600):INF;
        $freshness=is_finite($age)?max(0,min(100,100*(1-$age/$freshLimit))):0;
        $confidence=0.45*$coverage+0.35*$quality+0.20*$freshness;$score=max(0,min(100,$base*(0.72+0.28*$confidence/100)));
        $platformsByProfile[$item['profile']['profile_id']][]=['platform'=>$item['account']['platform'],'score'=>$score,'baseScore'=>$base,'coverage'=>$coverage,'confidence'=>$confidence,'freshness'=>$freshness,'raw'=>$item['raw'],'percentiles'=>$available];
    }
    $rows=[];
    foreach($loaded['profiles'] as $profile){
        $profileId=(string)$profile['profile_id'];$platforms=$platformsByProfile[$profileId]??[];usort($platforms,fn($a,$b)=>$b['score']<=>$a['score']?:strcmp($a['platform'],$b['platform']));$platforms=array_slice($platforms,0,3);
        $selectedWeights=array_slice(p50_mr_platform_weights(),0,count($platforms));$den=array_sum($selectedWeights);
        $aggregate=function(string $field)use($platforms,$selectedWeights,$den): ?float {if(!$platforms||$den<=0)return null;$v=0;foreach($platforms as $i=>$p)$v+=$p[$field]*$selectedWeights[$i];return $v/$den;};
        $score=$aggregate('score');$base=$aggregate('baseScore');$weightedCoverage=$aggregate('coverage')??0;$coverage=p50_mr_profile_coverage($platforms,$weightedCoverage);$confidence=$aggregate('confidence')??0;
        $contentCount=array_sum(array_column(array_column($platforms,'raw'),'contentCount'));$captureCount=array_sum(array_column(array_column($platforms,'raw'),'captureCount'));
        $latest=null;$reachRaw=0.0;foreach($platforms as $platform){$candidate=$platform['raw']['latestCaptureAt'];if($candidate&&($latest===null||$candidate>$latest))$latest=$candidate;$reachRaw+=(float)($platform['raw']['reachRaw']??0);}
        $official=count(array_filter($accountsByProfile[$profileId]??[],fn($account)=>p50_mr_is_official_account($account)))>0
            ||!empty($loaded['verifiedOfficialIds'][$profileId]);
        $recentActivity=p50_mr_has_recent_activity($platforms);
        $reasons=[];if(!(bool)$profile['editorial_eligible']||!(bool)$profile['alive'])$reasons[]='editorial_not_eligible';if(!$official)$reasons[]='no_official_metric_account';if($periodKey==='2H'&&!$recentActivity)$reasons[]='no_recent_activity';elseif($contentCount<1)$reasons[]='no_measurable_content';if($coverage<$thresholds['coverage'])$reasons[]=$periodKey==='2H'?'coverage_below_5':'coverage_below_30';if($confidence<$thresholds['confidence'])$reasons[]=$periodKey==='2H'?'confidence_below_35':'confidence_below_40';
        $parsedLatest=p50_metrics_parse_utc($latest);$age=$parsedLatest?($now->getTimestamp()-$parsedLatest->getTimestamp())/3600:INF;if($age>$freshLimit)$reasons[]='stale_captures';
        if($score===null&&$official&&(bool)$profile['alive']){
            $score=0.1;if($base===null)$base=0.1;$reasons[]='awaiting_measurable_capture';
        }
        $classable=$score!==null&&(bool)$profile['alive']&&$official;
        $rows[]=['profile'=>$profile,'profileId'=>$profileId,'score'=>$score,'baseScore'=>$base,'confidence'=>$confidence,'coverage'=>$coverage,'classable'=>$classable,'editorialEligible'=>(bool)$profile['editorial_eligible'],'platformCount'=>count($platforms),'contentCount'=>$contentCount,'captureCount'=>$captureCount,'latestCaptureAt'=>$latest,'components'=>$platforms,'rawFeatures'=>array_map(fn($p)=>['platform'=>$p['platform'],'features'=>$p['raw']['features'],'availability'=>$p['raw']['availability'],'reachRaw'=>$p['raw']['reachRaw'],'publishedInsideWindowFallback'=>$p['raw']['publishedInsideWindowFallback']],$platforms),'exclusionReasons'=>array_values(array_unique($reasons)),'reachRaw'=>$reachRaw,'previousRank'=>$previousRanks[$profileId]??null,'rank'=>null,'rankDelta'=>null];
    }
    $classable=array_values(array_filter($rows,fn($row)=>$row['classable']));
    usort($classable,fn($a,$b)=>$b['score']<=>$a['score']?:($b['confidence']<=>$a['confidence']?:($b['reachRaw']<=>$a['reachRaw']?:strcmp($a['profileId'],$b['profileId']))));
    $ranks=[];foreach($classable as $index=>$row)$ranks[$row['profileId']]=$index+1;
    foreach($rows as &$row)if(isset($ranks[$row['profileId']])){$row['rank']=$ranks[$row['profileId']];$row['rankDelta']=$row['previousRank']===null?null:$row['previousRank']-$row['rank'];}unset($row);
    return $rows;
}

function p50_mr_calculate(PDO $pdo,array $periods,string $triggerType,array $metadata=[]): array {
    $allowed=p50_mr_periods();$selected=[];foreach($periods as $period)if(isset($allowed[$period]))$selected[$period]=$allowed[$period];
    if(!$selected)throw new InvalidArgumentException('Aucune période valide.');
    if((int)p50_metrics_value($pdo,"SELECT GET_LOCK(?,10)",[P50_MR_LOCK])!==1)throw new RuntimeException('Un calcul expérimental est déjà en cours.');
    $runUuid=p50_mr_uuid();$now=p50_metrics_now_utc();$runCreated=false;$runMetadata=p50_mr_run_metadata($metadata);
    try{
        p50_mr_ensure_schema($pdo);
        $stmt=$pdo->prepare("INSERT INTO p50_metric_ranking_runs(run_uuid,algorithm_version,trigger_type,status,periods_json,metadata_json,started_at) VALUES(?,?,?,'running',?,?,?)");
        $stmt->execute([$runUuid,P50_MR_ALGORITHM_VERSION,mb_substr($triggerType,0,32),p50_mr_json(array_keys($selected)),p50_mr_json($runMetadata),$now->format('Y-m-d H:i:s')]);$runCreated=true;
        $pdo->beginTransaction();
        $loaded=p50_mr_load($pdo,$now);$allRows=[];$periodSummaries=[];$classableCount=0;$scoresWritten=0;
        foreach($selected as $periodKey=>$hours){
            $stmt=$pdo->prepare("SELECT profile_id,rank_position FROM p50_metric_ranking_current WHERE algorithm_version=? AND period_key=? AND rank_position IS NOT NULL");
            $stmt->execute([P50_MR_ALGORITHM_VERSION,$periodKey]);$previous=[];foreach($stmt->fetchAll() as $row)$previous[$row['profile_id']]=(int)$row['rank_position'];
            $rows=p50_mr_period_rows($loaded,$periodKey,$hours,$now,$previous);$allRows[$periodKey]=$rows;
            $periodSummary=p50_mr_period_summary($rows);$periodSummaries[$periodKey]=$periodSummary;
            $pdo->prepare("DELETE FROM p50_metric_ranking_current WHERE algorithm_version=? AND period_key=?")->execute([P50_MR_ALGORITHM_VERSION,$periodKey]);
            $insert=$pdo->prepare("INSERT INTO p50_metric_ranking_current(algorithm_version,period_key,profile_id,run_uuid,rank_position,score,base_score,confidence,coverage,classable,editorial_eligible,platform_count,content_count,capture_count,latest_capture_at,components_json,raw_features_json,exclusion_reasons_json,previous_rank,rank_delta,calculated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $snapshot=$pdo->prepare("INSERT INTO p50_metric_ranking_snapshots(run_uuid,algorithm_version,period_key,profile_id,rank_position,score,confidence,coverage,previous_rank,rank_delta,captured_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
            foreach($rows as $row){
                $insert->execute([P50_MR_ALGORITHM_VERSION,$periodKey,$row['profileId'],$runUuid,$row['rank'],$row['score'],$row['baseScore'],$row['confidence'],$row['coverage'],$row['classable']?1:0,$row['editorialEligible']?1:0,$row['platformCount'],$row['contentCount'],$row['captureCount'],$row['latestCaptureAt'],p50_mr_json($row['components']),p50_mr_json($row['rawFeatures']),p50_mr_json($row['exclusionReasons']),$row['previousRank'],$row['rankDelta'],$now->format('Y-m-d H:i:s')]);$scoresWritten++;
                if($row['classable']){$classableCount++;if($row['rank']<=100)$snapshot->execute([$runUuid,P50_MR_ALGORITHM_VERSION,$periodKey,$row['profileId'],$row['rank'],$row['score'],$row['confidence'],$row['coverage'],$row['previousRank'],$row['rankDelta'],$now->format('Y-m-d H:i:s')]);}
            }
            $periodRun=$pdo->prepare("INSERT INTO p50_metric_ranking_period_runs(
                run_uuid,algorithm_version,period_key,profiles_considered,classable_count,excluded_count,
                average_score,median_score,top_score,average_confidence,average_coverage,
                threshold_excluded_count,hard_excluded_count,other_excluded_count,exclusion_summary_json,calculated_at
              ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
              ON DUPLICATE KEY UPDATE profiles_considered=VALUES(profiles_considered),classable_count=VALUES(classable_count),
                excluded_count=VALUES(excluded_count),average_score=VALUES(average_score),median_score=VALUES(median_score),
                top_score=VALUES(top_score),average_confidence=VALUES(average_confidence),average_coverage=VALUES(average_coverage),
                threshold_excluded_count=VALUES(threshold_excluded_count),hard_excluded_count=VALUES(hard_excluded_count),
                other_excluded_count=VALUES(other_excluded_count),exclusion_summary_json=VALUES(exclusion_summary_json),
                calculated_at=VALUES(calculated_at)");
            $periodRun->execute([
                $runUuid,P50_MR_ALGORITHM_VERSION,$periodKey,$periodSummary['profilesConsidered'],$periodSummary['classableCount'],
                $periodSummary['excludedCount'],$periodSummary['averageScore'],$periodSummary['medianScore'],$periodSummary['topScore'],
                $periodSummary['averageConfidence'],$periodSummary['averageCoverage'],$periodSummary['thresholdExcludedCount'],
                $periodSummary['hardExcludedCount'],$periodSummary['otherExcludedCount'],p50_mr_json($periodSummary['exclusionSummary']),
                $now->format('Y-m-d H:i:s'),
            ]);
        }
        $stmt=$pdo->prepare("UPDATE p50_metric_ranking_runs SET status='success',profiles_considered=?,classable_count=?,scores_written=?,metadata_json=?,finished_at=? WHERE run_uuid=?");
        $stmt->execute([count($loaded['profiles']),$classableCount,$scoresWritten,p50_mr_json($runMetadata),$now->format('Y-m-d H:i:s'),$runUuid]);
        $pdo->commit();return ['ok'=>true,'runUuid'=>$runUuid,'algorithmVersion'=>P50_MR_ALGORITHM_VERSION,'periods'=>array_keys($selected),'profilesConsidered'=>count($loaded['profiles']),'classableCount'=>$classableCount,'scoresWritten'=>$scoresWritten,'periodSummaries'=>$periodSummaries];
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        if($runCreated)$pdo->prepare("UPDATE p50_metric_ranking_runs SET status='failed',error_message=?,finished_at=UTC_TIMESTAMP() WHERE run_uuid=?")->execute([p50_mr_safe_error($error),$runUuid]);
        throw $error;
    }finally{try{p50_metrics_value($pdo,"SELECT RELEASE_LOCK(?)",[P50_MR_LOCK]);}catch(Throwable){}}
}

function p50_mr_calculate_if_due(PDO $pdo,DateTimeImmutable $now,int $minimumMinutes,string $dispatchId): array {
    $minimumMinutes=max(60,min(240,$minimumMinutes));
    p50_mr_ensure_schema($pdo);
    $stmt=$pdo->prepare("SELECT finished_at FROM p50_metric_ranking_runs
        WHERE algorithm_version=? AND status='success' AND finished_at IS NOT NULL
        ORDER BY finished_at DESC,id DESC LIMIT 1");
    $stmt->execute([P50_MR_ALGORITHM_VERSION]);$latest=$stmt->fetchColumn();
    if($latest){
        $finishedAt=p50_metrics_parse_utc((string)$latest);
        if($finishedAt&&$finishedAt>$now->modify("-$minimumMinutes minutes"))return [
            'ok'=>true,'skipped'=>true,'reason'=>'recent_success',
            'latestFinishedAt'=>$finishedAt->format(DATE_ATOM),'algorithmVersion'=>P50_MR_ALGORITHM_VERSION,
        ];
    }
    $result=p50_mr_calculate($pdo,array_keys(p50_mr_periods()),'cron_2h',[
        'scheduled'=>true,'cadence'=>'2h','dispatchId'=>$dispatchId,
    ]);
    return array_merge(['skipped'=>false],$result);
}

function p50_mr_read(PDO $pdo,string $period,int $limit): array {
    $periods=p50_mr_periods();if(!isset($periods[$period]))$period='2H';$limit=max(1,min(200,$limit));$schema=p50_mr_schema_status($pdo);
    if($schema['status']!=='applied')return ['algorithmVersion'=>P50_MR_ALGORITHM_VERSION,'migrationStatus'=>$schema,'latestRun'=>null,'selectedPeriod'=>$period,'summary'=>['classable'=>0,'excluded'=>0,'averageConfidence'=>0,'averageCoverage'=>0],'rows'=>[],'exclusionSummary'=>[]];
    $latest=$pdo->prepare("SELECT run_uuid,algorithm_version,trigger_type,status,periods_json,profiles_considered,classable_count,scores_written,error_message,started_at,finished_at FROM p50_metric_ranking_runs ORDER BY id DESC LIMIT 1");$latest->execute();$latestRun=$latest->fetch()?:null;
    $aggregate=$pdo->prepare("SELECT COUNT(*) total_count,COALESCE(SUM(classable),0) classable_count,COALESCE(AVG(confidence),0) average_confidence,COALESCE(AVG(coverage),0) average_coverage FROM p50_metric_ranking_current WHERE algorithm_version=? AND period_key=?");
    $aggregate->execute([P50_MR_ALGORITHM_VERSION,$period]);$totals=$aggregate->fetch()?:['total_count'=>0,'classable_count'=>0,'average_confidence'=>0,'average_coverage'=>0];
    $reasonStmt=$pdo->prepare("SELECT exclusion_reasons_json FROM p50_metric_ranking_current WHERE algorithm_version=? AND period_key=?");
    $reasonStmt->execute([P50_MR_ALGORITHM_VERSION,$period]);$exclusions=[];
    foreach($reasonStmt->fetchAll(PDO::FETCH_COLUMN) as $reasonJson)foreach(json_decode((string)$reasonJson,true)?:[] as $reason)$exclusions[$reason]=($exclusions[$reason]??0)+1;
    $stmt=$pdo->prepare("SELECT c.*,r.public_name,r.handle,r.region,r.category FROM p50_metric_ranking_current c JOIN p50_profile_registry r ON r.profile_id=c.profile_id WHERE c.algorithm_version=? AND c.period_key=? ORDER BY c.classable DESC,c.rank_position IS NULL,c.rank_position,c.score DESC,c.profile_id LIMIT ?");
    $stmt->bindValue(1,P50_MR_ALGORITHM_VERSION);$stmt->bindValue(2,$period);$stmt->bindValue(3,$limit,PDO::PARAM_INT);$stmt->execute();$rows=[];
    foreach($stmt->fetchAll() as $row){$reasons=json_decode($row['exclusion_reasons_json'],true)?:[];$rows[]=['profileId'=>$row['profile_id'],'name'=>$row['public_name'],'handle'=>$row['handle'],'region'=>$row['region'],'category'=>$row['category'],'rank'=>$row['rank_position']===null?null:(int)$row['rank_position'],'previousRank'=>$row['previous_rank']===null?null:(int)$row['previous_rank'],'rankDelta'=>$row['rank_delta']===null?null:(int)$row['rank_delta'],'score'=>$row['score']===null?null:(float)$row['score'],'baseScore'=>$row['base_score']===null?null:(float)$row['base_score'],'confidence'=>(float)$row['confidence'],'coverage'=>(float)$row['coverage'],'classable'=>(bool)$row['classable'],'editorialEligible'=>(bool)$row['editorial_eligible'],'platformCount'=>(int)$row['platform_count'],'contentCount'=>(int)$row['content_count'],'captureCount'=>(int)$row['capture_count'],'latestCaptureAt'=>$row['latest_capture_at'],'components'=>json_decode($row['components_json'],true)?:[],'exclusionReasons'=>$reasons];}
    $total=(int)$totals['total_count'];$classable=(int)$totals['classable_count'];
    return ['algorithmVersion'=>P50_MR_ALGORITHM_VERSION,'migrationStatus'=>$schema,'latestRun'=>$latestRun,'selectedPeriod'=>$period,'summary'=>['classable'=>$classable,'excluded'=>$total-$classable,'averageConfidence'=>(float)$totals['average_confidence'],'averageCoverage'=>(float)$totals['average_coverage']],'rows'=>$rows,'exclusionSummary'=>$exclusions];
}
