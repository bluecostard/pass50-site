<?php
declare(strict_types=1);

require_once __DIR__.'/metrics-schema-core.php';

const P50_CONTENT_INTELLIGENCE_VERSION='CONTENT-INTELLIGENCE-V1.0';

function p50_ci_periods(): array {
    return ['2h'=>7200,'24h'=>86400,'48h'=>172800,'7d'=>604800,'15d'=>1296000];
}

function p50_ci_uuid(): string {
    $bytes=random_bytes(16);
    $bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);
    $bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
    $hex=bin2hex($bytes);
    return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
}

function p50_ci_json(array $value): string {
    return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}

function p50_ci_decode(?string $value): array {
    if(!$value)return [];
    $decoded=json_decode($value,true);
    return is_array($decoded)?$decoded:[];
}

function p50_ci_ensure_schema(PDO $pdo): void {
    p50_metrics_ensure_schema($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS p50_news_items (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      news_key CHAR(64) CHARACTER SET ascii NOT NULL,
      profile_id VARCHAR(100) NOT NULL,
      content_id BIGINT UNSIGNED NULL,
      platform VARCHAR(32) NOT NULL,
      item_type VARCHAR(32) NOT NULL,
      canonical_url TEXT NOT NULL,
      url_hash CHAR(64) CHARACTER SET ascii NOT NULL,
      title VARCHAR(500) NOT NULL DEFAULT '',
      thumbnail_url TEXT NULL,
      source_published_at DATETIME NULL,
      source_type VARCHAR(64) NOT NULL,
      confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
      validation_status VARCHAR(24) NOT NULL DEFAULT 'published',
      is_official TINYINT(1) NOT NULL DEFAULT 0,
      metadata_json LONGTEXT NOT NULL,
      first_seen_at DATETIME NOT NULL,
      last_seen_at DATETIME NOT NULL,
      pass50_published_at DATETIME NULL,
      expires_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_p50_news_key(news_key),
      INDEX idx_p50_news_profile_time(profile_id,source_published_at),
      INDEX idx_p50_news_status_time(validation_status,pass50_published_at),
      INDEX idx_p50_news_content(content_id),
      INDEX idx_p50_news_expiry(expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS p50_content_trend_runs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      run_uuid CHAR(36) CHARACTER SET ascii NOT NULL,
      version VARCHAR(64) NOT NULL,
      status VARCHAR(24) NOT NULL DEFAULT 'running',
      contents_considered INT UNSIGNED NOT NULL DEFAULT 0,
      rows_written INT UNSIGNED NOT NULL DEFAULT 0,
      details_json LONGTEXT NOT NULL,
      started_at DATETIME NOT NULL,
      finished_at DATETIME NULL,
      UNIQUE KEY uq_p50_content_trend_run(run_uuid),
      INDEX idx_p50_content_trend_run_status(status,finished_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS p50_content_trend_current (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      period_key VARCHAR(8) NOT NULL,
      content_id BIGINT UNSIGNED NOT NULL,
      profile_id VARCHAR(100) NOT NULL,
      platform VARCHAR(32) NOT NULL,
      rank_position INT UNSIGNED NOT NULL,
      previous_rank INT UNSIGNED NULL,
      rank_delta INT NULL,
      score DECIMAL(6,2) NOT NULL,
      raw_score DECIMAL(16,6) NOT NULL,
      confidence DECIMAL(6,2) NOT NULL,
      view_delta BIGINT UNSIGNED NOT NULL DEFAULT 0,
      interaction_delta BIGINT UNSIGNED NOT NULL DEFAULT 0,
      share_delta BIGINT UNSIGNED NOT NULL DEFAULT 0,
      velocity DECIMAL(16,6) NOT NULL DEFAULT 0,
      acceleration DECIMAL(12,6) NOT NULL DEFAULT 0,
      follower_count BIGINT UNSIGNED NULL,
      cluster_platform_count INT UNSIGNED NOT NULL DEFAULT 1,
      badge VARCHAR(16) NOT NULL DEFAULT 'RISING',
      run_uuid CHAR(36) CHARACTER SET ascii NOT NULL,
      calculated_at DATETIME NOT NULL,
      metadata_json LONGTEXT NOT NULL,
      UNIQUE KEY uq_p50_content_trend_period_content(period_key,content_id),
      INDEX idx_p50_content_trend_period_rank(period_key,rank_position),
      INDEX idx_p50_content_trend_profile(period_key,profile_id),
      INDEX idx_p50_content_trend_run(run_uuid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function p50_ci_table_ready(PDO $pdo): bool {
    return p50_metrics_table_exists($pdo,'p50_news_items')&&p50_metrics_table_exists($pdo,'p50_content_trend_current');
}

function p50_ci_trim(string $value,int $limit): string {
    $value=trim(preg_replace('/\s+/u',' ',$value)??'');
    return function_exists('mb_substr')?mb_substr($value,0,$limit,'UTF-8'):substr($value,0,$limit);
}

function p50_ci_thumbnail(string $platform,string $url,?string $platformContentId,array $metadata=[]): ?string {
    foreach(['thumbnailUrl','thumbnail','coverUrl','image'] as $key){
        $candidate=trim((string)($metadata[$key]??''));
        if($candidate!==''&&filter_var($candidate,FILTER_VALIDATE_URL))return $candidate;
    }
    if($platform==='YouTube'){
        $id=trim((string)$platformContentId);
        if($id===''&&preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|shorts/))([A-Za-z0-9_-]{6,})~i',$url,$m))$id=$m[1];
        if($id!=='')return 'https://i.ytimg.com/vi/'.rawurlencode($id).'/hqdefault.jpg';
    }
    return null;
}

function p50_ci_news_key(string $profileId,string $url): string {
    return hash('sha256',trim($profileId).'|'.p50_metrics_normalize_url($url));
}

function p50_ci_upsert_news(PDO $pdo,array $item): array {
    $profileId=trim((string)($item['profileId']??''));
    $url=p50_metrics_normalize_url((string)($item['url']??''));
    if($profileId===''||$url==='')throw new InvalidArgumentException('Actualité sans profil ou URL.');
    $now=(string)($item['observedAt']??gmdate('Y-m-d H:i:s'));
    $publishedAt=!empty($item['publishedAt'])?p50_metrics_timestamp((string)$item['publishedAt']):null;
    $expiresAt=!empty($item['expiresAt'])?p50_metrics_timestamp((string)$item['expiresAt']):null;
    $status=(string)($item['validationStatus']??'published');
    $pass50Published=$status==='published'?$now:null;
    $metadata=(array)($item['metadata']??[]);
    p50_metrics_assert_safe($metadata,'news.metadata');
    $stmt=$pdo->prepare("INSERT INTO p50_news_items(
      news_key,profile_id,content_id,platform,item_type,canonical_url,url_hash,title,thumbnail_url,
      source_published_at,source_type,confidence,validation_status,is_official,metadata_json,
      first_seen_at,last_seen_at,pass50_published_at,expires_at
    ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
      content_id=COALESCE(VALUES(content_id),content_id),platform=VALUES(platform),item_type=VALUES(item_type),
      title=VALUES(title),thumbnail_url=COALESCE(NULLIF(VALUES(thumbnail_url),''),thumbnail_url),
      source_published_at=COALESCE(VALUES(source_published_at),source_published_at),source_type=VALUES(source_type),
      confidence=GREATEST(confidence,VALUES(confidence)),validation_status=VALUES(validation_status),
      is_official=GREATEST(is_official,VALUES(is_official)),metadata_json=VALUES(metadata_json),
      last_seen_at=VALUES(last_seen_at),pass50_published_at=COALESCE(pass50_published_at,VALUES(pass50_published_at)),
      expires_at=COALESCE(VALUES(expires_at),expires_at)");
    $key=p50_ci_news_key($profileId,$url);
    $stmt->execute([
        $key,$profileId,$item['contentId']??null,(string)($item['platform']??'Web'),(string)($item['itemType']??'article'),
        $url,hash('sha256',$url),p50_ci_trim((string)($item['title']??''),500),$item['thumbnailUrl']??null,
        $publishedAt,(string)($item['sourceType']??'unknown'),max(0,min(100,(int)($item['confidence']??0))),
        $status,!empty($item['isOfficial'])?1:0,p50_ci_json($metadata),$now,$now,$pass50Published,$expiresAt,
    ]);
    $select=$pdo->prepare("SELECT id,news_key,content_id FROM p50_news_items WHERE news_key=? LIMIT 1");
    $select->execute([$key]);
    return $select->fetch()?:['id'=>0,'news_key'=>$key,'content_id'=>$item['contentId']??null];
}

function p50_ci_sync_official_news(PDO $pdo,int $limit=600): array {
    p50_ci_ensure_schema($pdo);
    $limit=max(1,min(2000,$limit));
    $sql="SELECT c.id,c.profile_id,c.platform,c.platform_content_id,c.content_type,c.canonical_url,c.title,
      c.published_at,c.confidence,c.source_type,c.metadata_json,c.last_seen_at,r.public_name
      FROM p50_metric_contents c
      JOIN p50_profile_registry r ON BINARY r.profile_id=BINARY c.profile_id
      WHERE c.status='active' AND r.alive=1 AND c.confidence>=70 AND c.canonical_url<>''
        AND COALESCE(c.published_at,c.last_seen_at)>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 45 DAY)
      ORDER BY COALESCE(c.published_at,c.last_seen_at) DESC,c.id DESC LIMIT ".$limit;
    $rows=$pdo->query($sql)->fetchAll();
    $created=0;$updated=0;$skipped=0;
    foreach($rows as $row){
        $url=trim((string)$row['canonical_url']);
        if($url===''||!filter_var($url,FILTER_VALIDATE_URL)){$skipped++;continue;}
        $title=p50_ci_trim((string)$row['title'],500);
        if($title==='')$title='Nouveau contenu de '.p50_ci_trim((string)$row['public_name'],180);
        $metadata=p50_ci_decode((string)$row['metadata_json']);
        $thumb=p50_ci_thumbnail((string)$row['platform'],$url,$row['platform_content_id']!==null?(string)$row['platform_content_id']:null,$metadata);
        $key=p50_ci_news_key((string)$row['profile_id'],$url);
        $exists=$pdo->prepare("SELECT id FROM p50_news_items WHERE news_key=? LIMIT 1");$exists->execute([$key]);
        $was=(bool)$exists->fetchColumn();
        p50_ci_upsert_news($pdo,[
            'profileId'=>(string)$row['profile_id'],'contentId'=>(int)$row['id'],'platform'=>(string)$row['platform'],
            'itemType'=>(string)$row['content_type'],'url'=>$url,'title'=>$title,'thumbnailUrl'=>$thumb,
            'publishedAt'=>$row['published_at']?:$row['last_seen_at'],'sourceType'=>'official_metric_content',
            'confidence'=>(int)$row['confidence'],'validationStatus'=>'published','isOfficial'=>true,
            'metadata'=>['collectorSource'=>(string)$row['source_type'],'automaticPublication'=>true,'contentIntelligenceVersion'=>P50_CONTENT_INTELLIGENCE_VERSION],
            'observedAt'=>gmdate('Y-m-d H:i:s'),'expiresAt'=>gmdate('Y-m-d H:i:s',time()+45*86400),
        ]);
        $was?$updated++:$created++;
    }
    return ['considered'=>count($rows),'created'=>$created,'updated'=>$updated,'skipped'=>$skipped];
}

function p50_ci_fingerprint(string $title): string {
    $title=function_exists('iconv')?(iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$title)?:$title):$title;
    $tokens=preg_split('/[^a-z0-9]+/i',strtolower($title),-1,PREG_SPLIT_NO_EMPTY)?:[];
    $stop=array_flip(['avec','sans','pour','dans','chez','plus','moins','cette','comme','mais','donc','alors','tout','tous','toute','video','nouveau','nouvelle','officiel','official','the','and','from','this','that','une','des','les','son','ses','sur','est','sont']);
    $tokens=array_values(array_filter($tokens,static fn($t)=>strlen($t)>=4&&!isset($stop[$t])));
    $tokens=array_slice(array_values(array_unique($tokens)),0,7);
    return $tokens?implode('-',$tokens):hash('sha256',strtolower(trim($title)));
}

function p50_ci_metric(array $capture,string $key): int {
    $value=$capture[$key]??0;
    return is_numeric($value)?max(0,(int)$value):0;
}

function p50_ci_capture_before(array $captures,int $timestamp): ?array {
    $match=null;
    foreach($captures as $capture){
        if((int)$capture['_ts']<=$timestamp)$match=$capture;else break;
    }
    return $match;
}

function p50_ci_delta(array $latest,array $base,string $key): int {
    return max(0,p50_ci_metric($latest,$key)-p50_ci_metric($base,$key));
}

function p50_ci_calculate_trends(PDO $pdo,?DateTimeImmutable $now=null): array {
    p50_ci_ensure_schema($pdo);
    $now=$now?:new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $nowTs=$now->getTimestamp();$nowSql=$now->format('Y-m-d H:i:s');$runUuid=p50_ci_uuid();
    $pdo->prepare("INSERT INTO p50_content_trend_runs(run_uuid,version,status,details_json,started_at) VALUES(?,?,'running','{}',?)")
        ->execute([$runUuid,P50_CONTENT_INTELLIGENCE_VERSION,$nowSql]);
    try{
        $contents=$pdo->query("SELECT c.id,c.account_id,c.profile_id,c.platform,c.platform_content_id,c.content_type,
          c.canonical_url,c.title,c.published_at,c.first_seen_at,c.last_seen_at,c.metadata_json,r.public_name
          FROM p50_metric_contents c JOIN p50_profile_registry r ON BINARY r.profile_id=BINARY c.profile_id
          WHERE c.status='active' AND r.alive=1 AND c.canonical_url<>''
            AND COALESCE(c.published_at,c.first_seen_at)>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 16 DAY)
          ORDER BY COALESCE(c.published_at,c.first_seen_at) DESC,c.id DESC LIMIT 800")->fetchAll();
        if(!$contents){
            $pdo->prepare("UPDATE p50_content_trend_runs SET status='success',details_json=?,finished_at=? WHERE run_uuid=?")
                ->execute([p50_ci_json(['reason'=>'no_contents']),$nowSql,$runUuid]);
            return ['runUuid'=>$runUuid,'contentsConsidered'=>0,'rowsWritten'=>0,'periods'=>[]];
        }
        $contentIds=array_map(static fn($r)=>(int)$r['id'],$contents);
        $placeholders=implode(',',array_fill(0,count($contentIds),'?'));
        $captureStmt=$pdo->prepare("SELECT content_id,observed_at,views,likes,comments,shares,saves,usable_metric_count,confidence
          FROM p50_metric_captures WHERE quality_status='usable' AND content_id IN ($placeholders)
            AND observed_at>=DATE_SUB(?,INTERVAL 16 DAY) ORDER BY content_id,observed_at,id");
        $captureStmt->execute(array_merge($contentIds,[$nowSql]));
        $capturesByContent=[];
        foreach($captureStmt->fetchAll() as $capture){
            $capture['_ts']=strtotime((string)$capture['observed_at'].' UTC')?:0;
            $capturesByContent[(int)$capture['content_id']][]=$capture;
        }
        $accountIds=array_values(array_unique(array_map(static fn($r)=>(int)$r['account_id'],$contents)));
        $followers=[];
        if($accountIds){
            $accountPlaceholders=implode(',',array_fill(0,count($accountIds),'?'));
            $followerStmt=$pdo->prepare("SELECT account_id,followers,observed_at FROM p50_metric_captures
              WHERE content_id IS NULL AND followers IS NOT NULL AND quality_status='usable' AND account_id IN ($accountPlaceholders)
              ORDER BY account_id,observed_at DESC,id DESC");
            $followerStmt->execute($accountIds);
            foreach($followerStmt->fetchAll() as $row){$accountId=(int)$row['account_id'];if(!isset($followers[$accountId]))$followers[$accountId]=(int)$row['followers'];}
        }
        $previous=[];
        foreach($pdo->query("SELECT period_key,content_id,rank_position FROM p50_content_trend_current")->fetchAll() as $row)$previous[(string)$row['period_key']][(int)$row['content_id']]=(int)$row['rank_position'];
        $periodResults=[];$totalWritten=0;
        $pdo->beginTransaction();
        foreach(p50_ci_periods() as $periodKey=>$seconds){
            $cutoff=$nowTs-$seconds;$halfCutoff=$nowTs-(int)round($seconds/2);$hours=max(.25,$seconds/3600);
            $clusterPlatforms=[];$prepared=[];
            foreach($contents as $content){
                $id=(int)$content['id'];$captures=$capturesByContent[$id]??[];
                if(!$captures)continue;
                $latest=end($captures);if(!$latest||((int)$latest['_ts']<$cutoff))continue;
                $base=p50_ci_capture_before($captures,$cutoff)??$captures[0];
                $mid=p50_ci_capture_before($captures,$halfCutoff)??$base;
                if((int)$latest['_ts']<=(int)$base['_ts'])continue;
                $views=p50_ci_delta($latest,$base,'views');$likes=p50_ci_delta($latest,$base,'likes');
                $comments=p50_ci_delta($latest,$base,'comments');$shares=p50_ci_delta($latest,$base,'shares');$saves=p50_ci_delta($latest,$base,'saves');
                $interactions=$likes+4*$comments+6*$shares+5*$saves;
                if($views===0&&$interactions===0)continue;
                $recentViews=p50_ci_delta($latest,$mid,'views');$priorViews=p50_ci_delta($mid,$base,'views');
                $recentInteractions=p50_ci_delta($latest,$mid,'likes')+4*p50_ci_delta($latest,$mid,'comments')+6*p50_ci_delta($latest,$mid,'shares')+5*p50_ci_delta($latest,$mid,'saves');
                $priorInteractions=p50_ci_delta($mid,$base,'likes')+4*p50_ci_delta($mid,$base,'comments')+6*p50_ci_delta($mid,$base,'shares')+5*p50_ci_delta($mid,$base,'saves');
                $recentRate=($recentViews+3*$recentInteractions)/max(.25,$hours/2);
                $priorRate=($priorViews+3*$priorInteractions)/max(.25,$hours/2);
                $acceleration=max(-1,min(5,(($recentRate+1)/($priorRate+1))-1));
                $followerCount=$followers[(int)$content['account_id']]??null;
                $normalizer=pow(max(1000,(int)($followerCount??1000)),.55);
                $viewRate=$views/$hours;$interactionRate=$interactions/$hours;$normalizedRate=($views/$normalizer)/$hours;
                $velocity=$viewRate+3*$interactionRate;
                $raw=.42*log(1+$viewRate)+.32*log(1+$interactionRate)+.14*log(1+$normalizedRate)+.12*max(0,$acceleration);
                $fingerprint=p50_ci_fingerprint((string)$content['title']);
                $clusterPlatforms[$fingerprint][(string)$content['platform']]=true;
                $relevantCaptures=array_filter($captures,static fn($c)=>(int)$c['_ts']>=$cutoff);
                $confidence=min(100,35+count($relevantCaptures)*12+((int)($latest['usable_metric_count']??0))*5);
                $publishedTs=strtotime((string)($content['published_at']?:$content['first_seen_at']).' UTC')?:$nowTs;
                $prepared[]=['content'=>$content,'raw'=>$raw,'confidence'=>$confidence,'views'=>$views,'interactions'=>$interactions,
                    'shares'=>$shares,'velocity'=>$velocity,'acceleration'=>$acceleration,'followers'=>$followerCount,
                    'fingerprint'=>$fingerprint,'ageHours'=>max(0,($nowTs-$publishedTs)/3600)];
            }
            usort($prepared,static fn($a,$b)=>$b['raw']<=>$a['raw']);
            $maxRaw=$prepared?(float)$prepared[0]['raw']:0;$count=count($prepared);$rows=[];
            foreach($prepared as $index=>$item){
                $percentile=$count<=1?100:100*(1-$index/($count-1));
                $intensity=$maxRaw>0?min(100,100*$item['raw']/$maxRaw):0;
                $score=round(.65*$percentile+.35*$intensity,2);
                $clusterCount=count($clusterPlatforms[$item['fingerprint']]??[]);
                $shareRate=$item['views']>0?1000*$item['shares']/$item['views']:0;
                $badge=$score>=85&&($clusterCount>=3||$shareRate>=8)?'VIRAL':($item['ageHours']<=6&&$score>=55?'NEW':($score>=70?'HOT':'RISING'));
                $rows[]=$item+['score'=>$score,'clusterCount'=>$clusterCount,'badge'=>$badge,'rank'=>$index+1];
            }
            $pdo->prepare("DELETE FROM p50_content_trend_current WHERE period_key=?")->execute([$periodKey]);
            $insert=$pdo->prepare("INSERT INTO p50_content_trend_current(period_key,content_id,profile_id,platform,rank_position,previous_rank,rank_delta,
              score,raw_score,confidence,view_delta,interaction_delta,share_delta,velocity,acceleration,follower_count,cluster_platform_count,badge,run_uuid,calculated_at,metadata_json)
              VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach(array_slice($rows,0,100) as $row){
                $content=$row['content'];$previousRank=$previous[$periodKey][(int)$content['id']]??null;
                $rank=(int)$row['rank'];$delta=$previousRank===null?null:$previousRank-$rank;
                $insert->execute([$periodKey,(int)$content['id'],(string)$content['profile_id'],(string)$content['platform'],$rank,$previousRank,$delta,
                    $row['score'],$row['raw'],$row['confidence'],$row['views'],$row['interactions'],$row['shares'],$row['velocity'],$row['acceleration'],
                    $row['followers'],$row['clusterCount'],$row['badge'],$runUuid,$nowSql,p50_ci_json(['fingerprint'=>$row['fingerprint'],'ageHours'=>round($row['ageHours'],2)])]);
                $totalWritten++;
            }
            $periodResults[$periodKey]=['candidates'=>$count,'stored'=>min(100,$count),'topScore'=>$rows[0]['score']??null];
        }
        $pdo->commit();
        $details=['periods'=>$periodResults,'contentCount'=>count($contents),'automaticNews'=>true,'publicStateWrites'=>0];
        $pdo->prepare("UPDATE p50_content_trend_runs SET status='success',contents_considered=?,rows_written=?,details_json=?,finished_at=? WHERE run_uuid=?")
            ->execute([count($contents),$totalWritten,p50_ci_json($details),$nowSql,$runUuid]);
        return ['runUuid'=>$runUuid,'contentsConsidered'=>count($contents),'rowsWritten'=>$totalWritten,'periods'=>$periodResults,'publicStateWrites'=>0];
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        $pdo->prepare("UPDATE p50_content_trend_runs SET status='failed',details_json=?,finished_at=? WHERE run_uuid=?")
            ->execute([p50_ci_json(['error'=>substr($error->getMessage(),0,220)]),$nowSql,$runUuid]);
        throw $error;
    }
}

function p50_ci_refresh(PDO $pdo): array {
    $news=p50_ci_sync_official_news($pdo);
    $trends=p50_ci_calculate_trends($pdo);
    return ['ok'=>true,'version'=>P50_CONTENT_INTELLIGENCE_VERSION,'news'=>$news,'trends'=>$trends,'publicStateWrites'=>0];
}
