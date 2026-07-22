<?php
declare(strict_types=1);

require_once __DIR__ . '/http-tools.php';

const P50_DATA_CONFIDENCE_THRESHOLD = 90;

function p50_de_threshold(): int {
    global $config;
    return max(90, min(100, (int)($config['data_engine']['confidence_threshold'] ?? P50_DATA_CONFIDENCE_THRESHOLD)));
}

function p50_de_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    $sql = [
        "CREATE TABLE IF NOT EXISTS p50_profile_registry (
            profile_id VARCHAR(100) PRIMARY KEY,
            public_name VARCHAR(190) NOT NULL,
            handle VARCHAR(190) NOT NULL DEFAULT '',
            region VARCHAR(32) NOT NULL DEFAULT 'CI',
            category VARCHAR(100) NOT NULL DEFAULT '',
            alive TINYINT(1) NOT NULL DEFAULT 1,
            eligible TINYINT(1) NOT NULL DEFAULT 1,
            state_hash CHAR(64) CHARACTER SET ascii NOT NULL,
            last_state_sync_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_p50_registry_eligible (eligible,alive),
            INDEX idx_p50_registry_name (public_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS p50_collection_runs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            run_uuid CHAR(36) CHARACTER SET ascii NOT NULL,
            profile_id VARCHAR(100) NULL,
            collector VARCHAR(64) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'running',
            requested_by CHAR(36) NULL,
            items_found INT UNSIGNED NOT NULL DEFAULT 0,
            items_verified INT UNSIGNED NOT NULL DEFAULT 0,
            error_message TEXT NULL,
            metadata LONGTEXT NULL,
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            finished_at DATETIME NULL,
            UNIQUE KEY uq_p50_run_uuid (run_uuid),
            INDEX idx_p50_runs_profile_date (profile_id,started_at),
            INDEX idx_p50_runs_status_date (status,started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS p50_fact_evidence (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            profile_id VARCHAR(100) NOT NULL,
            fact_key VARCHAR(64) NOT NULL,
            normalized_value VARCHAR(255) NOT NULL,
            value_hash CHAR(64) CHARACTER SET ascii NOT NULL,
            raw_value TEXT NOT NULL,
            source_type VARCHAR(64) NOT NULL,
            source_name VARCHAR(190) NOT NULL,
            source_url TEXT NULL,
            source_hash CHAR(64) CHARACTER SET ascii NOT NULL,
            source_weight TINYINT UNSIGNED NOT NULL DEFAULT 0,
            fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_p50_fact_evidence (profile_id,fact_key,value_hash,source_hash),
            INDEX idx_p50_fact_evidence_value (profile_id,fact_key,value_hash),
            INDEX idx_p50_fact_evidence_source (profile_id,source_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS p50_facts (
            profile_id VARCHAR(100) NOT NULL,
            fact_key VARCHAR(64) NOT NULL,
            normalized_value VARCHAR(255) NOT NULL,
            value_hash CHAR(64) CHARACTER SET ascii NOT NULL,
            value_json LONGTEXT NOT NULL,
            confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
            evidence_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            source_types LONGTEXT NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'candidate',
            first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            verified_at DATETIME NULL,
            PRIMARY KEY (profile_id,fact_key,value_hash),
            INDEX idx_p50_facts_public (profile_id,fact_key,status,confidence)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS p50_social_link_evidence (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            profile_id VARCHAR(100) NOT NULL,
            platform VARCHAR(32) NOT NULL,
            normalized_url TEXT NOT NULL,
            url_hash CHAR(64) CHARACTER SET ascii NOT NULL,
            source_type VARCHAR(64) NOT NULL,
            source_name VARCHAR(190) NOT NULL,
            source_url TEXT NULL,
            source_hash CHAR(64) CHARACTER SET ascii NOT NULL,
            source_weight TINYINT UNSIGNED NOT NULL DEFAULT 0,
            validation_json LONGTEXT NULL,
            fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_p50_social_evidence (profile_id,platform,url_hash,source_hash),
            INDEX idx_p50_social_evidence_url (profile_id,platform,url_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS p50_social_links (
            profile_id VARCHAR(100) NOT NULL,
            platform VARCHAR(32) NOT NULL,
            normalized_url TEXT NOT NULL,
            url_hash CHAR(64) CHARACTER SET ascii NOT NULL,
            confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
            evidence_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            source_types LONGTEXT NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'candidate',
            validation_json LONGTEXT NULL,
            first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            checked_at DATETIME NULL,
            verified_at DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (profile_id,platform),
            INDEX idx_p50_social_public (profile_id,status,confidence)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS p50_ranking_snapshots (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            profile_id VARCHAR(100) NOT NULL,
            period_key VARCHAR(16) NOT NULL,
            rank_position SMALLINT UNSIGNED NOT NULL,
            trend_score DECIMAL(6,2) NOT NULL,
            rank_delta SMALLINT NOT NULL DEFAULT 0,
            badges LONGTEXT NOT NULL,
            data_confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
            captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_p50_snapshots_period_date (period_key,captured_at),
            INDEX idx_p50_snapshots_profile_date (profile_id,captured_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS p50_activity_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            profile_id VARCHAR(100) NOT NULL,
            platform VARCHAR(32) NOT NULL,
            event_type VARCHAR(48) NOT NULL,
            title VARCHAR(255) NOT NULL,
            url TEXT NOT NULL,
            url_hash CHAR(64) CHARACTER SET ascii NOT NULL,
            published_at DATETIME NULL,
            metrics LONGTEXT NULL,
            confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(24) NOT NULL DEFAULT 'candidate',
            collected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_p50_activity_url (profile_id,url_hash),
            INDEX idx_p50_activity_public (profile_id,status,confidence,published_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS p50_engine_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value LONGTEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($sql as $statement) db()->exec($statement);
    $stmt = db()->prepare("INSERT INTO p50_engine_settings(setting_key,setting_value) VALUES('confidence_threshold',?) ON DUPLICATE KEY UPDATE setting_value=setting_value");
    $stmt->execute([(string)p50_de_threshold()]);
    $done = true;
}

function p50_de_get_setting(string $key, mixed $fallback=null): mixed {
    p50_de_ensure_schema();
    $stmt=db()->prepare('SELECT setting_value FROM p50_engine_settings WHERE setting_key=? LIMIT 1');
    $stmt->execute([$key]);
    $value=$stmt->fetchColumn();
    if($value===false)return $fallback;
    $decoded=json_decode((string)$value,true);
    return json_last_error()===JSON_ERROR_NONE?$decoded:$value;
}

function p50_de_set_setting(string $key, mixed $value): void {
    p50_de_ensure_schema();
    $encoded=is_string($value)?$value:(json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'');
    $stmt=db()->prepare('INSERT INTO p50_engine_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()');
    $stmt->execute([$key,$encoded]);
}

function p50_de_now(): string { return gmdate('Y-m-d H:i:s'); }
function p50_de_hash(string $value): string { return hash('sha256', $value); }

function p50_de_load_public_state(): array {
    $stmt = db()->query("SELECT data FROM app_state WHERE id='public' LIMIT 1");
    $raw = $stmt->fetchColumn();
    if (!is_string($raw) || $raw === '') return [];
    $state = json_decode($raw, true);
    return is_array($state) ? $state : [];
}

function p50_de_save_public_state(array $state, ?string $userId = null): void {
    $stmt = db()->prepare("INSERT INTO app_state(id,data,updated_by) VALUES('public',?,?) ON DUPLICATE KEY UPDATE data=VALUES(data),updated_by=VALUES(updated_by),updated_at=NOW()");
    $stmt->execute([json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $userId]);
}

function p50_de_profile_state_map(array $state): array {
    $map = [];
    foreach ((array)($state['profiles'] ?? []) as $profile) {
        if (!is_array($profile) || empty($profile['id'])) continue;
        $map[(string)$profile['id']] = $profile;
    }
    return $map;
}

function p50_de_sync_registry_from_state(): int {
    p50_de_ensure_schema();
    $state = p50_de_load_public_state();
    $count = 0;
    $sql = "INSERT INTO p50_profile_registry(profile_id,public_name,handle,region,category,alive,eligible,state_hash,last_state_sync_at)
            VALUES(?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE public_name=VALUES(public_name),handle=VALUES(handle),region=VALUES(region),category=VALUES(category),alive=VALUES(alive),eligible=VALUES(eligible),state_hash=VALUES(state_hash),last_state_sync_at=NOW()";
    $stmt = db()->prepare($sql);
    foreach ((array)($state['profiles'] ?? []) as $p) {
        if (!is_array($p) || empty($p['id']) || empty($p['name'])) continue;
        $payload = [
            (string)$p['id'], (string)$p['name'], (string)($p['handle'] ?? ''),
            (string)($p['region'] ?? 'CI'), (string)($p['category'] ?? ''),
            !array_key_exists('alive',$p) || !empty($p['alive']) ? 1 : 0,
            !array_key_exists('eligible',$p) || !empty($p['eligible']) ? 1 : 0,
            p50_de_hash(json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
        ];
        $stmt->execute($payload);
        $count++;
    }
    return $count;
}

function p50_de_registry_profiles(?string $profileId = null, int $limit = 100, int $offset = 0): array {
    p50_de_ensure_schema();
    if ($profileId !== null && $profileId !== '') {
        $stmt = db()->prepare('SELECT * FROM p50_profile_registry WHERE profile_id=? LIMIT 1');
        $stmt->execute([$profileId]);
        $row = $stmt->fetch();
        return $row ? [$row] : [];
    }
    $limit = max(1, min(100, $limit));
    $offset = max(0, $offset);
    $stmt = db()->prepare("SELECT * FROM p50_profile_registry WHERE alive=1 AND eligible=1 ORDER BY public_name LIMIT $limit OFFSET $offset");
    $stmt->execute();
    return $stmt->fetchAll();
}

function p50_de_begin_run(?string $profileId, string $collector, ?string $userId, array $metadata = []): array {
    $uuid = uuid_v4();
    $stmt = db()->prepare('INSERT INTO p50_collection_runs(run_uuid,profile_id,collector,requested_by,metadata) VALUES(?,?,?,?,?)');
    $stmt->execute([$uuid, $profileId, $collector, $userId, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    return ['id'=>(int)db()->lastInsertId(), 'uuid'=>$uuid];
}

function p50_de_finish_run(int $id, string $status, int $found, int $verified, ?string $error = null, array $metadata = []): void {
    $stmt = db()->prepare('UPDATE p50_collection_runs SET status=?,items_found=?,items_verified=?,error_message=?,metadata=?,finished_at=NOW() WHERE id=?');
    $stmt->execute([$status,$found,$verified,$error,json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$id]);
}

function p50_de_add_fact_evidence(string $profileId, string $factKey, string $normalizedValue, mixed $rawValue, string $sourceType, string $sourceName, string $sourceUrl, int $sourceWeight): void {
    $normalizedValue = trim($normalizedValue);
    if ($normalizedValue === '') return;
    $raw = is_string($rawValue) ? $rawValue : (json_encode($rawValue,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '');
    $valueHash = p50_de_hash($normalizedValue);
    $sourceHash = p50_de_hash($sourceType . '|' . $sourceName . '|' . $sourceUrl);
    $stmt = db()->prepare("INSERT INTO p50_fact_evidence(profile_id,fact_key,normalized_value,value_hash,raw_value,source_type,source_name,source_url,source_hash,source_weight,fetched_at)
        VALUES(?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE raw_value=VALUES(raw_value),source_weight=VALUES(source_weight),fetched_at=NOW(),source_url=VALUES(source_url)");
    $stmt->execute([$profileId,$factKey,$normalizedValue,$valueHash,$raw,$sourceType,$sourceName,$sourceUrl,$sourceHash,max(0,min(100,$sourceWeight))]);
    p50_de_rebuild_fact($profileId,$factKey);
}

function p50_de_rebuild_fact(string $profileId, string $factKey): void {
    $stmt = db()->prepare('SELECT normalized_value,value_hash,MAX(raw_value) raw_value,COUNT(*) evidence_count,COUNT(DISTINCT source_type) source_type_count,MAX(source_weight) max_weight,AVG(source_weight) avg_weight,GROUP_CONCAT(DISTINCT source_type ORDER BY source_type SEPARATOR ",") source_types FROM p50_fact_evidence WHERE profile_id=? AND fact_key=? GROUP BY normalized_value,value_hash');
    $stmt->execute([$profileId,$factKey]);
    $groups = $stmt->fetchAll();
    if (!$groups) return;
    $manualTypes = ['manual_owner','manual_admin'];
    $scored = [];
    foreach ($groups as $g) {
        $types = array_values(array_filter(explode(',',(string)$g['source_types'])));
        $manualOwner = in_array('manual_owner',$types,true);
        $manualAdmin = in_array('manual_admin',$types,true);
        $sourceCount = (int)$g['source_type_count'];
        $maxWeight = (int)$g['max_weight'];
        $avgWeight = (float)$g['avg_weight'];
        if ($manualOwner) $confidence = 100;
        elseif ($manualAdmin) $confidence = 98;
        elseif ($factKey === 'birth_date') {
            $confidence = $sourceCount >= 2 ? min(100, 90 + max(0,min(10,(int)round(($avgWeight-80)/1.5)))) : min(89,(int)round($maxWeight*0.90));
        } else {
            $confidence = $sourceCount >= 2 ? min(100, 90 + max(0,min(10,(int)round(($avgWeight-80)/1.5)))) : ($maxWeight >= 98 ? 95 : min(89,(int)round($maxWeight*0.90)));
        }
        $scored[] = $g + ['confidence'=>$confidence,'types'=>$types];
    }
    usort($scored, static fn($a,$b) => $b['confidence'] <=> $a['confidence'] ?: $b['evidence_count'] <=> $a['evidence_count']);
    $best = $scored[0];
    $conflict = isset($scored[1]) && (int)$scored[1]['confidence'] >= p50_de_threshold() && (int)$scored[1]['confidence'] === (int)$best['confidence'];
    $upsert = db()->prepare("INSERT INTO p50_facts(profile_id,fact_key,normalized_value,value_hash,value_json,confidence,evidence_count,source_types,status,last_seen_at,verified_at)
        VALUES(?,?,?,?,?,?,?,?,?,NOW(),?)
        ON DUPLICATE KEY UPDATE normalized_value=VALUES(normalized_value),value_json=VALUES(value_json),confidence=VALUES(confidence),evidence_count=VALUES(evidence_count),source_types=VALUES(source_types),status=VALUES(status),last_seen_at=NOW(),verified_at=VALUES(verified_at)");
    foreach ($scored as $index => $g) {
        $verified = !$conflict && $index===0 && (int)$g['confidence'] >= p50_de_threshold();
        $status = $conflict && (int)$g['confidence'] >= p50_de_threshold() ? 'conflict' : ($verified ? 'verified' : 'candidate');
        $valueJson = json_encode(['value'=>$g['normalized_value'],'raw'=>$g['raw_value']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $upsert->execute([$profileId,$factKey,$g['normalized_value'],$g['value_hash'],$valueJson,(int)$g['confidence'],(int)$g['evidence_count'],json_encode($g['types']),$status,$verified?p50_de_now():null]);
    }
}

function p50_de_normalize_social_url(string $platform, string $url): string {
    $url = trim($url);
    if (!filter_var($url,FILTER_VALIDATE_URL)) return '';
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) return '';
    $scheme = 'https';
    $host = strtolower((string)$parts['host']);
    $host = preg_replace('/^www\./','',$host) ?: $host;
    $path = '/' . trim((string)($parts['path'] ?? ''),'/');
    $path = $path === '/' ? '/' : rtrim($path,'/');
    $platform = strtolower($platform);
    if ($platform === 'instagram') $host='instagram.com';
    elseif ($platform === 'tiktok') $host='tiktok.com';
    elseif ($platform === 'facebook') $host='facebook.com';
    elseif ($platform === 'youtube') $host='youtube.com';
    elseif ($platform === 'x') $host='x.com';
    elseif ($platform === 'snapchat') $host='snapchat.com';
    $query = '';
    if ($platform === 'web' && !empty($parts['query'])) $query='?'.$parts['query'];
    return $scheme.'://'.$host.$path.$query;
}

function p50_de_direct_social_path(string $platform, string $url): bool {
    $path = trim((string)(parse_url($url,PHP_URL_PATH) ?: ''),'/');
    $query = (string)(parse_url($url,PHP_URL_QUERY) ?: '');
    $p = strtolower($platform);
    if ($p === 'web') return true;
    if ($path === '' || preg_match('#^(search|explore|results|home|watch|feed)(/|$)#i',$path)) return false;
    if (str_contains($query,'search_query=')) return false;
    return match($p) {
        'instagram' => count(array_filter(explode('/',$path))) >= 1 && !str_starts_with($path,'p/') && !str_starts_with($path,'reel/'),
        'tiktok' => str_starts_with($path,'@'),
        'youtube' => preg_match('#^(channel/|c/|user/|@)#i',$path) === 1,
        'facebook' => !preg_match('#^(watch|groups|marketplace|gaming)(/|$)#i',$path),
        'x' => count(array_filter(explode('/',$path))) === 1,
        'snapchat' => str_starts_with($path,'add/'),
        default => true,
    };
}

function p50_de_validate_social_url(string $platform, string $url, string $name = '', string $handle = ''): array {
    $normalized = p50_de_normalize_social_url($platform,$url);
    if ($normalized === '') return ['ok'=>false,'status'=>'invalid','normalizedUrl'=>'','httpStatus'=>0,'nameScore'=>0,'message'=>'URL invalide'];
    if (!p50_platform_host_ok($platform,$normalized)) return ['ok'=>false,'status'=>'wrong_platform','normalizedUrl'=>$normalized,'httpStatus'=>0,'nameScore'=>0,'message'=>'Le domaine ne correspond pas à la plateforme'];
    if (!p50_de_direct_social_path($platform,$normalized)) return ['ok'=>false,'status'=>'generic_or_content','normalizedUrl'=>$normalized,'httpStatus'=>0,'nameScore'=>0,'message'=>'Le lien doit pointer vers un profil officiel direct'];
    $r = p50_http_fetch($normalized,10,'text/html,*/*;q=0.7',true);
    if (!$r['ok'] && in_array((int)$r['status'],[403,405,429],true)) $r = p50_http_fetch($normalized,10,'text/html,*/*;q=0.7');
    $blocked = in_array((int)$r['status'],[403,429],true);
    $metadata = $r['body'] !== '' ? p50_page_metadata($r['body'],$r['finalUrl'] ?: $normalized) : ['title'=>'','description'=>'','image'=>'','canonical'=>''];
    $nameScore = p50_name_score(($metadata['title']??'').' '.($metadata['description']??'').' '.$normalized,$name,$handle);
    $ok = $r['ok'] || $blocked;
    return [
        'ok'=>$ok,
        'status'=>$r['ok']?'accessible':($blocked?'blocked_but_exists':'unreachable'),
        'normalizedUrl'=>p50_de_normalize_social_url($platform,$r['finalUrl'] ?: $normalized) ?: $normalized,
        'httpStatus'=>(int)$r['status'],
        'nameScore'=>$nameScore,
        'title'=>(string)($metadata['title']??''),
        'message'=>$r['ok']?'Lien direct accessible':($blocked?'La plateforme bloque le contrôle automatique, mais le lien direct est valide':'Lien inaccessible'),
    ];
}

function p50_de_add_social_evidence(string $profileId, string $platform, string $url, string $sourceType, string $sourceName, string $sourceUrl, int $sourceWeight, array $validation = []): void {
    $normalized = p50_de_normalize_social_url($platform,$url);
    if ($normalized === '') return;
    $urlHash = p50_de_hash($normalized);
    $sourceHash = p50_de_hash($sourceType.'|'.$sourceName.'|'.$sourceUrl);
    $stmt = db()->prepare("INSERT INTO p50_social_link_evidence(profile_id,platform,normalized_url,url_hash,source_type,source_name,source_url,source_hash,source_weight,validation_json,fetched_at)
        VALUES(?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE normalized_url=VALUES(normalized_url),source_weight=VALUES(source_weight),validation_json=VALUES(validation_json),fetched_at=NOW(),source_url=VALUES(source_url)");
    $stmt->execute([$profileId,$platform,$normalized,$urlHash,$sourceType,$sourceName,$sourceUrl,$sourceHash,max(0,min(100,$sourceWeight)),json_encode($validation,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    p50_de_rebuild_social_link($profileId,$platform);
}

function p50_de_rebuild_social_link(string $profileId, string $platform): void {
    $stmt = db()->prepare('SELECT normalized_url,url_hash,COUNT(*) evidence_count,COUNT(DISTINCT source_type) source_type_count,MAX(source_weight) max_weight,AVG(source_weight) avg_weight,GROUP_CONCAT(DISTINCT source_type ORDER BY source_type SEPARATOR ",") source_types,MAX(validation_json) validation_json FROM p50_social_link_evidence WHERE profile_id=? AND platform=? GROUP BY normalized_url,url_hash');
    $stmt->execute([$profileId,$platform]);
    $groups = $stmt->fetchAll();
    if (!$groups) return;
    $scored=[];
    foreach($groups as $g){
        $types=array_values(array_filter(explode(',',(string)$g['source_types'])));
        $manualOwner=in_array('manual_owner',$types,true);
        $manualAdmin=in_array('manual_admin',$types,true);
        $sourceCount=(int)$g['source_type_count'];
        $maxWeight=(int)$g['max_weight'];
        $avgWeight=(float)$g['avg_weight'];
        if($manualOwner)$confidence=100;
        elseif($manualAdmin)$confidence=98;
        elseif($sourceCount>=2)$confidence=min(100,92+max(0,min(8,(int)round(($avgWeight-82)/2))));
        elseif(in_array('wikidata',$types,true)&&$maxWeight>=92)$confidence=92;
        else $confidence=min(89,(int)round($maxWeight*0.92));
        $scored[]=$g+['confidence'=>$confidence,'types'=>$types];
    }
    usort($scored,static fn($a,$b)=>$b['confidence']<=>$a['confidence']?:$b['evidence_count']<=>$a['evidence_count']);
    $best=$scored[0];
    $conflict=isset($scored[1])&&(int)$scored[1]['confidence']>=p50_de_threshold()&&(int)$scored[1]['confidence']===(int)$best['confidence'];
    $verified=!$conflict&&(int)$best['confidence']>=p50_de_threshold();
    $status=$conflict?'conflict':($verified?'verified':'candidate');
    $stmt=db()->prepare("INSERT INTO p50_social_links(profile_id,platform,normalized_url,url_hash,confidence,evidence_count,source_types,status,validation_json,checked_at,verified_at)
        VALUES(?,?,?,?,?,?,?,?,?,NOW(),?)
        ON DUPLICATE KEY UPDATE normalized_url=VALUES(normalized_url),url_hash=VALUES(url_hash),confidence=VALUES(confidence),evidence_count=VALUES(evidence_count),source_types=VALUES(source_types),status=VALUES(status),validation_json=VALUES(validation_json),checked_at=NOW(),verified_at=VALUES(verified_at)");
    $stmt->execute([$profileId,$platform,$best['normalized_url'],$best['url_hash'],(int)$best['confidence'],(int)$best['evidence_count'],json_encode($best['types']),$status,$best['validation_json'],$verified?p50_de_now():null]);
}

function p50_de_wikidata_claims(array $entity, string $property): array {
    $out=[];
    foreach((array)($entity['claims'][$property]??[]) as $claim){
        $value=$claim['mainsnak']['datavalue']['value']??null;
        if($value!==null)$out[]=$value;
    }
    return $out;
}

function p50_de_wikidata_date(array $entity): ?string {
    foreach(p50_de_wikidata_claims($entity,'P569') as $value){
        if(!is_array($value)||empty($value['time'])||(int)($value['precision']??0)<11)continue;
        if(preg_match('/^[+-](\d{4})-(\d{2})-(\d{2})T/',(string)$value['time'],$m))return sprintf('%04d-%02d-%02d',(int)$m[1],(int)$m[2],(int)$m[3]);
    }
    return null;
}

function p50_de_parse_dates(string $text): array {
    $text=html_entity_decode($text,ENT_QUOTES|ENT_HTML5,'UTF-8');
    $months=[
        'janvier'=>1,'février'=>2,'fevrier'=>2,'mars'=>3,'avril'=>4,'mai'=>5,'juin'=>6,'juillet'=>7,'août'=>8,'aout'=>8,'septembre'=>9,'octobre'=>10,'novembre'=>11,'décembre'=>12,'decembre'=>12,
        'january'=>1,'february'=>2,'march'=>3,'april'=>4,'may'=>5,'june'=>6,'july'=>7,'august'=>8,'september'=>9,'october'=>10,'november'=>11,'december'=>12,
    ];
    $keys=implode('|',array_map(static fn($x)=>preg_quote($x,'/'),array_keys($months)));
    preg_match_all('/\b(\d{1,2})\s+('.$keys.')\s+(\d{4})\b/iu',$text,$matches,PREG_SET_ORDER);
    $dates=[];
    foreach($matches as $m){
        $monthKey=function_exists('mb_strtolower')?mb_strtolower($m[2],'UTF-8'):strtolower($m[2]);
        $month=$months[$monthKey]??null;
        if(!$month)continue;
        $date=sprintf('%04d-%02d-%02d',(int)$m[3],$month,(int)$m[1]);
        if(checkdate($month,(int)$m[1],(int)$m[3]))$dates[]=$date;
    }
    preg_match_all('/\b(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})\b/',$text,$numeric,PREG_SET_ORDER);
    foreach($numeric as $m){$day=(int)$m[1];$month=(int)$m[2];$year=(int)$m[3];if(checkdate($month,$day,$year))$dates[]=sprintf('%04d-%02d-%02d',$year,$month,$day);}
    return array_values(array_unique($dates));
}

function p50_de_collect_wikidata(array $profile): array {
    $name=(string)$profile['public_name'];
    $handle=(string)$profile['handle'];
    $searchUrl='https://www.wikidata.org/w/api.php?'.http_build_query(['action'=>'wbsearchentities','search'=>$name,'language'=>'fr','uselang'=>'fr','format'=>'json','limit'=>6]);
    $search=p50_json_get($searchUrl,12);
    $best=null;$bestScore=0;
    foreach((array)($search['search']??[]) as $candidate){
        $hay=(string)($candidate['label']??'').' '.(string)($candidate['description']??'').' '.implode(' ',(array)($candidate['aliases']??[]));
        $score=p50_name_score($hay,$name,$handle);
        if($score>$bestScore){$bestScore=$score;$best=$candidate;}
    }
    if(!$best||$bestScore<52||empty($best['id']))return ['found'=>0,'verified'=>0,'message'=>'Aucune entité Wikidata suffisamment proche'];
    $qid=(string)$best['id'];
    $entityUrl='https://www.wikidata.org/wiki/Special:EntityData/'.rawurlencode($qid).'.json';
    $entityData=p50_json_get($entityUrl,12);
    $entity=$entityData['entities'][$qid]??null;
    if(!is_array($entity))return ['found'=>0,'verified'=>0,'message'=>'Entité Wikidata inaccessible'];
    $found=0;
    $birth=p50_de_wikidata_date($entity);
    if($birth){p50_de_add_fact_evidence((string)$profile['profile_id'],'birth_date',$birth,$birth,'wikidata','Wikidata · '.$qid,$entityUrl,96);$found++;}
    $properties=[
        'P2003'=>['Instagram',static fn($v)=>'https://instagram.com/'.ltrim((string)$v,'@')],
        'P7085'=>['TikTok',static fn($v)=>'https://tiktok.com/@'.ltrim((string)$v,'@')],
        'P2013'=>['Facebook',static fn($v)=>filter_var((string)$v,FILTER_VALIDATE_URL)?(string)$v:'https://facebook.com/'.ltrim((string)$v,'@')],
        'P2397'=>['YouTube',static fn($v)=>'https://youtube.com/channel/'.(string)$v],
        'P2002'=>['X',static fn($v)=>'https://x.com/'.ltrim((string)$v,'@')],
        'P2984'=>['Snapchat',static fn($v)=>'https://snapchat.com/add/'.ltrim((string)$v,'@')],
        'P856'=>['Web',static fn($v)=>(string)$v],
    ];
    foreach($properties as $property=>[$platform,$builder]){
        foreach(p50_de_wikidata_claims($entity,$property) as $value){
            if(is_array($value))continue;
            $url=$builder($value);
            $validation=p50_de_validate_social_url($platform,$url,$name,$handle);
            if(!$validation['ok']&&$platform!=='Web')continue;
            p50_de_add_social_evidence((string)$profile['profile_id'],$platform,$validation['normalizedUrl']?:$url,'wikidata','Wikidata · '.$qid,$entityUrl,92,$validation);
            $found++;
        }
    }
    $wikiSite='';$wikiTitle='';
    foreach(['frwiki','enwiki'] as $site){if(!empty($entity['sitelinks'][$site]['title'])){$wikiSite=$site;$wikiTitle=(string)$entity['sitelinks'][$site]['title'];break;}}
    if($wikiTitle!==''){
        $lang=$wikiSite==='frwiki'?'fr':'en';
        $wikiApi='https://'.$lang.'.wikipedia.org/w/api.php?'.http_build_query(['action'=>'query','prop'=>'extracts|info','inprop'=>'url','exintro'=>1,'explaintext'=>1,'redirects'=>1,'titles'=>$wikiTitle,'format'=>'json']);
        $wiki=p50_json_get($wikiApi,12);
        foreach((array)($wiki['query']['pages']??[]) as $page){
            $extract=(string)($page['extract']??'');
            $pageUrl=(string)($page['fullurl']??('https://'.$lang.'.wikipedia.org/wiki/'.rawurlencode(str_replace(' ','_',$wikiTitle))));
            foreach(p50_de_parse_dates($extract) as $date){
                if($birth!==null&&$date!==$birth)continue;
                p50_de_add_fact_evidence((string)$profile['profile_id'],'birth_date',$date,$date,'wikipedia','Wikipédia · '.$lang,$pageUrl,92);
                $found++;
                break;
            }
        }
    }
    $verified=p50_de_profile_verified_count((string)$profile['profile_id']);
    return ['found'=>$found,'verified'=>$verified,'qid'=>$qid,'identityScore'=>$bestScore,'message'=>'Collecte Wikidata/Wikipédia terminée'];
}


function p50_de_collect_state_facts(array $profile): int {
    $state=p50_de_load_public_state();
    $map=p50_de_profile_state_map($state);
    $p=$map[(string)$profile['profile_id']]??null;
    if(!is_array($p))return 0;
    $date=trim((string)($p['birthDate']??''));
    if($date!==''&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){
        p50_de_add_fact_evidence((string)$profile['profile_id'],'birth_date',$date,$date,'state_import','État PASS50','',70);
        return 1;
    }
    return 0;
}

function p50_de_collect_state_links(array $profile): int {
    $state=p50_de_load_public_state();
    $map=p50_de_profile_state_map($state);
    $p=$map[(string)$profile['profile_id']]??null;
    if(!is_array($p))return 0;
    $count=0;
    foreach((array)($p['links']??[]) as $platform=>$url){
        if(!is_string($url)||trim($url)==='')continue;
        $validation=p50_de_validate_social_url((string)$platform,$url,(string)$profile['public_name'],(string)$profile['handle']);
        if($validation['normalizedUrl']==='')continue;
        p50_de_add_social_evidence((string)$profile['profile_id'],(string)$platform,$validation['normalizedUrl'],'state_import','État PASS50','',70,$validation);
        $count++;
    }
    return $count;
}

function p50_de_profile_verified_count(string $profileId): int {
    $stmt=db()->prepare("SELECT (SELECT COUNT(*) FROM p50_facts WHERE profile_id=? AND status='verified' AND confidence>=?) + (SELECT COUNT(*) FROM p50_social_links WHERE profile_id=? AND status='verified' AND confidence>=?)");
    $threshold=p50_de_threshold();
    $stmt->execute([$profileId,$threshold,$profileId,$threshold]);
    return (int)$stmt->fetchColumn();
}

function p50_de_verified_facts(string $profileId): array {
    $stmt=db()->prepare("SELECT fact_key,normalized_value,confidence,evidence_count,source_types,verified_at FROM p50_facts WHERE profile_id=? AND status='verified' AND confidence>=? ORDER BY confidence DESC");
    $stmt->execute([$profileId,p50_de_threshold()]);
    $out=[];
    foreach($stmt->fetchAll() as $row){if(!isset($out[$row['fact_key']]))$out[$row['fact_key']]=$row;}
    return $out;
}

function p50_de_social_links(string $profileId, bool $verifiedOnly=false): array {
    $sql='SELECT profile_id,platform,normalized_url url,confidence,evidence_count,source_types,status,validation_json,checked_at,verified_at FROM p50_social_links WHERE profile_id=?';
    $params=[$profileId];
    if($verifiedOnly){$sql.=" AND status='verified' AND confidence>=?";$params[]=p50_de_threshold();}
    $sql.=' ORDER BY platform';
    $stmt=db()->prepare($sql);$stmt->execute($params);
    $rows=$stmt->fetchAll();
    foreach($rows as &$row){$row['sourceTypes']=decode_json_column($row['source_types']??null,[]);$row['validation']=decode_json_column($row['validation_json']??null,[]);unset($row['source_types'],$row['validation_json']);}
    return $rows;
}

function p50_de_publish_profile(string $profileId, ?string $userId=null): bool {
    $state=p50_de_load_public_state();
    if(!$state)return false;
    $facts=p50_de_verified_facts($profileId);
    $links=p50_de_social_links($profileId,true);
    $changed=false;
    foreach((array)($state['profiles']??[]) as &$p){
        if(!is_array($p)||(string)($p['id']??'')!==$profileId)continue;
        $publicLinks=[];$maxSocial=0;
        foreach($links as $link){$publicLinks[(string)$link['platform']]=(string)$link['url'];$maxSocial=max($maxSocial,(int)$link['confidence']);}
        if(($p['links']??[])!==$publicLinks){$p['links']=$publicLinks;$changed=true;}
        $p['quality']=is_array($p['quality']??null)?$p['quality']:[];
        $p['quality']['identity']=100;
        $p['quality']['social']=$maxSocial;
        if(isset($facts['birth_date'])){
            $date=(string)$facts['birth_date']['normalized_value'];
            if(($p['birthDate']??null)!==$date){$p['birthDate']=$date;$changed=true;}
            $p['birthYear']=(int)substr($date,0,4);
            $p['ageStatus']='confirmed';
            $p['quality']['birth']=(int)$facts['birth_date']['confidence'];
        }else{
            unset($p['birthDate'],$p['birthYear']);
            $p['ageStatus']='unconfirmed';
            $p['quality']['birth']=0;
        }
        $p['lastCollectedAt']=gmdate('c');
        $p['dataEngine']=['threshold'=>p50_de_threshold(),'publishedAt'=>gmdate('c'),'verifiedFacts'=>array_keys($facts),'verifiedSocialLinks'=>count($links)];
        $changed=true;
        break;
    }
    unset($p);
    if($changed){$state['dataEngineMeta']=['threshold'=>p50_de_threshold(),'lastPublishedAt'=>gmdate('c')];p50_de_save_public_state($state,$userId);}
    return $changed;
}

function p50_de_publish_all(?string $userId=null): int {
    $profiles=p50_de_registry_profiles(null,100,0);$count=0;
    foreach($profiles as $profile)if(p50_de_publish_profile((string)$profile['profile_id'],$userId))$count++;
    return $count;
}


function p50_de_add_activity(string $profileId,string $platform,string $type,string $title,string $url,?string $publishedAt,array $metrics,int $confidence): void {
    if(!filter_var($url,FILTER_VALIDATE_URL))return;
    $status=$confidence>=p50_de_threshold()?'verified':'candidate';
    $safeTitle=function_exists('mb_substr')?mb_substr($title,0,255,'UTF-8'):substr($title,0,255);
    $stmt=db()->prepare("INSERT INTO p50_activity_events(profile_id,platform,event_type,title,url,url_hash,published_at,metrics,confidence,status,collected_at)
        VALUES(?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE title=VALUES(title),published_at=VALUES(published_at),metrics=VALUES(metrics),confidence=GREATEST(confidence,VALUES(confidence)),status=IF(GREATEST(confidence,VALUES(confidence))>=?,'verified',status),collected_at=NOW()");
    $stmt->execute([$profileId,$platform,$type,$safeTitle,$url,p50_de_hash($url),$publishedAt,json_encode($metrics,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),max(0,min(100,$confidence)),$status,p50_de_threshold()]);
}

function p50_de_youtube_channel_id(string $url): ?string {
    $path=trim((string)(parse_url($url,PHP_URL_PATH)?:''),'/');
    if(preg_match('#^channel/(UC[A-Za-z0-9_-]{20,})$#',$path,$m))return $m[1];
    $r=p50_http_fetch($url,12,'text/html,*/*;q=0.7');
    if(!$r['ok'])return null;
    $patterns=[
        '/"channelId"\s*:\s*"(UC[A-Za-z0-9_-]{20,})"/',
        '/itemprop="channelId"\s+content="(UC[A-Za-z0-9_-]{20,})"/i',
        '/youtube\.com\/channel\/(UC[A-Za-z0-9_-]{20,})/',
    ];
    foreach($patterns as $pattern)if(preg_match($pattern,$r['body'],$m))return $m[1];
    return null;
}

function p50_de_collect_youtube_activity(array $profile): array {
    $profileId=(string)$profile['profile_id'];
    $links=p50_de_social_links($profileId,true);
    $youtube=null;
    foreach($links as $link)if(strcasecmp((string)$link['platform'],'YouTube')===0){$youtube=$link;break;}
    if(!$youtube)return ['found'=>0,'verified'=>0,'message'=>'Aucune chaîne YouTube vérifiée'];
    $channelId=p50_de_youtube_channel_id((string)$youtube['url']);
    if(!$channelId)return ['found'=>0,'verified'=>0,'message'=>'Identifiant de chaîne YouTube introuvable'];
    $feedUrl='https://www.youtube.com/feeds/videos.xml?channel_id='.rawurlencode($channelId);
    $r=p50_http_fetch($feedUrl,15,'application/atom+xml,application/xml,text/xml;q=0.9,*/*;q=0.5');
    if(!$r['ok']||$r['body']==='')return ['found'=>0,'verified'=>0,'message'=>'Flux YouTube indisponible','httpStatus'=>$r['status']];
    if(!function_exists('simplexml_load_string'))return ['found'=>0,'verified'=>0,'message'=>'Extension XML indisponible'];
    libxml_use_internal_errors(true);
    $xml=simplexml_load_string($r['body'],'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA);
    if(!$xml)return ['found'=>0,'verified'=>0,'message'=>'Flux YouTube invalide'];
    $namespaces=$xml->getNamespaces(true);$found=0;
    foreach($xml->entry as $entry){
        $yt=isset($namespaces['yt'])?$entry->children($namespaces['yt']):null;
        $media=isset($namespaces['media'])?$entry->children($namespaces['media']):null;
        $videoId=(string)($yt?->videoId??'');
        $title=trim((string)$entry->title);
        $publishedRaw=(string)$entry->published;
        $published=$publishedRaw!==''?(new DateTimeImmutable($publishedRaw))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'):null;
        $url=$videoId!==''?'https://www.youtube.com/watch?v='.$videoId:'';
        if($url===''||$title==='')continue;
        $metrics=['channelId'=>$channelId,'author'=>(string)($entry->author->name??''),'updated'=>(string)$entry->updated];
        if($media){$group=$media->group;$stats=$group?->community?->statistics;$views=(string)($stats['views']??'');if($views!=='')$metrics['views']=(int)$views;}
        p50_de_add_activity($profileId,'YouTube','video',$title,$url,$published,$metrics,(int)$youtube['confidence']);
        $found++;if($found>=10)break;
    }
    return ['found'=>$found,'verified'=>$found,'channelId'=>$channelId,'feedUrl'=>$feedUrl,'message'=>'Activité YouTube collectée'];
}

function p50_de_activity_events(string $profileId,bool $verifiedOnly=true,int $limit=20): array {
    $limit=max(1,min(100,$limit));
    $sql='SELECT platform,event_type,title,url,published_at,metrics,confidence,status,collected_at FROM p50_activity_events WHERE profile_id=?';
    $params=[$profileId];
    if($verifiedOnly){$sql.=" AND status='verified' AND confidence>=?";$params[]=p50_de_threshold();}
    $sql.=" ORDER BY COALESCE(published_at,collected_at) DESC LIMIT $limit";
    $stmt=db()->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll();
    foreach($rows as &$row){$row['metrics']=decode_json_column($row['metrics']??null,[]);}unset($row);
    return $rows;
}

function p50_de_capture_snapshots(string $period='2H'): int {
    $state=p50_de_load_public_state();
    $profiles=(array)($state['profiles']??[]);
    $eligible=array_values(array_filter($profiles,static fn($p)=>is_array($p)&&(!array_key_exists('alive',$p)||!empty($p['alive']))&&(!array_key_exists('eligible',$p)||!empty($p['eligible']))));
    usort($eligible,static function($a,$b)use($period){$sa=(float)($a['scores'][$period]??$a['score']??0);$sb=(float)($b['scores'][$period]??$b['score']??0);return $sb<=>$sa;});
    $stmt=db()->prepare('INSERT INTO p50_ranking_snapshots(profile_id,period_key,rank_position,trend_score,rank_delta,badges,data_confidence,captured_at) VALUES(?,?,?,?,?,?,?,NOW())');
    $count=0;
    foreach($eligible as $index=>$p){
        if(empty($p['id']))continue;
        $quality=(array)($p['quality']??[]);
        $values=array_filter(array_map('intval',$quality),static fn($v)=>$v>0);
        $confidence=$values?(int)round(array_sum($values)/count($values)):0;
        $stmt->execute([(string)$p['id'],$period,$index+1,(float)($p['scores'][$period]??$p['score']??0),(int)($p['delta']??0),json_encode((array)($p['badges']??[]),JSON_UNESCAPED_UNICODE),$confidence]);
        $count++;
    }
    return $count;
}

function p50_de_hub_payload(): array {
    p50_de_ensure_schema();
    p50_de_sync_registry_from_state();
    $state=p50_de_load_public_state();$stateMap=p50_de_profile_state_map($state);
    $profiles=[];$threshold=p50_de_threshold();
    foreach(p50_de_registry_profiles(null,100,0) as $r){
        $id=(string)$r['profile_id'];$sp=$stateMap[$id]??[];
        $facts=p50_de_verified_facts($id);$social=p50_de_social_links($id,false);
        $runStmt=db()->prepare('SELECT status,collector,started_at,finished_at,error_message FROM p50_collection_runs WHERE profile_id=? ORDER BY started_at DESC LIMIT 1');$runStmt->execute([$id]);$lastRun=$runStmt->fetch()?:null;
        $photoConfidence=((string)($sp['photoStatus']??'')==='validated')?100:0;
        $scoreConfidence=(int)($sp['quality']['score']??0);
        $liveConfidence=(int)($sp['quality']['live']??0);
        $birthConfidence=(int)($facts['birth_date']['confidence']??0);
        $socialConfidence=0;$verifiedSocial=0;
        foreach($social as $l){if($l['status']==='verified'&&(int)$l['confidence']>=$threshold){$verifiedSocial++;$socialConfidence=max($socialConfidence,(int)$l['confidence']);}}
        $qualities=['identity'=>100,'photo'=>$photoConfidence,'birth'=>$birthConfidence,'social'=>$socialConfidence,'score'=>$scoreConfidence,'live'=>$liveConfidence];
        $complete=count(array_filter($qualities,static fn($v)=>$v>=$threshold));
        $profiles[]=[
            'id'=>$id,'name'=>$r['public_name'],'handle'=>$r['handle'],'region'=>$r['region'],'category'=>$r['category'],
            'quality'=>$qualities,'completeness'=>(int)round($complete/count($qualities)*100),
            'facts'=>$facts,'socialLinks'=>$social,'verifiedSocialCount'=>$verifiedSocial,'lastRun'=>$lastRun,
            'lastCollectedAt'=>$lastRun['finished_at']??null,
        ];
    }
    $kpis=[
        'profiles'=>count($profiles),
        'birthVerified'=>count(array_filter($profiles,static fn($p)=>$p['quality']['birth']>=$threshold)),
        'socialVerified'=>count(array_filter($profiles,static fn($p)=>$p['quality']['social']>=$threshold)),
        'fullyReliable'=>count(array_filter($profiles,static fn($p)=>$p['completeness']>=67)),
    ];
    return ['ok'=>true,'threshold'=>$threshold,'kpis'=>$kpis,'profiles'=>$profiles,'generatedAt'=>gmdate('c')];
}
