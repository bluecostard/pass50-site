<?php
declare(strict_types=1);

const P50_METRICS_SCHEMA_VERSION=1;
const P50_METRICS_MIGRATION_KEY='metrics_canonical_schema_v1';
const P50_METRICS_LOCK='pass50_metrics_canonical_schema_v1';

function p50_metrics_json(array $value): string {
    return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}

function p50_metrics_normalize_url(?string $url): string {
    $url=trim((string)$url);if($url==='')return '';
    $parts=parse_url($url);if(!$parts||empty($parts['host']))return $url;
    $host=strtolower((string)$parts['host']);if(str_starts_with($host,'www.'))$host=substr($host,4);
    $path=preg_replace('#/+#','/',(string)($parts['path']??'/'))?:'/';$path=$path==='/'?'/':rtrim($path,'/');
    $query=[];
    if(!empty($parts['query'])){
        parse_str((string)$parts['query'],$raw);
        foreach($raw as $key=>$value){
            if(preg_match('/^(utm_|fbclid$|gclid$|ref$|source$|feature$|si$|token$|key$|secret$|password$|cookie$)/i',(string)$key))continue;
            $query[(string)$key]=$value;
        }
        ksort($query);
    }
    return 'https://'.$host.$path.($query?'?'.http_build_query($query):'');
}

function p50_metrics_normalize_handle(?string $handle): string {
    return strtolower(ltrim(trim((string)$handle),'@'));
}

function p50_metrics_account_key(string $profileId,string $platform,?string $platformAccountId=null,?string $handle=null,?string $canonicalUrl=null): string {
    $identity=trim((string)$platformAccountId);
    if($identity!=='')$identity='id:'.strtolower($identity);
    elseif(($normalized=p50_metrics_normalize_handle($handle))!=='')$identity='handle:'.$normalized;
    else $identity='url:'.p50_metrics_normalize_url($canonicalUrl);
    return hash('sha256',trim($profileId).'|'.strtolower(trim($platform)).'|'.$identity);
}

function p50_metrics_content_key(string $accountKey,string $platform,?string $platformContentId=null,?string $canonicalUrl=null): string {
    $identity=trim((string)$platformContentId);
    $identity=$identity!==''?'id:'.strtolower($identity):'url:'.p50_metrics_normalize_url($canonicalUrl);
    return hash('sha256',$accountKey.'|'.strtolower(trim($platform)).'|'.$identity);
}

function p50_metrics_timestamp(string|DateTimeInterface $value): string {
    if($value instanceof DateTimeInterface)return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $timestamp=strtotime(trim($value));if($timestamp===false)throw new InvalidArgumentException('Date d’observation invalide.');
    return gmdate('Y-m-d H:i:s',$timestamp);
}

function p50_metrics_signature_value(mixed $value): array {
    if(is_array($value)){
        $normalized=[];
        foreach($value as $key=>$item)$normalized[(string)$key]=p50_metrics_signature_value($item);
        ksort($normalized,SORT_STRING);
        return ['type'=>'array','value'=>$normalized];
    }
    if($value===null)return ['type'=>'null'];
    return ['type'=>gettype($value),'value'=>$value];
}

function p50_metrics_capture_key(int|string $accountId,int|string|null $contentId,string $platform,string $sourceType,string|DateTimeInterface $observedAt,array $metrics): string {
    $signature=p50_metrics_signature_value($metrics);
    return hash('sha256',implode('|',[(string)$accountId,(string)($contentId??''),strtolower(trim($platform)),strtolower(trim($sourceType)),p50_metrics_timestamp($observedAt),p50_metrics_json($signature)]));
}

function p50_metrics_assert_safe(array $value,string $path='payload'): void {
    foreach($value as $key=>$item){
        $name=(string)$key;
        if(preg_match('/(?:token|secret|password|passwd|cookie|authorization|session)/i',$name))throw new InvalidArgumentException('Champ sensible interdit dans '.$path.'.');
        if(is_array($item))p50_metrics_assert_safe($item,$path.'.'.$name);
        elseif(is_string($item)&&preg_match('/(?:Bearer\s+[A-Za-z0-9._~+\/=-]+|(?:token|secret|password|cookie)\s*[=:]\s*\S+)/i',$item))throw new InvalidArgumentException('Valeur sensible interdite dans '.$path.'.');
    }
}

function p50_metrics_provenance(array $input): array {
    $provenance=(array)($input['provenance']??[]);
    if(!$provenance)throw new InvalidArgumentException('Provenance obligatoire.');
    p50_metrics_assert_safe($provenance,'provenance');return $provenance;
}

function p50_metrics_schema_sql(): array {
    return [
        "CREATE TABLE IF NOT EXISTS p50_metric_schema_migrations (
          migration_key VARCHAR(100) PRIMARY KEY,schema_version INT UNSIGNED NOT NULL,checksum CHAR(64) NOT NULL,
          status VARCHAR(24) NOT NULL,details_json LONGTEXT NOT NULL,applied_at DATETIME NULL,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_p50_metric_migration_status(status,updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS p50_metric_accounts (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,account_key CHAR(64) CHARACTER SET ascii NOT NULL,
          profile_id VARCHAR(100) NOT NULL,platform VARCHAR(32) NOT NULL,platform_account_id VARCHAR(191) NULL,
          handle VARCHAR(191) NOT NULL DEFAULT '',canonical_url TEXT NOT NULL,url_hash CHAR(64) CHARACTER SET ascii NOT NULL,
          status VARCHAR(24) NOT NULL DEFAULT 'active',confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
          source_type VARCHAR(64) NOT NULL,provenance_json LONGTEXT NOT NULL,metadata_json LONGTEXT NOT NULL,
          first_seen_at DATETIME NOT NULL,last_seen_at DATETIME NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uq_p50_metric_account_key(account_key),UNIQUE KEY uq_p50_metric_account_profile_platform(profile_id,platform),
          INDEX idx_p50_metric_account_profile(profile_id),INDEX idx_p50_metric_account_platform(platform),
          INDEX idx_p50_metric_account_status(status),INDEX idx_p50_metric_account_seen(last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS p50_metric_contents (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,content_key CHAR(64) CHARACTER SET ascii NOT NULL,
          account_id BIGINT UNSIGNED NOT NULL,profile_id VARCHAR(100) NOT NULL,platform VARCHAR(32) NOT NULL,
          platform_content_id VARCHAR(191) NULL,content_type VARCHAR(24) NOT NULL DEFAULT 'unknown',
          canonical_url TEXT NOT NULL,url_hash CHAR(64) CHARACTER SET ascii NOT NULL,title VARCHAR(500) NOT NULL DEFAULT '',
          published_at DATETIME NULL,status VARCHAR(24) NOT NULL DEFAULT 'active',confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
          source_type VARCHAR(64) NOT NULL,provenance_json LONGTEXT NOT NULL,metadata_json LONGTEXT NOT NULL,
          first_seen_at DATETIME NOT NULL,last_seen_at DATETIME NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uq_p50_metric_content_key(content_key),UNIQUE KEY uq_p50_metric_content_platform_id(account_id,platform_content_id),
          INDEX idx_p50_metric_content_account(account_id),INDEX idx_p50_metric_content_profile(profile_id),
          INDEX idx_p50_metric_content_platform(platform),INDEX idx_p50_metric_content_status(status),
          INDEX idx_p50_metric_content_published(published_at),INDEX idx_p50_metric_content_seen(last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS p50_metric_captures (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,capture_key CHAR(64) CHARACTER SET ascii NOT NULL,
          account_id BIGINT UNSIGNED NOT NULL,content_id BIGINT UNSIGNED NULL,profile_id VARCHAR(100) NOT NULL,
          platform VARCHAR(32) NOT NULL,collector VARCHAR(64) NOT NULL,source_type VARCHAR(64) NOT NULL,
          source_reference TEXT NULL,observed_at DATETIME NOT NULL,captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          followers BIGINT UNSIGNED NULL,views BIGINT UNSIGNED NULL,likes BIGINT UNSIGNED NULL,comments BIGINT UNSIGNED NULL,
          shares BIGINT UNSIGNED NULL,saves BIGINT UNSIGNED NULL,live_viewers BIGINT UNSIGNED NULL,
          usable_metric_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
          quality_status VARCHAR(24) NOT NULL DEFAULT 'usable',metrics_json LONGTEXT NOT NULL,
          provenance_json LONGTEXT NOT NULL,raw_payload_hash CHAR(64) CHARACTER SET ascii NULL,
          run_uuid CHAR(36) CHARACTER SET ascii NULL,metadata_json LONGTEXT NOT NULL,
          UNIQUE KEY uq_p50_metric_capture_key(capture_key),INDEX idx_p50_metric_capture_account_time(account_id,observed_at),
          INDEX idx_p50_metric_capture_content_time(content_id,observed_at),INDEX idx_p50_metric_capture_profile_time(profile_id,observed_at),
          INDEX idx_p50_metric_capture_platform_time(platform,observed_at),INDEX idx_p50_metric_capture_quality(quality_status,captured_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS p50_metric_jobs (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,job_uuid CHAR(36) CHARACTER SET ascii NOT NULL,
          idempotency_key CHAR(64) CHARACTER SET ascii NOT NULL,collector VARCHAR(64) NOT NULL,platform VARCHAR(32) NULL,
          scope_type VARCHAR(32) NOT NULL,scope_id VARCHAR(191) NULL,priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
          status VARCHAR(24) NOT NULL DEFAULT 'pending',scheduled_at DATETIME NOT NULL,next_attempt_at DATETIME NULL,
          attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 3,
          locked_at DATETIME NULL,lock_token CHAR(64) CHARACTER SET ascii NULL,payload_json LONGTEXT NOT NULL,
          last_error VARCHAR(500) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uq_p50_metric_job_uuid(job_uuid),UNIQUE KEY uq_p50_metric_job_idempotency(idempotency_key),
          INDEX idx_p50_metric_job_queue(status,priority,scheduled_at,next_attempt_at),INDEX idx_p50_metric_job_lock(locked_at,lock_token),
          INDEX idx_p50_metric_job_scope(scope_type,scope_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS p50_metric_runs (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,run_uuid CHAR(36) CHARACTER SET ascii NOT NULL,
          job_uuid CHAR(36) CHARACTER SET ascii NULL,collector VARCHAR(64) NOT NULL,platform VARCHAR(32) NULL,
          trigger_type VARCHAR(32) NOT NULL,status VARCHAR(24) NOT NULL DEFAULT 'running',
          accounts_processed INT UNSIGNED NOT NULL DEFAULT 0,contents_found INT UNSIGNED NOT NULL DEFAULT 0,
          captures_recorded INT UNSIGNED NOT NULL DEFAULT 0,duplicates_skipped INT UNSIGNED NOT NULL DEFAULT 0,
          quarantined_count INT UNSIGNED NOT NULL DEFAULT 0,error_count INT UNSIGNED NOT NULL DEFAULT 0,
          error_message VARCHAR(500) NULL,metadata_json LONGTEXT NOT NULL,started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          finished_at DATETIME NULL,UNIQUE KEY uq_p50_metric_run_uuid(run_uuid),
          INDEX idx_p50_metric_run_job(job_uuid),INDEX idx_p50_metric_run_status(status,started_at),
          INDEX idx_p50_metric_run_collector(collector,platform,started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

function p50_metrics_table_columns(): array {
    return [
      'p50_metric_accounts'=>[
        'id'=>"BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE FIRST",'account_key'=>"CHAR(64) CHARACTER SET ascii NULL",
        'platform_account_id'=>"VARCHAR(191) NULL",'handle'=>"VARCHAR(191) NOT NULL DEFAULT ''",'canonical_url'=>"TEXT NULL",
        'url_hash'=>"CHAR(64) CHARACTER SET ascii NULL",'confidence'=>"TINYINT UNSIGNED NOT NULL DEFAULT 0",
        'source_type'=>"VARCHAR(64) NOT NULL DEFAULT 'legacy_unknown'",'provenance_json'=>"LONGTEXT NULL",'metadata_json'=>"LONGTEXT NULL",
        'first_seen_at'=>"DATETIME NULL",'last_seen_at'=>"DATETIME NULL",
      ],
      'p50_metric_runs'=>[
        'job_uuid'=>"CHAR(36) CHARACTER SET ascii NULL",'collector'=>"VARCHAR(64) NOT NULL DEFAULT 'legacy_metrics'",
        'platform'=>"VARCHAR(32) NULL",'trigger_type'=>"VARCHAR(32) NOT NULL DEFAULT 'legacy'",
        'accounts_processed'=>"INT UNSIGNED NOT NULL DEFAULT 0",'contents_found'=>"INT UNSIGNED NOT NULL DEFAULT 0",
        'captures_recorded'=>"INT UNSIGNED NOT NULL DEFAULT 0",'duplicates_skipped'=>"INT UNSIGNED NOT NULL DEFAULT 0",
        'quarantined_count'=>"INT UNSIGNED NOT NULL DEFAULT 0",'error_count'=>"INT UNSIGNED NOT NULL DEFAULT 0",
        'metadata_json'=>"LONGTEXT NULL",
      ],
    ];
}

function p50_metrics_column_exists(PDO $pdo,string $table,string $column): bool {
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->execute([$table,$column]);return (int)$stmt->fetchColumn()>0;
}

function p50_metrics_table_exists(PDO $pdo,string $table): bool {
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $stmt->execute([$table]);return (int)$stmt->fetchColumn()>0;
}

function p50_metrics_index_exists(PDO $pdo,string $table,string $index): bool {
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?");
    $stmt->execute([$table,$index]);return (int)$stmt->fetchColumn()>0;
}

function p50_metrics_ensure_schema(PDO $pdo): array {
    $got=(int)p50_metrics_value($pdo,"SELECT GET_LOCK(?,10)",[P50_METRICS_LOCK]);
    if($got!==1)throw new RuntimeException('Migration métrique déjà en cours.');
    $sql=p50_metrics_schema_sql();$checksum=hash('sha256',implode("\n",$sql));
    try{
        $pdo->exec($sql[0]);
        $stmt=$pdo->prepare("INSERT INTO p50_metric_schema_migrations(migration_key,schema_version,checksum,status,details_json,applied_at)
          VALUES(?,?,?,'applying','{}',NULL) ON DUPLICATE KEY UPDATE schema_version=VALUES(schema_version),checksum=VALUES(checksum),status='applying',updated_at=NOW()");
        $stmt->execute([P50_METRICS_MIGRATION_KEY,P50_METRICS_SCHEMA_VERSION,$checksum]);
        foreach(array_slice($sql,1) as $statement)$pdo->exec($statement);
        foreach(p50_metrics_table_columns() as $table=>$columns)foreach($columns as $column=>$definition){
            if(!p50_metrics_column_exists($pdo,$table,$column))$pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
        if(p50_metrics_column_exists($pdo,'p50_metric_accounts','profile_url')){
            $pdo->exec("UPDATE p50_metric_accounts SET canonical_url=COALESCE(NULLIF(canonical_url,''),profile_url),
              url_hash=COALESCE(url_hash,SHA2(COALESCE(NULLIF(canonical_url,''),profile_url),256)),
              account_key=COALESCE(account_key,SHA2(CONCAT(profile_id,'|',LOWER(platform),'|url:',COALESCE(NULLIF(canonical_url,''),profile_url)),256)),
              provenance_json=COALESCE(provenance_json,'{\"migration\":\"legacy_metrics_v1\"}'),metadata_json=COALESCE(metadata_json,'{}'),
              first_seen_at=COALESCE(first_seen_at,created_at,NOW()),last_seen_at=COALESCE(last_seen_at,last_collected_at,updated_at,NOW())");
            $pdo->exec("ALTER TABLE p50_metric_accounts MODIFY account_key CHAR(64) CHARACTER SET ascii NOT NULL,
              MODIFY canonical_url TEXT NOT NULL,MODIFY url_hash CHAR(64) CHARACTER SET ascii NOT NULL,
              MODIFY provenance_json LONGTEXT NOT NULL,MODIFY metadata_json LONGTEXT NOT NULL,
              MODIFY first_seen_at DATETIME NOT NULL,MODIFY last_seen_at DATETIME NOT NULL");
        }
        if(p50_metrics_column_exists($pdo,'p50_metric_runs','collected_accounts')){
            $pdo->exec("UPDATE p50_metric_runs SET metadata_json=COALESCE(metadata_json,'{}')");
            $pdo->exec("ALTER TABLE p50_metric_runs MODIFY metadata_json LONGTEXT NOT NULL");
        }
        $indexes=[
          ['p50_metric_accounts','uq_p50_metric_account_key','UNIQUE KEY uq_p50_metric_account_key(account_key)'],
          ['p50_metric_accounts','uq_p50_metric_account_profile_platform','UNIQUE KEY uq_p50_metric_account_profile_platform(profile_id,platform)'],
          ['p50_metric_accounts','idx_p50_metric_account_profile','INDEX idx_p50_metric_account_profile(profile_id)'],
          ['p50_metric_accounts','idx_p50_metric_account_platform','INDEX idx_p50_metric_account_platform(platform)'],
          ['p50_metric_accounts','idx_p50_metric_account_status','INDEX idx_p50_metric_account_status(status)'],
          ['p50_metric_accounts','idx_p50_metric_account_seen','INDEX idx_p50_metric_account_seen(last_seen_at)'],
        ];
        foreach($indexes as [$table,$name,$definition])if(!p50_metrics_index_exists($pdo,$table,$name))$pdo->exec("ALTER TABLE `$table` ADD $definition");
        $triggerExists=(int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='p50_metric_captures_immutable'");
        if($triggerExists===0)$pdo->exec("CREATE TRIGGER p50_metric_captures_immutable BEFORE UPDATE ON p50_metric_captures FOR EACH ROW
          BEGIN IF NOT (OLD.capture_key<=>NEW.capture_key AND OLD.account_id<=>NEW.account_id AND OLD.content_id<=>NEW.content_id
          AND OLD.observed_at<=>NEW.observed_at AND OLD.followers<=>NEW.followers AND OLD.views<=>NEW.views AND OLD.likes<=>NEW.likes
          AND OLD.comments<=>NEW.comments AND OLD.shares<=>NEW.shares AND OLD.saves<=>NEW.saves AND OLD.live_viewers<=>NEW.live_viewers
          AND OLD.metrics_json<=>NEW.metrics_json) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Metric captures are immutable'; END IF; END");
        $details=['mode'=>'non_destructive','legacyTablesPreserved'=>true];
        $stmt=$pdo->prepare("UPDATE p50_metric_schema_migrations SET status='applied',details_json=?,applied_at=COALESCE(applied_at,NOW()),updated_at=NOW() WHERE migration_key=?");
        $stmt->execute([p50_metrics_json($details),P50_METRICS_MIGRATION_KEY]);
        return ['migrationKey'=>P50_METRICS_MIGRATION_KEY,'schemaVersion'=>P50_METRICS_SCHEMA_VERSION,'status'=>'applied','checksum'=>$checksum];
    }catch(Throwable $error){
        try{$stmt=$pdo->prepare("UPDATE p50_metric_schema_migrations SET status='error',details_json=?,updated_at=NOW() WHERE migration_key=?");$stmt->execute([p50_metrics_json(['error'=>'Migration interrompue ; reprise possible.']),P50_METRICS_MIGRATION_KEY]);}catch(Throwable){}
        throw $error;
    }finally{
        try{$stmt=$pdo->prepare("SELECT RELEASE_LOCK(?)");$stmt->execute([P50_METRICS_LOCK]);}catch(Throwable){}
    }
}

function p50_metrics_value(PDO $pdo,string $sql,array $params=[]): mixed {
    $stmt=$pdo->prepare($sql);$stmt->execute($params);return $stmt->fetchColumn();
}

function p50_metrics_uuid(): string {
    if(function_exists('uuid_v4'))return uuid_v4();
    $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);$bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($bytes),4));
}

function p50_metrics_upsert_account(PDO $pdo,array $input): array {
    $provenance=p50_metrics_provenance($input);
    p50_metrics_assert_safe((array)($input['metadata']??[]),'metadata');
    $profileId=trim((string)($input['profileId']??''));$platform=trim((string)($input['platform']??''));
    if($profileId===''||$platform==='')throw new InvalidArgumentException('Profil et plateforme obligatoires.');
    $url=p50_metrics_normalize_url($input['canonicalUrl']??'');$handle=p50_metrics_normalize_handle($input['handle']??'');
    $platformId=trim((string)($input['platformAccountId']??''));$platformId=$platformId===''?null:$platformId;
    $candidate=p50_metrics_account_key($profileId,$platform,$platformId,$handle,$url);
    $existing=$pdo->prepare("SELECT id,account_key FROM p50_metric_accounts WHERE profile_id=? AND platform=? LIMIT 1");
    $existing->execute([$profileId,$platform]);$row=$existing->fetch();
    $key=$row?(string)$row['account_key']:$candidate;$now=p50_metrics_timestamp($input['observedAt']??gmdate('c'));
    $legacyProfileUrl=p50_metrics_column_exists($pdo,'p50_metric_accounts','profile_url');
    $columns="account_key,profile_id,platform,platform_account_id,handle,canonical_url,url_hash,status,confidence,source_type,provenance_json,metadata_json,first_seen_at,last_seen_at".($legacyProfileUrl?',profile_url':'');
    $placeholders=implode(',',array_fill(0,$legacyProfileUrl?15:14,'?'));
    $stmt=$pdo->prepare("INSERT INTO p50_metric_accounts($columns)
      VALUES($placeholders)
      ON DUPLICATE KEY UPDATE platform_account_id=COALESCE(VALUES(platform_account_id),platform_account_id),
      handle=IF(VALUES(handle)<>'',VALUES(handle),handle),canonical_url=IF(VALUES(canonical_url)<>'',VALUES(canonical_url),canonical_url),
      url_hash=IF(VALUES(canonical_url)<>'',VALUES(url_hash),url_hash),status=VALUES(status),confidence=GREATEST(confidence,VALUES(confidence)),
      source_type=VALUES(source_type),provenance_json=VALUES(provenance_json),metadata_json=VALUES(metadata_json),last_seen_at=GREATEST(last_seen_at,VALUES(last_seen_at))");
    $params=[$key,$profileId,$platform,$platformId,$handle,$url,hash('sha256',$url),(string)($input['status']??'active'),max(0,min(100,(int)($input['confidence']??0))),(string)($input['sourceType']??'unknown'),p50_metrics_json($provenance),p50_metrics_json((array)($input['metadata']??[])),$now,$now];
    if($legacyProfileUrl)$params[]=$url;$stmt->execute($params);
    $id=(int)p50_metrics_value($pdo,"SELECT id FROM p50_metric_accounts WHERE profile_id=? AND platform=? LIMIT 1",[$profileId,$platform]);
    return ['id'=>$id,'accountKey'=>$key,'created'=>!$row];
}

function p50_metrics_is_content_url(string $platform,string $url): bool {
    $path=(string)(parse_url($url,PHP_URL_PATH)?:'');$query=(string)(parse_url($url,PHP_URL_QUERY)?:'');
    return match(strtolower($platform)){
      'youtube'=>(bool)preg_match('#/(?:watch|shorts|live|embed)(?:/|$)#i',$path)||str_contains($query,'v='),
      'tiktok'=>(bool)preg_match('#/@[^/]+/(?:video|photo)/\d+#i',$path),
      'instagram'=>(bool)preg_match('#/(?:p|reel|reels|tv)/[^/]+#i',$path),
      'facebook'=>(bool)preg_match('#/(?:reel|reels|videos|watch|posts)/#i',$path)||str_contains($query,'v=')||str_contains($query,'fbid='),
      'x'=>(bool)preg_match('#/status/\d+#i',$path),
      'snapchat'=>(bool)preg_match('#/(?:spotlight|story)/#i',$path),
      default=>trim($path,'/')!=='',
    };
}

function p50_metrics_upsert_content(PDO $pdo,array $input): array {
    $provenance=p50_metrics_provenance($input);p50_metrics_assert_safe((array)($input['metadata']??[]),'metadata');
    $accountId=(int)($input['accountId']??0);$account=$pdo->prepare("SELECT account_key,profile_id,platform FROM p50_metric_accounts WHERE id=? LIMIT 1");$account->execute([$accountId]);$a=$account->fetch();
    if(!$a)throw new InvalidArgumentException('Compte métrique introuvable.');
    $url=p50_metrics_normalize_url($input['canonicalUrl']??'');$platformId=trim((string)($input['platformContentId']??''));$platformId=$platformId===''?null:$platformId;
    if($platformId===null&&!p50_metrics_is_content_url((string)$a['platform'],$url))throw new InvalidArgumentException('Une page de profil ne peut pas être enregistrée comme contenu.');
    $key=p50_metrics_content_key((string)$a['account_key'],(string)$a['platform'],$platformId,$url);$now=p50_metrics_timestamp($input['observedAt']??gmdate('c'));
    $type=(string)($input['contentType']??'unknown');if(!in_array($type,['video','post','reel','short','live','unknown'],true))$type='unknown';
    $published=!empty($input['publishedAt'])?p50_metrics_timestamp($input['publishedAt']):null;
    $stmt=$pdo->prepare("INSERT INTO p50_metric_contents(content_key,account_id,profile_id,platform,platform_content_id,content_type,canonical_url,url_hash,title,published_at,status,confidence,source_type,provenance_json,metadata_json,first_seen_at,last_seen_at)
      VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE title=IF(VALUES(title)<>'',VALUES(title),title),published_at=COALESCE(published_at,VALUES(published_at)),
      status=VALUES(status),confidence=GREATEST(confidence,VALUES(confidence)),source_type=VALUES(source_type),
      provenance_json=VALUES(provenance_json),metadata_json=VALUES(metadata_json),last_seen_at=GREATEST(last_seen_at,VALUES(last_seen_at))");
    $stmt->execute([$key,$accountId,$a['profile_id'],$a['platform'],$platformId,$type,$url,hash('sha256',$url),(string)($input['title']??''),$published,(string)($input['status']??'active'),max(0,min(100,(int)($input['confidence']??0))),(string)($input['sourceType']??'unknown'),p50_metrics_json($provenance),p50_metrics_json((array)($input['metadata']??[])),$now,$now]);
    $id=(int)p50_metrics_value($pdo,"SELECT id FROM p50_metric_contents WHERE content_key=? LIMIT 1",[$key]);
    return ['id'=>$id,'contentKey'=>$key,'created'=>$stmt->rowCount()===1];
}

function p50_metrics_metric_values(array $input): array {
    $names=['followers','views','likes','comments','shares','saves','live_viewers'];$values=[];$errors=[];
    foreach($names as $name){
        $value=$input[$name]??null;
        if($value===null){$values[$name]=null;continue;}
        if(is_int($value))$number=$value;
        elseif(is_string($value)&&preg_match('/^\d+$/',$value))$number=(int)$value;
        else{$values[$name]=null;$errors[]=$name.':invalid';continue;}
        if($number<0){$values[$name]=null;$errors[]=$name.':negative';continue;}
        $values[$name]=$number;
    }
    return [$values,$errors];
}

function p50_metrics_record_capture(PDO $pdo,array $input): array {
    p50_metrics_assert_safe((array)($input['metrics']??[]),'metrics');$provenance=p50_metrics_provenance($input);p50_metrics_assert_safe((array)($input['metadata']??[]),'metadata');
    $accountId=(int)($input['accountId']??0);$contentId=isset($input['contentId'])?(int)$input['contentId']:null;
    $stmt=$pdo->prepare("SELECT profile_id,platform FROM p50_metric_accounts WHERE id=? LIMIT 1");$stmt->execute([$accountId]);$account=$stmt->fetch();if(!$account)throw new InvalidArgumentException('Compte métrique introuvable.');
    if($contentId!==null&&$contentId>0){
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM p50_metric_contents WHERE id=? AND account_id=?");$stmt->execute([$contentId,$accountId]);if((int)$stmt->fetchColumn()!==1)throw new InvalidArgumentException('Contenu métrique incompatible.');
    }else $contentId=null;
    $metricInput=$input;foreach(['followers','views','likes','comments','shares','saves','live_viewers'] as $metric)if(!array_key_exists($metric,$metricInput)&&array_key_exists($metric,(array)($input['metrics']??[])))$metricInput[$metric]=$input['metrics'][$metric];
    [$values,$errors]=p50_metrics_metric_values($metricInput);$quality=$errors?'quarantined':(string)($input['qualityStatus']??'usable');
    $usable=$errors?0:count(array_filter($values,static fn($value): bool=>$value!==null));
    $observed=p50_metrics_timestamp($input['observedAt']??gmdate('c'));$source=(string)($input['sourceType']??'unknown');
    $signature=['canonical'=>$values,'future'=>(array)($input['metrics']??[]),'quality'=>$quality,'errors'=>$errors];
    $key=p50_metrics_capture_key($accountId,$contentId,(string)$account['platform'],$source,$observed,$signature);
    $rawHash=trim((string)($input['rawPayloadHash']??''));if($rawHash!==''&&!preg_match('/^[a-f0-9]{64}$/i',$rawHash))throw new InvalidArgumentException('Empreinte brute invalide.');
    $sourceReference=p50_metrics_normalize_url($input['sourceReference']??'');$sourceReference=$sourceReference!==''?$sourceReference:null;
    $metadata=(array)($input['metadata']??[]);if($errors)$metadata['validationErrors']=$errors;
    $sql="INSERT IGNORE INTO p50_metric_captures(capture_key,account_id,content_id,profile_id,platform,collector,source_type,source_reference,observed_at,followers,views,likes,comments,shares,saves,live_viewers,usable_metric_count,confidence,quality_status,metrics_json,provenance_json,raw_payload_hash,run_uuid,metadata_json)
      VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $stmt=$pdo->prepare($sql);$stmt->execute([$key,$accountId,$contentId,$account['profile_id'],$account['platform'],(string)($input['collector']??'unknown'),$source,$sourceReference,$observed,$values['followers'],$values['views'],$values['likes'],$values['comments'],$values['shares'],$values['saves'],$values['live_viewers'],$usable,max(0,min(100,(int)($input['confidence']??0))),$quality,p50_metrics_json((array)($input['metrics']??[])),p50_metrics_json($provenance),$rawHash?:null,($input['runUuid']??null)?:null,p50_metrics_json($metadata)]);
    $created=$stmt->rowCount()===1;$id=(int)p50_metrics_value($pdo,"SELECT id FROM p50_metric_captures WHERE capture_key=? LIMIT 1",[$key]);
    return ['id'=>$id,'captureKey'=>$key,'created'=>$created,'duplicate'=>!$created,'quarantined'=>$quality==='quarantined','qualityStatus'=>$quality,'usableMetricCount'=>$usable];
}

function p50_metrics_enqueue_job(PDO $pdo,array $input): array {
    p50_metrics_assert_safe((array)($input['payload']??[]),'payload');
    $idempotency=trim((string)($input['idempotencyKey']??''));if($idempotency==='')throw new InvalidArgumentException('Clé d’idempotence obligatoire.');
    $key=preg_match('/^[a-f0-9]{64}$/i',$idempotency)?strtolower($idempotency):hash('sha256',$idempotency);$uuid=p50_metrics_uuid();
    $stmt=$pdo->prepare("INSERT IGNORE INTO p50_metric_jobs(job_uuid,idempotency_key,collector,platform,scope_type,scope_id,priority,status,scheduled_at,next_attempt_at,attempts,max_attempts,payload_json)
      VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$uuid,$key,(string)$input['collector'],($input['platform']??null)?:null,(string)$input['scopeType'],($input['scopeId']??null)?:null,max(0,min(1000,(int)($input['priority']??100))),(string)($input['status']??'pending'),p50_metrics_timestamp($input['scheduledAt']??gmdate('c')),null,0,max(1,min(20,(int)($input['maxAttempts']??3))),p50_metrics_json((array)($input['payload']??[]))]);
    $created=$stmt->rowCount()===1;$stmt=$pdo->prepare("SELECT id,job_uuid FROM p50_metric_jobs WHERE idempotency_key=? LIMIT 1");$stmt->execute([$key]);$row=$stmt->fetch();
    return ['id'=>(int)$row['id'],'jobUuid'=>(string)$row['job_uuid'],'created'=>$created,'duplicate'=>!$created];
}

function p50_metrics_start_run(PDO $pdo,array $input): array {
    p50_metrics_assert_safe((array)($input['metadata']??[]),'metadata');$uuid=(string)($input['runUuid']??p50_metrics_uuid());
    $stmt=$pdo->prepare("INSERT INTO p50_metric_runs(run_uuid,job_uuid,collector,platform,trigger_type,status,metadata_json,started_at) VALUES(?,?,?,?,?,'running',?,NOW())");
    $stmt->execute([$uuid,($input['jobUuid']??null)?:null,(string)$input['collector'],($input['platform']??null)?:null,(string)($input['triggerType']??'manual'),p50_metrics_json((array)($input['metadata']??[]))]);
    return ['runUuid'=>$uuid,'status'=>'running'];
}

function p50_metrics_finish_run(PDO $pdo,string $runUuid,string $status,array $counters=[],?string $error=null,array $metadata=[]): array {
    p50_metrics_assert_safe($metadata,'metadata');$safeError=p50_metrics_safe_error($error);
    $stmt=$pdo->prepare("UPDATE p50_metric_runs SET status=?,accounts_processed=?,contents_found=?,captures_recorded=?,duplicates_skipped=?,quarantined_count=?,error_count=?,error_message=?,metadata_json=?,finished_at=NOW() WHERE run_uuid=? AND status='running'");
    $stmt->execute([$status,(int)($counters['accountsProcessed']??0),(int)($counters['contentsFound']??0),(int)($counters['capturesRecorded']??0),(int)($counters['duplicatesSkipped']??0),(int)($counters['quarantinedCount']??0),(int)($counters['errorCount']??0),$safeError,p50_metrics_json($metadata),$runUuid]);
    return ['runUuid'=>$runUuid,'status'=>$status,'finished'=>$stmt->rowCount()===1];
}

function p50_metrics_safe_error(?string $value): ?string {
    if($value===null)return null;$value=strip_tags($value);$value=preg_replace('#https?://\S+#i','[url]',$value)??'';
    $value=preg_replace('/(?:token|secret|password|cookie)\s*[=:]\s*\S+/i','$1=[redacted]',$value)??'';
    return function_exists('mb_substr')?mb_substr(trim($value),0,500,'UTF-8'):substr(trim($value),0,500);
}

function p50_metrics_schema_status(PDO $pdo): array {
    $tables=['p50_metric_schema_migrations','p50_metric_accounts','p50_metric_contents','p50_metric_captures','p50_metric_jobs','p50_metric_runs'];
    $present=[];foreach($tables as $table)$present[$table]=p50_metrics_table_exists($pdo,$table);
    $migration=null;if($present['p50_metric_schema_migrations']){
        $stmt=$pdo->prepare("SELECT migration_key,schema_version,status,applied_at,updated_at,details_json FROM p50_metric_schema_migrations WHERE migration_key=? LIMIT 1");$stmt->execute([P50_METRICS_MIGRATION_KEY]);$migration=$stmt->fetch()?:null;
        if($migration)$migration['details']=json_decode((string)$migration['details_json'],true)?:[];if($migration)unset($migration['details_json']);
    }
    $volumes=[];foreach(array_slice($tables,1) as $table)$volumes[$table]=$present[$table]?(int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn():null;
    $legacy=[];foreach(['p50_social_links','p50_activity_events','p50_activity_metric_history'] as $table)$legacy[$table]=p50_metrics_table_exists($pdo,$table)?(int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn():null;
    $lastBackfill=$present['p50_metric_runs']&&p50_metrics_column_exists($pdo,'p50_metric_runs','collector')?p50_metrics_value($pdo,"SELECT MAX(finished_at) FROM p50_metric_runs WHERE collector='legacy_backfill_v1' AND status='success'"):null;
    return ['schemaVersion'=>(int)($migration['schema_version']??0),'migrationStatus'=>(string)($migration['status']??'not_installed'),'tables'=>$present,'volumes'=>$volumes,'legacyVolumes'=>$legacy,'lastBackfillAt'=>$lastBackfill,'migration'=>$migration];
}

function p50_metrics_legacy_metrics(?string $json): array {
    $raw=json_decode((string)$json,true);if(!is_array($raw))return [];
    $aliases=['followers'=>['followers','subscriberCount'],'views'=>['views','viewCount','playCount','reach','impressions','signalVolume'],'likes'=>['likes','likeCount','reactions'],'comments'=>['comments','commentCount','replies'],'shares'=>['shares','shareCount','reposts','quotes'],'saves'=>['saves','saveCount'],'live_viewers'=>['live_viewers','viewers']];
    $out=[];foreach($aliases as $target=>$keys){$found=false;$sum=0;foreach($keys as $key)if(array_key_exists($key,$raw)&&is_numeric($raw[$key])){$sum+=(int)$raw[$key];$found=true;}if($found)$out[$target]=$sum;}
    return $out;
}

function p50_metrics_backfill_legacy(PDO $pdo,int $limit=500): array {
    $limit=max(1,min(1000,$limit));$run=p50_metrics_start_run($pdo,['collector'=>'legacy_backfill_v1','triggerType'=>'migration','metadata'=>['migration'=>P50_METRICS_MIGRATION_KEY]]);
    $totals=['accountsCreated'=>0,'contentsCreated'=>0,'capturesRecorded'=>0,'duplicatesSkipped'=>0,'quarantinedCount'=>0,'errors'=>0];
    try{
        $pdo->beginTransaction();
        $links=$pdo->query("SELECT profile_id,platform,normalized_url,confidence,status,verified_at,updated_at FROM p50_social_links WHERE status='verified' ORDER BY profile_id,platform LIMIT ".$limit)->fetchAll();
        foreach($links as $row)try{
            $result=p50_metrics_upsert_account($pdo,['profileId'=>$row['profile_id'],'platform'=>$row['platform'],'canonicalUrl'=>$row['normalized_url'],'status'=>'active','confidence'=>$row['confidence'],'sourceType'=>'legacy_social_link','observedAt'=>$row['updated_at']??$row['verified_at']??gmdate('c'),'provenance'=>['sourceTable'=>'p50_social_links','sourceKey'=>$row['profile_id'].'|'.$row['platform'],'legacyUrlHash'=>hash('sha256',(string)$row['normalized_url']),'originalDate'=>$row['verified_at']??null,'migration'=>'legacy_backfill_v1']]);
            $totals['accountsCreated']+=(int)$result['created'];
        }catch(Throwable){$totals['errors']++;}

        $events=$pdo->query("SELECT id,profile_id,platform,event_type,title,url,url_hash,published_at,metrics,confidence,status,collected_at FROM p50_activity_events ORDER BY id LIMIT ".$limit)->fetchAll();
        foreach($events as $event)try{
            $accountStmt=$pdo->prepare("SELECT id FROM p50_metric_accounts WHERE profile_id=? AND platform=? LIMIT 1");$accountStmt->execute([$event['profile_id'],$event['platform']]);$accountId=(int)($accountStmt->fetchColumn()?:0);
            if($accountId===0){$account=p50_metrics_upsert_account($pdo,['profileId'=>$event['profile_id'],'platform'=>$event['platform'],'canonicalUrl'=>$event['url'],'status'=>'legacy_inferred','confidence'=>$event['confidence'],'sourceType'=>'legacy_activity_event','observedAt'=>$event['collected_at'],'provenance'=>['sourceTable'=>'p50_activity_events','sourceId'=>$event['id'],'legacyUrlHash'=>$event['url_hash'],'originalDate'=>$event['collected_at'],'migration'=>'legacy_backfill_v1']]);$accountId=$account['id'];$totals['accountsCreated']+=(int)$account['created'];}
            $content=p50_metrics_upsert_content($pdo,['accountId'=>$accountId,'platformContentId'=>null,'contentType'=>in_array($event['event_type'],['video','post','reel','short','live'],true)?$event['event_type']:'unknown','canonicalUrl'=>$event['url'],'title'=>$event['title'],'publishedAt'=>$event['published_at'],'status'=>$event['status'],'confidence'=>$event['confidence'],'sourceType'=>'legacy_activity_event','observedAt'=>$event['collected_at'],'provenance'=>['sourceTable'=>'p50_activity_events','sourceId'=>$event['id'],'legacyUrlHash'=>$event['url_hash'],'originalDate'=>$event['collected_at'],'migration'=>'legacy_backfill_v1']]);
            $totals['contentsCreated']+=(int)$content['created'];
        }catch(Throwable){$totals['errors']++;}

        $history=$pdo->query("SELECT h.id,h.profile_id,h.platform,h.url_hash,h.metrics,h.captured_at,e.id event_id,e.url event_url
          FROM p50_activity_metric_history h LEFT JOIN p50_activity_events e ON e.profile_id=h.profile_id AND e.platform=h.platform AND e.url_hash=h.url_hash
          ORDER BY h.id LIMIT ".$limit)->fetchAll();
        foreach($history as $row)try{
            $accountId=(int)p50_metrics_value($pdo,"SELECT id FROM p50_metric_accounts WHERE profile_id=? AND platform=? LIMIT 1",[$row['profile_id'],$row['platform']]);
            $contentId=(int)p50_metrics_value($pdo,"SELECT id FROM p50_metric_contents WHERE profile_id=? AND platform=? AND url_hash=? LIMIT 1",[$row['profile_id'],$row['platform'],hash('sha256',p50_metrics_normalize_url($row['event_url']??''))]);
            if(!$accountId||!$contentId){$totals['errors']++;continue;}
            $capture=p50_metrics_record_capture($pdo,['accountId'=>$accountId,'contentId'=>$contentId,'collector'=>'legacy_backfill_v1','sourceType'=>'legacy_metric_history','observedAt'=>$row['captured_at'],'metrics'=>p50_metrics_legacy_metrics($row['metrics']),'confidence'=>90,'runUuid'=>$run['runUuid'],'provenance'=>['sourceTable'=>'p50_activity_metric_history','sourceId'=>$row['id'],'sourceEventId'=>$row['event_id'],'legacyUrlHash'=>$row['url_hash'],'originalDate'=>$row['captured_at'],'migration'=>'legacy_backfill_v1']]);
            $totals['capturesRecorded']+=(int)$capture['created'];$totals['duplicatesSkipped']+=(int)$capture['duplicate'];$totals['quarantinedCount']+=(int)$capture['quarantined'];
        }catch(Throwable){$totals['errors']++;}

        $fallback=$pdo->query("SELECT e.id,e.profile_id,e.platform,e.url,e.url_hash,e.metrics,e.confidence,e.collected_at
          FROM p50_activity_events e
          WHERE e.metrics IS NOT NULL AND e.metrics<>'' AND NOT EXISTS(SELECT 1 FROM p50_activity_metric_history h WHERE h.profile_id=e.profile_id AND h.platform=e.platform AND h.url_hash=e.url_hash)
          ORDER BY e.id LIMIT ".$limit)->fetchAll();
        foreach($fallback as $row)try{
            $metrics=p50_metrics_legacy_metrics($row['metrics']);if(!$metrics)continue;
            $accountId=(int)p50_metrics_value($pdo,"SELECT id FROM p50_metric_accounts WHERE profile_id=? AND platform=? LIMIT 1",[$row['profile_id'],$row['platform']]);
            $contentId=(int)p50_metrics_value($pdo,"SELECT id FROM p50_metric_contents WHERE profile_id=? AND platform=? AND url_hash=? LIMIT 1",[$row['profile_id'],$row['platform'],hash('sha256',p50_metrics_normalize_url($row['url']))]);
            if(!$accountId||!$contentId){$totals['errors']++;continue;}
            $capture=p50_metrics_record_capture($pdo,['accountId'=>$accountId,'contentId'=>$contentId,'collector'=>'legacy_backfill_v1','sourceType'=>'legacy_activity_event','observedAt'=>$row['collected_at'],'metrics'=>$metrics,'confidence'=>$row['confidence'],'runUuid'=>$run['runUuid'],'provenance'=>['sourceTable'=>'p50_activity_events','sourceId'=>$row['id'],'legacyUrlHash'=>$row['url_hash'],'originalDate'=>$row['collected_at'],'migration'=>'legacy_backfill_v1','fallback'=>true]]);
            $totals['capturesRecorded']+=(int)$capture['created'];$totals['duplicatesSkipped']+=(int)$capture['duplicate'];$totals['quarantinedCount']+=(int)$capture['quarantined'];
        }catch(Throwable){$totals['errors']++;}
        $pdo->commit();
        p50_metrics_finish_run($pdo,$run['runUuid'],'success',['accountsProcessed'=>count($links),'contentsFound'=>count($events),'capturesRecorded'=>$totals['capturesRecorded'],'duplicatesSkipped'=>$totals['duplicatesSkipped'],'quarantinedCount'=>$totals['quarantinedCount'],'errorCount'=>$totals['errors']],null,['migration'=>P50_METRICS_MIGRATION_KEY]);
        return $totals+['runUuid'=>$run['runUuid'],'status'=>'success'];
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        p50_metrics_finish_run($pdo,$run['runUuid'],'error',['errorCount'=>1],$error->getMessage(),['migration'=>P50_METRICS_MIGRATION_KEY]);throw $error;
    }
}
