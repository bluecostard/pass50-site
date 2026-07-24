<?php
declare(strict_types=1);

require_once __DIR__ . '/http-tools.php';

const P50_DATA_CONFIDENCE_THRESHOLD = 90;
const P50_PRIORITY_WAVE_V22 = [
    'census-didi-b','census-himra','census-ks-bloom','census-roseline-layo','census-josey',
    'census-doupi-papillon','census-ange-freddy','census-eudoxie-yao','census-willy-dumbo',
    'census-jonathan-morrison','census-lexes','census-chris-vital','census-mamie-show',
    'census-artiste-de-poulet','census-jr-lamelo','census-bb-sans-os-de-man'
];

function p50_de_is_priority_profile(string $profileId): bool {
    return in_array($profileId,P50_PRIORITY_WAVE_V22,true);
}

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
        "CREATE TABLE IF NOT EXISTS p50_social_link_audit (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            profile_id VARCHAR(100) NOT NULL,
            platform VARCHAR(32) NOT NULL,
            action_type VARCHAR(32) NOT NULL,
            previous_url TEXT NULL,
            new_url TEXT NULL,
            actor_id CHAR(36) NULL,
            actor_role VARCHAR(24) NOT NULL DEFAULT '',
            actor_name VARCHAR(190) NOT NULL DEFAULT '',
            metadata_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_p50_social_audit_profile (profile_id,created_at),
            INDEX idx_p50_social_audit_platform (profile_id,platform,created_at)
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

function p50_de_registry_profiles(?string $profileId = null, int $limit = 1000, int $offset = 0, bool $eligibleOnly = false): array {
    p50_de_ensure_schema();
    if ($profileId !== null && $profileId !== '') {
        $stmt = db()->prepare('SELECT * FROM p50_profile_registry WHERE profile_id=? LIMIT 1');
        $stmt->execute([$profileId]);
        $row = $stmt->fetch();
        return $row ? [$row] : [];
    }
    $limit = max(1, min(1000, $limit));
    $offset = max(0, $offset);
    $where = $eligibleOnly ? 'alive=1 AND eligible=1' : 'alive=1';
    $stmt = db()->prepare("SELECT * FROM p50_profile_registry WHERE $where ORDER BY public_name LIMIT $limit OFFSET $offset");
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Sélectionne en priorité les profils jamais collectés, puis les plus anciens.
 * Le moteur travaille ainsi sur toute la base, y compris les profils non classables.
 */
function p50_de_profiles_for_collection(int $limit = 5, ?string $profileId = null, array $excludeIds = []): array {
    p50_de_ensure_schema();
    if ($profileId !== null && $profileId !== '') return p50_de_registry_profiles($profileId,1,0,false);
    $limit=max(1,min(10,$limit));
    $exclude=[];
    foreach(array_slice($excludeIds,0,500) as $candidate){
        $candidate=trim((string)$candidate);
        if($candidate!==''&&preg_match('/^[A-Za-z0-9._:-]{1,120}$/',$candidate))$exclude[$candidate]=true;
    }
    $params=[];
    $where='r.alive=1';
    if($exclude){
        $ids=array_keys($exclude);
        $where.=' AND r.profile_id NOT IN ('.implode(',',array_fill(0,count($ids),'?')).')';
        $params=array_merge($params,$ids);
    }
    $priority="'census-didi-b','census-himra','census-ks-bloom','census-roseline-layo','census-josey','census-doupi-papillon','census-ange-freddy','census-eudoxie-yao','census-willy-dumbo','census-jonathan-morrison','census-lexes','census-chris-vital','census-mamie-show','census-artiste-de-poulet','census-jr-lamelo','census-bb-sans-os-de-man'";
    $sql="SELECT r.*,runs.last_run_at
          FROM p50_profile_registry r
          LEFT JOIN (
              SELECT profile_id,MAX(started_at) last_run_at
              FROM p50_collection_runs
              GROUP BY profile_id
          ) runs ON runs.profile_id=r.profile_id
          WHERE $where
          ORDER BY CASE WHEN runs.last_run_at IS NULL THEN 0 ELSE 1 END ASC,
                   CASE WHEN r.profile_id IN ($priority) AND (runs.last_run_at IS NULL OR runs.last_run_at<DATE_SUB(NOW(),INTERVAL 6 HOUR)) THEN 0 ELSE 1 END ASC,
                   runs.last_run_at ASC,r.public_name ASC
          LIMIT $limit";
    $stmt=db()->prepare($sql);
    $stmt->execute($params);
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

function p50_de_source_identity(string $sourceType, string $sourceName, string $sourceUrl): string {
    $sourceType = trim($sourceType);
    $sourceName = trim(mb_strtolower($sourceName));
    // Les décisions manuelles doivent rester distinctes et parfaitement auditables.
    if (in_array($sourceType,['manual_owner','manual_admin'],true)) {
        return $sourceType.'|'.$sourceName.'|'.trim($sourceUrl);
    }
    $host = strtolower((string)(parse_url($sourceUrl,PHP_URL_HOST) ?: ''));
    $host = preg_replace('/^www\./','',$host) ?: $host;
    // Plusieurs pages du même média ou établissement comptent comme une seule source indépendante.
    if ($host !== '') return $sourceType.'|'.$host;
    return $sourceType.'|'.$sourceName;
}

function p50_de_normalize_profile_name(string $value): string {
    $value=p50_normalize_text($value);
    return preg_replace('/[^a-z0-9]+/','',$value) ?: '';
}

function p50_de_collect_curated_evidence_v221(array $profile): int {
    static $pack = null;
    if ($pack === null) {
        $path = dirname(__DIR__).'/pass50_v22_1_evidence_pack.json';
        if (!is_file($path)) { $pack = []; }
        else {
            $decoded = json_decode((string)file_get_contents($path),true);
            $pack = is_array($decoded) ? $decoded : [];
        }
    }
    $profileId = (string)($profile['profile_id'] ?? '');
    if ($profileId === '') return 0;
    $profileName=p50_de_normalize_profile_name((string)($profile['public_name']??''));
    $found = 0;
    foreach ($pack as $item) {
        if (!is_array($item)) continue;
        $itemId=(string)($item['profile_id']??'');
        $itemName=p50_de_normalize_profile_name((string)($item['profile_name']??''));
        if($itemId!==$profileId&&($itemName===''||$profileName===''||$itemName!==$profileName))continue;
        $factKey = trim((string)($item['fact_key'] ?? ''));
        $value = trim((string)($item['normalized_value'] ?? ''));
        if ($factKey === '' || $value === '') continue;
        p50_de_add_fact_evidence(
            $profileId,
            $factKey,
            $value,
            $item['raw_value'] ?? $value,
            (string)($item['source_type'] ?? 'curated_research_v221'),
            (string)($item['source_name'] ?? 'Recherche PASS50 V22.1'),
            (string)($item['source_url'] ?? ''),
            (int)($item['source_weight'] ?? 85)
        );
        $found++;
    }
    return $found;
}

function p50_de_add_fact_evidence(string $profileId, string $factKey, string $normalizedValue, mixed $rawValue, string $sourceType, string $sourceName, string $sourceUrl, int $sourceWeight): void {
    $normalizedValue = trim($normalizedValue);
    if ($normalizedValue === '') return;
    $raw = is_string($rawValue) ? $rawValue : (json_encode($rawValue,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '');
    $valueHash = p50_de_hash($normalizedValue);
    $sourceHash = p50_de_hash(p50_de_source_identity($sourceType,$sourceName,$sourceUrl));
    $stmt = db()->prepare("INSERT INTO p50_fact_evidence(profile_id,fact_key,normalized_value,value_hash,raw_value,source_type,source_name,source_url,source_hash,source_weight,fetched_at)
        VALUES(?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE raw_value=VALUES(raw_value),source_weight=VALUES(source_weight),fetched_at=NOW(),source_url=VALUES(source_url)");
    $stmt->execute([$profileId,$factKey,$normalizedValue,$valueHash,$raw,$sourceType,$sourceName,$sourceUrl,$sourceHash,max(0,min(100,$sourceWeight))]);
    p50_de_rebuild_fact($profileId,$factKey);
}

function p50_de_type_matches(array $types, string $prefix): bool {
    foreach($types as $type){
        $type=(string)$type;
        if($type===$prefix||str_starts_with($type,$prefix.'_'))return true;
    }
    return false;
}

function p50_de_rebuild_fact(string $profileId, string $factKey): void {
    $stmt = db()->prepare('SELECT normalized_value,value_hash,MAX(raw_value) raw_value,COUNT(*) evidence_count,COUNT(DISTINCT source_hash) independent_source_count,COUNT(DISTINCT source_type) source_type_count,MAX(source_weight) max_weight,AVG(source_weight) avg_weight,GROUP_CONCAT(DISTINCT source_type ORDER BY source_type SEPARATOR ",") source_types FROM p50_fact_evidence WHERE profile_id=? AND fact_key=? GROUP BY normalized_value,value_hash');
    $stmt->execute([$profileId,$factKey]);
    $groups = $stmt->fetchAll();
    if (!$groups) return;
    $scored = [];
    foreach ($groups as $g) {
        $types = array_values(array_filter(explode(',',(string)$g['source_types'])));
        $manualOwner = in_array('manual_owner',$types,true);
        $manualAdmin = in_array('manual_admin',$types,true);
        $manualLegacy = p50_de_type_matches($types,'manual_source');
        $official = p50_de_type_matches($types,'official_site');
        $officialLabel = p50_de_type_matches($types,'official_label') || p50_de_type_matches($types,'official_artist_label') || p50_de_type_matches($types,'official_broadcaster');
        $wikidataHigh = p50_de_type_matches($types,'wikidata_high_match');
        $wikipediaExact = p50_de_type_matches($types,'wikipedia_exact');
        $academicOfficial = p50_de_type_matches($types,'academic_official') || p50_de_type_matches($types,'public_institution') || p50_de_type_matches($types,'diploma_public_archive');
        $curatedResearch = p50_de_type_matches($types,'curated_research_v22');
        $sourceCount = (int)($g['independent_source_count'] ?? $g['source_type_count']);
        $maxWeight = (int)$g['max_weight'];
        $avgWeight = (float)$g['avg_weight'];

        if ($manualOwner) $confidence = 100;
        elseif ($manualAdmin||$manualLegacy) $confidence = 98;
        elseif ($factKey === 'photo_url') {
            // Une photo proposée automatiquement reste à confirmer humainement.
            $confidence = min(89,max(60,(int)round($maxWeight*0.90)));
        } elseif ($factKey === 'birth_date') {
            if($academicOfficial&&$maxWeight>=96)$confidence=97;
            elseif(($official||$officialLabel)&&$maxWeight>=96)$confidence=97;
            elseif($wikidataHigh&&$maxWeight>=94)$confidence=94;
            elseif($wikipediaExact&&$maxWeight>=92)$confidence=92;
            elseif($sourceCount>=2)$confidence=min(100,90+max(0,min(10,(int)round(($avgWeight-80)/1.5))));
            else $confidence=min(89,(int)round($maxWeight*0.90));
        } elseif ($academicOfficial&&$maxWeight>=94) {
            $confidence=96;
        } elseif ($curatedResearch&&$maxWeight>=94) {
            $confidence=94;
        } elseif (($official||$officialLabel)&&$maxWeight>=94) {
            $confidence=96;
        } elseif ($wikidataHigh&&$maxWeight>=94) {
            $confidence=94;
        } elseif ($wikipediaExact&&$maxWeight>=90) {
            $confidence=92;
        } elseif ($sourceCount >= 2) {
            $confidence = min(100, 90 + max(0,min(10,(int)round(($avgWeight-80)/1.5))));
        } else {
            $confidence = $maxWeight >= 98 ? 95 : min(89,(int)round($maxWeight*0.90));
        }
        $scored[] = $g + ['confidence'=>$confidence,'types'=>$types];
    }
    usort($scored, static fn($a,$b) => $b['confidence'] <=> $a['confidence'] ?: $b['evidence_count'] <=> $a['evidence_count']);
    $best = $scored[0];
    $bestManual = in_array('manual_owner',$best['types'],true) || in_array('manual_admin',$best['types'],true) || p50_de_type_matches($best['types'],'manual_source');
    $conflict = false;
    if (!$bestManual && isset($scored[1]) && (int)$scored[1]['confidence'] >= p50_de_threshold()) {
        $gap = (int)$best['confidence'] - (int)$scored[1]['confidence'];
        // Pour une naissance, toute deuxième date fortement étayée bloque la publication.
        // Pour les autres faits, deux versions fortes et proches restent en conflit.
        $conflict = $factKey === 'birth_date' ? true : $gap <= 4;
    }
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
    if ($platform === 'facebook' && strtolower(trim($path,'/')) === 'profile.php' && !empty($parts['query'])) { parse_str((string)$parts['query'],$fbq); if (!empty($fbq['id']) && ctype_digit((string)$fbq['id'])) $query='?id='.(string)$fbq['id']; }
    return $scheme.'://'.$host.$path.$query;
}

function p50_de_direct_social_path(string $platform, string $url): bool {
    $path = trim((string)(parse_url($url,PHP_URL_PATH) ?: ''),'/');
    $query = (string)(parse_url($url,PHP_URL_QUERY) ?: '');
    $segments = array_values(array_filter(explode('/',$path),static fn($x)=>$x!==''));
    $first = strtolower((string)($segments[0]??''));
    $p = strtolower($platform);
    if ($p === 'web') return true;
    if ($path === '' || preg_match('#^(search|explore|results|home|watch|feed|login)(/|$)#i',$path)) return false;
    if (str_contains($query,'search_query=')) return false;
    return match($p) {
        'instagram' => count($segments)===1 && !in_array($first,['accounts','about','developer','developers','direct','directory','explore','legal','privacy','reel','reels','stories','terms'],true) && preg_match('/^[A-Za-z0-9._-]+$/',$segments[0])===1,
        'tiktok' => count($segments)===1 && preg_match('/^@[A-Za-z0-9._-]+$/',$segments[0])===1,
        'youtube' => preg_match('#^(@[A-Za-z0-9._-]+|(channel|c|user)/[A-Za-z0-9._-]+)$#i',$path)===1,
        'facebook' => (count($segments)===1 && !in_array($first,['login','home','watch','groups','marketplace','gaming','events','reel','reels','share','sharer','photo','photos','videos','help','privacy','settings','checkpoint'],true) && preg_match('/^[A-Za-z0-9._-]+$/',$segments[0])===1) || ($first==='profile.php' && isset($segments[0]) && preg_match('/(?:^|&)id=\d+(?:&|$)/',$query)===1) || (count($segments)===3 && $first==='pages' && ctype_digit((string)$segments[2])),
        'x' => count($segments)===1 && !in_array($first,['home','explore','notifications','messages','i','search','settings','compose'],true) && preg_match('/^[A-Za-z0-9_]+$/',$segments[0])===1,
        'snapchat' => count($segments)===2 && strtolower($segments[0])==='add' && preg_match('/^[A-Za-z0-9._-]+$/',$segments[1])===1,
        'linkedin' => count($segments)===2 && in_array(strtolower($segments[0]),['in','company'],true) && preg_match('/^[A-Za-z0-9._-]+$/',$segments[1])===1,
        default => true,
    };
}

function p50_de_validate_social_url(string $platform, string $url, string $name = '', string $handle = ''): array {
    $normalized = p50_de_normalize_social_url($platform,$url);
    if ($normalized === '') return ['ok'=>false,'status'=>'invalid','normalizedUrl'=>'','httpStatus'=>0,'nameScore'=>0,'message'=>'URL invalide'];
    if (!p50_platform_host_ok($platform,$normalized)) return ['ok'=>false,'status'=>'wrong_platform','normalizedUrl'=>$normalized,'httpStatus'=>0,'nameScore'=>0,'message'=>'Le domaine ne correspond pas à la plateforme'];
    if (!p50_de_direct_social_path($platform,$normalized)) return ['ok'=>false,'status'=>'generic_or_content','normalizedUrl'=>$normalized,'httpStatus'=>0,'nameScore'=>0,'message'=>'Le lien doit pointer vers un profil officiel direct'];
    $r = p50_http_fetch($normalized,10,'text/html,*/*;q=0.7',true);
    if (!$r['ok'] && in_array((int)$r['status'],[401,403,405,429,451],true)) $r = p50_http_fetch($normalized,10,'text/html,*/*;q=0.7');
    $finalNormalized = p50_de_normalize_social_url($platform,$r['finalUrl'] ?: '');
    $redirectedAway = $finalNormalized !== '' && (!p50_platform_host_ok($platform,$finalNormalized) || !p50_de_direct_social_path($platform,$finalNormalized));
    $blockedStatus = in_array((int)$r['status'],[0,401,403,405,429,451],true) || (int)$r['status']>=500;
    // Facebook, Instagram, TikTok et YouTube redirigent souvent les contrôles serveur
    // vers login/challenge/consent. Le lien soumis reste la source de vérité.
    $blocked = $redirectedAway || $blockedStatus;
    $metadata = $r['body'] !== '' ? p50_page_metadata($r['body'],$r['finalUrl'] ?: $normalized) : ['title'=>'','description'=>'','image'=>'','canonical'=>''];
    $nameScore = p50_name_score(($metadata['title']??'').' '.($metadata['description']??'').' '.$normalized,$name,$handle);
    $explicitMissing = in_array((int)$r['status'],[404,410],true) && !$redirectedAway;
    $ok = !$explicitMissing && ($r['ok'] || $blocked);
    return [
        'ok'=>$ok,
        'status'=>$explicitMissing?'unreachable':($blocked?'blocked_but_exists':'accessible'),
        'normalizedUrl'=>$redirectedAway||$finalNormalized===''?$normalized:$finalNormalized,
        'httpStatus'=>(int)$r['status'],
        'nameScore'=>$nameScore,
        'title'=>(string)($metadata['title']??''),
        'message'=>$explicitMissing?'Profil introuvable':($blocked?'Profil direct valide ; la plateforme bloque le contrôle automatique':'Lien direct accessible'),
    ];
}

function p50_de_log_social_action(string $profileId,string $platform,string $actionType,?string $previousUrl,?string $newUrl,?array $user=null,array $metadata=[]): void {
    p50_de_ensure_schema();
    $stmt=db()->prepare('INSERT INTO p50_social_link_audit(profile_id,platform,action_type,previous_url,new_url,actor_id,actor_role,actor_name,metadata_json,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())');
    $stmt->execute([
        $profileId,$platform,$actionType,$previousUrl,$newUrl,
        $user['id']??null,(string)($user['role']??''),(string)($user['display_name']??$user['email']??''),
        json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
    ]);
}

function p50_de_social_history(string $profileId,int $limit=100): array {
    p50_de_ensure_schema();
    $limit=max(1,min(500,$limit));
    $stmt=db()->prepare("SELECT id,profile_id,platform,action_type,previous_url,new_url,actor_role,actor_name,metadata_json,created_at FROM p50_social_link_audit WHERE profile_id=? ORDER BY id DESC LIMIT $limit");
    $stmt->execute([$profileId]);
    $rows=$stmt->fetchAll();
    foreach($rows as &$row){$row['metadata']=decode_json_column($row['metadata_json']??null,[]);unset($row['metadata_json']);}
    return $rows;
}

function p50_de_current_social_url(string $profileId,string $platform): string {
    $stmt=db()->prepare('SELECT normalized_url FROM p50_social_links WHERE profile_id=? AND platform=? LIMIT 1');
    $stmt->execute([$profileId,$platform]);
    return (string)($stmt->fetchColumn()?:'');
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
    $stmt = db()->prepare('SELECT normalized_url,url_hash,COUNT(*) evidence_count,COUNT(DISTINCT source_type) source_type_count,MAX(source_weight) max_weight,AVG(source_weight) avg_weight,GROUP_CONCAT(DISTINCT source_type ORDER BY source_type SEPARATOR ",") source_types,MAX(validation_json) validation_json,MAX(fetched_at) latest_fetched_at FROM p50_social_link_evidence WHERE profile_id=? AND platform=? GROUP BY normalized_url,url_hash');
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
        $official=p50_de_type_matches($types,'official_site');
        $wikidataHigh=p50_de_type_matches($types,'wikidata_high_match');
        $curatedResearch=p50_de_type_matches($types,'curated_research_v22');
        if($manualOwner)$confidence=100;
        elseif($manualAdmin)$confidence=98;
        elseif($official&&$maxWeight>=94)$confidence=96;
        elseif($curatedResearch&&$maxWeight>=94)$confidence=94;
        elseif($wikidataHigh&&$maxWeight>=92)$confidence=94;
        elseif($sourceCount>=2)$confidence=min(100,92+max(0,min(8,(int)round(($avgWeight-82)/2))));
        elseif(in_array('wikidata',$types,true)&&$maxWeight>=92)$confidence=92;
        else $confidence=min(89,(int)round($maxWeight*0.92));
        $scored[]=$g+['confidence'=>$confidence,'types'=>$types];
    }
    usort($scored,static fn($a,$b)=>$b['confidence']<=>$a['confidence']?:strcmp((string)($b['latest_fetched_at']??''),(string)($a['latest_fetched_at']??''))?:$b['evidence_count']<=>$a['evidence_count']);
    $best=$scored[0];
    $bestIsManual=in_array('manual_owner',$best['types'],true)||in_array('manual_admin',$best['types'],true);
    // Deux anciennes validations manuelles ne doivent pas bloquer la publication :
    // la plus récente est l'intention explicite du propriétaire.
    $conflict=!$bestIsManual&&isset($scored[1])&&(int)$scored[1]['confidence']>=p50_de_threshold()&&(int)$scored[1]['confidence']===(int)$best['confidence'];
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

function p50_de_normalize_iso_date(string $value): string {
    $value=trim($value);
    if($value==='')return '';
    if(preg_match('/^(\d{4})-(\d{2})-(\d{2})/',$value,$m)){
        $y=(int)$m[1];$mo=(int)$m[2];$d=(int)$m[3];
        return checkdate($mo,$d,$y)?sprintf('%04d-%02d-%02d',$y,$mo,$d):'';
    }
    $dates=p50_de_parse_dates($value);
    return $dates[0]??'';
}

function p50_de_jsonld_walk(mixed $value, array &$out): void {
    if(!is_array($value))return;
    $isList=array_is_list($value);
    if(!$isList)$out[]=$value;
    foreach($value as $child)if(is_array($child))p50_de_jsonld_walk($child,$out);
}

function p50_de_jsonld_nodes(string $html): array {
    preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is',$html,$matches);
    $nodes=[];
    foreach((array)($matches[1]??[]) as $raw){
        $raw=html_entity_decode(trim((string)$raw),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $decoded=json_decode($raw,true);
        if(is_array($decoded))p50_de_jsonld_walk($decoded,$nodes);
    }
    return $nodes;
}

function p50_de_jsonld_types(array $node): array {
    $types=$node['@type']??[];
    if(is_string($types))$types=[$types];
    return array_map(static fn($x)=>strtolower((string)$x),is_array($types)?$types:[]);
}

function p50_de_jsonld_string(mixed $value): string {
    if(is_string($value))return trim($value);
    if(is_array($value)){
        foreach(['name','url','@id','contentUrl'] as $key)if(isset($value[$key])&&is_string($value[$key]))return trim($value[$key]);
        foreach($value as $child){$v=p50_de_jsonld_string($child);if($v!=='')return $v;}
    }
    return '';
}

function p50_de_jsonld_urls(mixed $value): array {
    $out=[];
    if(is_string($value)&&filter_var($value,FILTER_VALIDATE_URL))$out[]=$value;
    elseif(is_array($value))foreach($value as $child)$out=array_merge($out,p50_de_jsonld_urls($child));
    return array_values(array_unique($out));
}

function p50_de_birth_context_dates(string $text): array {
    $plain=html_entity_decode(strip_tags($text),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $patterns=[
        '/\b(?:né|née)\s+(?:à\s+[^,.\n]{0,60}\s+)?le\s+([^,.\n]{4,55})/iu',
        '/\b(?:date\s+de\s+naissance|naissance)\s*[:\-]\s*([^,.\n]{4,55})/iu',
        '/\bborn\s+(?:in\s+[^,.\n]{0,60}\s+)?on\s+([^,.\n]{4,55})/iu',
        '/\bdate\s+of\s+birth\s*[:\-]\s*([^,.\n]{4,55})/iu',
    ];
    $out=[];
    foreach($patterns as $pattern){
        preg_match_all($pattern,$plain,$matches,PREG_SET_ORDER);
        foreach($matches as $m)$out=array_merge($out,p50_de_parse_dates((string)($m[1]??'')));
    }
    return array_values(array_unique($out));
}

function p50_de_text_excerpt(string $value,int $limit=500): string {
    $value=trim(preg_replace('/\s+/u',' ',html_entity_decode(strip_tags($value),ENT_QUOTES|ENT_HTML5,'UTF-8'))??'');
    if($value==='')return '';
    return function_exists('mb_substr')?mb_substr($value,0,$limit,'UTF-8'):substr($value,0,$limit);
}

function p50_de_infer_category(string $text): string {
    $t=p50_normalize_text($text);
    $rules=[
        'Humour / Divertissement'=>['humoriste','comedien','comedienne','comique','sketch','web humor','acteur comique'],
        'Musique'=>['chanteur','chanteuse','rappeur','rappeuse','musicien','musicienne','artiste musical','disc jockey',' dj '],
        'Médias'=>['journaliste','animateur','animatrice','presentateur','presentatrice','chroniqueur','television','radio'],
        'Beauté'=>['maquilleur','maquilleuse','makeup','cosmetique','beaute'],
        'Mode'=>['mannequin','styliste','fashion','mode'],
        'Sport'=>['footballeur','footballeuse','athlete','sportif','sportive','football'],
        'Business'=>['entrepreneur','entrepreneuse','homme d affaires','femme d affaires','business'],
        'Cuisine'=>['chef cuisinier','cheffe cuisiniere','cuisinier','cuisiniere','gastronomie','food'],
        'Photographie'=>['photographe','photographie'],
        'Tech / Digital'=>['informaticien','informatique','technologie','digital','intelligence artificielle','developpeur'],
        'Lifestyle'=>['influenceur','influenceuse','createur de contenu','creatrice de contenu','youtubeur','youtubeuse','tiktokeur','tiktokeuse','blogueur','blogueuse'],
    ];
    foreach($rules as $category=>$keywords)foreach($keywords as $keyword)if(str_contains(' '.$t.' ',' '.trim($keyword).' ' )||str_contains($t,trim($keyword)))return $category;
    return '';
}

function p50_de_social_urls_from_html(string $html): array {
    preg_match_all('/<a[^>]+href=["\']([^"\']+)["\']/i',$html,$matches);
    $out=[];
    foreach((array)($matches[1]??[]) as $url){
        $url=html_entity_decode(trim((string)$url),ENT_QUOTES|ENT_HTML5,'UTF-8');
        if(!filter_var($url,FILTER_VALIDATE_URL))continue;
        $platform=p50_platform($url);
        if($platform==='Web'||!p50_de_direct_social_path($platform,$url))continue;
        $out[$platform]=$url;
    }
    return $out;
}

/** Analyse une page publique et transforme les données structurées en preuves. */
function p50_de_collect_page_enrichment(array $profile,string $url,string $sourceType,string $sourceName,int $weight,bool $official=false): array {
    if(!filter_var($url,FILTER_VALIDATE_URL)||!p50_public_http_url($url))return ['found'=>0,'message'=>'URL refusée'];
    $r=p50_http_fetch($url,10,'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5');
    if($r['body']==='')return ['found'=>0,'message'=>'Page vide','httpStatus'=>$r['status']];
    $final=$r['finalUrl']?:$url;
    if(str_starts_with($sourceType,'media_bio_')){
        $finalHost=strtolower((string)(parse_url($final,PHP_URL_HOST)?:''));
        $finalHost=preg_replace('/^www\./','',$finalHost)?:$finalHost;
        if($finalHost!==''&&!str_contains($finalHost,'news.google.')){$sourceType='media_bio_'.substr(hash('sha256',$finalHost),0,16);$sourceName=$finalHost;}
    }
    $meta=p50_page_metadata($r['body'],$final);
    $plain=p50_de_text_excerpt($r['body'],30000);
    $name=(string)$profile['public_name'];$handle=(string)$profile['handle'];$profileId=(string)$profile['profile_id'];
    $identityScore=p50_name_score(($meta['title']??'').' '.($meta['description']??'').' '.$plain.' '.$final,$name,$handle);
    if(!$official&&$identityScore<48)return ['found'=>0,'message'=>'Identité insuffisamment proche','identityScore'=>$identityScore];
    $found=0;$personNodes=[];
    foreach(p50_de_jsonld_nodes($r['body']) as $node){
        $types=p50_de_jsonld_types($node);
        if(!array_intersect($types,['person','profilepage','organization']))continue;
        $nodeName=p50_de_jsonld_string($node['name']??($node['mainEntity']['name']??''));
        $nodeScore=p50_name_score($nodeName.' '.p50_de_jsonld_string($node['alternateName']??''),$name,$handle);
        if($official||$nodeScore>=48)$personNodes[]=$node;
    }
    $birthDates=[];$sameAs=[];$image='';$description='';$occupation='';$nationality='';
    foreach($personNodes as $node){
        $birth=p50_de_normalize_iso_date(p50_de_jsonld_string($node['birthDate']??''));if($birth!=='')$birthDates[]=$birth;
        $sameAs=array_merge($sameAs,p50_de_jsonld_urls($node['sameAs']??[]));
        if($image==='')$image=p50_de_jsonld_string($node['image']??'');
        if($description==='')$description=p50_de_jsonld_string($node['description']??'');
        if($occupation==='')$occupation=p50_de_jsonld_string($node['jobTitle']??($node['hasOccupation']??''));
        if($nationality==='')$nationality=p50_de_jsonld_string($node['nationality']??'');
    }
    if($official||$identityScore>=60)$birthDates=array_merge($birthDates,p50_de_birth_context_dates($plain));
    $birthDates=array_values(array_unique($birthDates));
    foreach($birthDates as $date){p50_de_add_fact_evidence($profileId,'birth_date',$date,$date,$sourceType,$sourceName,$final,$weight);$found++;}
    $description=p50_de_text_excerpt($description!==''?$description:(string)($meta['description']??''),500);
    if($description!==''&&($official||$identityScore>=60)){p50_de_add_fact_evidence($profileId,'bio',$description,$description,$sourceType,$sourceName,$final,min(98,$weight));$found++;}
    if($occupation!==''){p50_de_add_fact_evidence($profileId,'occupation',$occupation,$occupation,$sourceType,$sourceName,$final,min(98,$weight));$found++;}
    $category=p50_de_infer_category($occupation.' '.$description.' '.$plain);
    if($category!==''){p50_de_add_fact_evidence($profileId,'category',$category,$category,$sourceType,$sourceName,$final,min(98,$weight));$found++;}
    if($nationality!==''){p50_de_add_fact_evidence($profileId,'nationality',$nationality,$nationality,$sourceType,$sourceName,$final,min(98,$weight));$found++;}
    if($image==='')$image=(string)($meta['image']??'');
    if(filter_var($image,FILTER_VALIDATE_URL)&&p50_public_http_url($image)){p50_de_add_fact_evidence($profileId,'photo_url',$image,$image,$sourceType,$sourceName,$final,min(95,$weight));$found++;}
    if($official){
        p50_de_add_fact_evidence($profileId,'official_website',$final,$final,$sourceType,$sourceName,$final,max(96,$weight));$found++;
        $sameAs=array_merge($sameAs,array_values(p50_de_social_urls_from_html($r['body'])));
    }
    foreach(array_values(array_unique($sameAs)) as $socialUrl){
        $platform=p50_platform($socialUrl);if($platform==='Web')continue;
        $validation=p50_de_validate_social_url($platform,$socialUrl,$name,$handle);
        if($validation['normalizedUrl']===''||in_array($validation['status'],['wrong_platform','generic_or_content','invalid'],true))continue;
        p50_de_add_social_evidence($profileId,$platform,$validation['normalizedUrl'],$sourceType,$sourceName,$final,$official?96:min(88,$weight),$validation);$found++;
    }
    return ['found'=>$found,'identityScore'=>$identityScore,'url'=>$final,'birthDates'=>$birthDates,'message'=>'Page enrichie'];
}

function p50_de_wikidata_labels(array $ids): array {
    $ids=array_values(array_unique(array_filter(array_map('strval',$ids),static fn($x)=>preg_match('/^Q\d+$/',$x))));
    if(!$ids)return [];
    $url='https://www.wikidata.org/w/api.php?'.http_build_query(['action'=>'wbgetentities','ids'=>implode('|',array_slice($ids,0,50)),'props'=>'labels','languages'=>'fr|en','format'=>'json']);
    $data=p50_json_get($url,12);$out=[];
    foreach((array)($data['entities']??[]) as $id=>$entity){$out[$id]=(string)($entity['labels']['fr']['value']??$entity['labels']['en']['value']??$id);}
    return $out;
}

function p50_de_wikimedia_file_url(string $file): string {
    $file=trim($file);if($file==='')return '';
    return 'https://commons.wikimedia.org/wiki/Special:Redirect/file/'.rawurlencode(str_replace(' ','_',$file));
}

function p50_de_collect_wikipedia_search(array $profile): array {
    $name=(string)$profile['public_name'];$handle=(string)$profile['handle'];$profileId=(string)$profile['profile_id'];
    $found=0;$pages=[];
    foreach(['fr','en'] as $lang){
        foreach(array_unique(array_filter([$name,ltrim($handle,'@')])) as $query){
            $searchUrl='https://'.$lang.'.wikipedia.org/w/api.php?'.http_build_query(['action'=>'query','list'=>'search','srsearch'=>$query,'srlimit'=>5,'format'=>'json']);
            $search=p50_json_get($searchUrl,10);
            foreach((array)($search['query']['search']??[]) as $row){
                $title=(string)($row['title']??'');if($title==='')continue;
                $score=p50_name_score($title.' '.strip_tags((string)($row['snippet']??'')),$name,$handle);
                if($score<50)continue;
                $key=$lang.'|'.$title;if(!isset($pages[$key])||$score>$pages[$key]['score'])$pages[$key]=['lang'=>$lang,'title'=>$title,'score'=>$score];
            }
        }
    }
    uasort($pages,static fn($a,$b)=>$b['score']<=>$a['score']);
    foreach(array_slice(array_values($pages),0,2) as $candidate){
        $lang=$candidate['lang'];$title=$candidate['title'];$score=(int)$candidate['score'];
        $api='https://'.$lang.'.wikipedia.org/w/api.php?'.http_build_query(['action'=>'query','prop'=>'extracts|info|pageimages','inprop'=>'url','exintro'=>1,'explaintext'=>1,'redirects'=>1,'piprop'=>'original','titles'=>$title,'format'=>'json']);
        $data=p50_json_get($api,12);
        foreach((array)($data['query']['pages']??[]) as $page){
            $extract=(string)($page['extract']??'');$pageTitle=(string)($page['title']??$title);
            $identity=p50_name_score($pageTitle.' '.$extract,$name,$handle);if($identity<55)continue;
            $url=(string)($page['fullurl']??('https://'.$lang.'.wikipedia.org/wiki/'.rawurlencode(str_replace(' ','_',$pageTitle))));
            $type='wikipedia_exact_'.$lang;
            foreach(p50_de_birth_context_dates($extract) as $date){p50_de_add_fact_evidence($profileId,'birth_date',$date,$date,$type,'Wikipédia · '.$lang,$url,93);$found++;}
            $bio=p50_de_text_excerpt($extract,500);if($bio!==''){p50_de_add_fact_evidence($profileId,'bio',$bio,$bio,$type,'Wikipédia · '.$lang,$url,93);$found++;}
            $category=p50_de_infer_category($extract);if($category!==''){p50_de_add_fact_evidence($profileId,'category',$category,$category,$type,'Wikipédia · '.$lang,$url,92);$found++;}
            $image=(string)($page['original']['source']??'');if(filter_var($image,FILTER_VALIDATE_URL)){p50_de_add_fact_evidence($profileId,'photo_url',$image,$image,$type,'Wikipédia · '.$lang,$url,90);$found++;}
        }
    }
    return ['found'=>$found,'pagesChecked'=>min(2,count($pages)),'message'=>'Recherche Wikipédia terminée'];
}

function p50_de_biography_urls(string $name,int $limit=5): array {
    $queries=['"'.$name.'" "né le"','"'.$name.'" "née le"','"'.$name.'" biographie','"'.$name.'" "date de naissance"','"'.$name.'" anniversaire'];
    $urls=[];
    if(function_exists('simplexml_load_string')){
        // Bing RSS apporte des biographies et pages de référence, pas seulement l'actualité récente.
        foreach($queries as $query){
            $rss='https://www.bing.com/search?format=rss&q='.rawurlencode($query);
            $r=p50_http_fetch($rss,10,'application/rss+xml,application/xml,text/xml;q=0.9,*/*;q=0.5');
            if(!$r['ok'])continue;
            libxml_use_internal_errors(true);$xml=simplexml_load_string($r['body'],'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA);
            if(!$xml||!isset($xml->channel->item))continue;
            foreach($xml->channel->item as $item){
                $u=trim((string)$item->link);if(filter_var($u,FILTER_VALIDATE_URL))$urls[]=$u;
                if(count(array_unique($urls))>=$limit)break 2;
            }
        }
        // Google News complète avec les médias ivoiriens indexés récemment.
        if(count(array_unique($urls))<$limit){
            foreach($queries as $query){
                $rss='https://news.google.com/rss/search?q='.rawurlencode($query).'&hl=fr&gl=CI&ceid=CI:fr';
                $r=p50_http_fetch($rss,10,'application/rss+xml,application/xml,text/xml;q=0.9,*/*;q=0.5');
                if(!$r['ok'])continue;
                libxml_use_internal_errors(true);$xml=simplexml_load_string($r['body'],'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA);
                if(!$xml||!isset($xml->channel->item))continue;
                foreach($xml->channel->item as $item){
                    $u=trim((string)$item->link);$description=(string)($item->description??'');
                    if(preg_match_all('/href=["\'](https?:\/\/[^"\']+)["\']/i',$description,$links)){
                        foreach((array)($links[1]??[]) as $candidate){
                            $candidate=html_entity_decode((string)$candidate,ENT_QUOTES|ENT_HTML5,'UTF-8');
                            $host=strtolower((string)(parse_url($candidate,PHP_URL_HOST)?:''));
                            if($host!==''&&!str_contains($host,'google.')){$u=$candidate;break;}
                        }
                    }
                    if(filter_var($u,FILTER_VALIDATE_URL))$urls[]=$u;
                    if(count(array_unique($urls))>=$limit)break 2;
                }
            }
        }
    }
    return array_slice(array_values(array_unique($urls)),0,$limit);
}


function p50_de_collect_biography_web(array $profile,int $limit=3): array {
    $name=(string)$profile['public_name'];$found=0;$checked=0;$details=[];
    foreach(p50_de_biography_urls($name,$limit) as $url){
        $host=strtolower((string)(parse_url($url,PHP_URL_HOST)?:'web'));$host=preg_replace('/^www\./','',$host)?:$host;
        $type='media_bio_'.substr(hash('sha256',$host),0,16);
        $result=p50_de_collect_page_enrichment($profile,$url,$type,$host,86,false);
        $checked++;$found+=(int)($result['found']??0);$details[]=$result;
    }
    return ['found'=>$found,'checked'=>$checked,'details'=>$details,'message'=>'Recherche biographique terminée'];
}

function p50_de_collect_wikidata(array $profile): array {
    $name=(string)$profile['public_name'];$handle=(string)$profile['handle'];$profileId=(string)$profile['profile_id'];
    $candidates=[];
    foreach(['fr','en'] as $language){
        foreach(array_unique(array_filter([$name,ltrim($handle,'@')])) as $query){
            $searchUrl='https://www.wikidata.org/w/api.php?'.http_build_query(['action'=>'wbsearchentities','search'=>$query,'language'=>$language,'uselang'=>'fr','format'=>'json','limit'=>8]);
            $search=p50_json_get($searchUrl,12);
            foreach((array)($search['search']??[]) as $candidate){
                $qid=(string)($candidate['id']??'');if($qid==='')continue;
                $hay=(string)($candidate['label']??'').' '.(string)($candidate['description']??'').' '.implode(' ',(array)($candidate['aliases']??[]));
                $score=p50_name_score($hay,$name,$handle);
                if(str_contains(p50_normalize_text($hay),'ivoir'))$score=min(100,$score+8);
                if(!isset($candidates[$qid])||$score>$candidates[$qid]['score'])$candidates[$qid]=['row'=>$candidate,'score'=>$score];
            }
        }
    }
    uasort($candidates,static fn($a,$b)=>$b['score']<=>$a['score']);
    $bestEntry=$candidates?reset($candidates):null;
    if(!$bestEntry||(int)$bestEntry['score']<52||empty($bestEntry['row']['id']))return ['found'=>0,'verified'=>0,'message'=>'Aucune entité Wikidata suffisamment proche'];
    $best=$bestEntry['row'];$bestScore=(int)$bestEntry['score'];$qid=(string)$best['id'];
    $entityUrl='https://www.wikidata.org/wiki/Special:EntityData/'.rawurlencode($qid).'.json';
    $entityData=p50_json_get($entityUrl,12);$entity=$entityData['entities'][$qid]??null;
    if(!is_array($entity))return ['found'=>0,'verified'=>0,'message'=>'Entité Wikidata inaccessible'];
    $bestHay=(string)($best['label']??'').' '.(string)($best['description']??'').' '.implode(' ',(array)($best['aliases']??[]));
    $trustedIdentity=$bestScore>=75||($bestScore>=65&&str_contains(p50_normalize_text($bestHay),'ivoir'));
    $found=0;$sourceType=$trustedIdentity?'wikidata_high_match':'wikidata';$weight=$trustedIdentity?98:94;
    $birth=p50_de_wikidata_date($entity);
    if($birth){p50_de_add_fact_evidence($profileId,'birth_date',$birth,$birth,$sourceType,'Wikidata · '.$qid,$entityUrl,$weight);$found++;}
    $description=(string)($entity['descriptions']['fr']['value']??$entity['descriptions']['en']['value']??$best['description']??'');
    if($description!==''){p50_de_add_fact_evidence($profileId,'bio',p50_de_text_excerpt($description,500),$description,$sourceType,'Wikidata · '.$qid,$entityUrl,$weight);$found++;}
    $occupationIds=[];foreach(p50_de_wikidata_claims($entity,'P106') as $v)if(is_array($v)&&!empty($v['id']))$occupationIds[]=(string)$v['id'];
    $occupationLabels=p50_de_wikidata_labels($occupationIds);$occupations=implode(', ',array_values($occupationLabels));
    if($occupations!==''){p50_de_add_fact_evidence($profileId,'occupation',$occupations,$occupations,$sourceType,'Wikidata · '.$qid,$entityUrl,$weight);$found++;$cat=p50_de_infer_category($occupations.' '.$description);if($cat!==''){p50_de_add_fact_evidence($profileId,'category',$cat,$cat,$sourceType,'Wikidata · '.$qid,$entityUrl,$weight);$found++;}}
    $nationIds=[];foreach(p50_de_wikidata_claims($entity,'P27') as $v)if(is_array($v)&&!empty($v['id']))$nationIds[]=(string)$v['id'];
    $nationLabels=p50_de_wikidata_labels($nationIds);$nationality=implode(', ',array_values($nationLabels));
    if($nationality!==''){p50_de_add_fact_evidence($profileId,'nationality',$nationality,$nationality,$sourceType,'Wikidata · '.$qid,$entityUrl,$weight);$found++;}
    foreach(p50_de_wikidata_claims($entity,'P18') as $file){if(is_string($file)){ $image=p50_de_wikimedia_file_url($file);if($image!==''){p50_de_add_fact_evidence($profileId,'photo_url',$image,$image,$sourceType,'Wikidata · '.$qid,$entityUrl,94);$found++;break;}}}
    $properties=[
        'P2003'=>['Instagram',static fn($v)=>'https://instagram.com/'.ltrim((string)$v,'@')],
        'P7085'=>['TikTok',static fn($v)=>'https://tiktok.com/@'.ltrim((string)$v,'@')],
        'P2013'=>['Facebook',static fn($v)=>filter_var((string)$v,FILTER_VALIDATE_URL)?(string)$v:'https://facebook.com/'.ltrim((string)$v,'@')],
        'P2397'=>['YouTube',static fn($v)=>'https://youtube.com/channel/'.(string)$v],
        'P2002'=>['X',static fn($v)=>'https://x.com/'.ltrim((string)$v,'@')],
        'P2984'=>['Snapchat',static fn($v)=>'https://snapchat.com/add/'.ltrim((string)$v,'@')],
        'P856'=>['Web',static fn($v)=>(string)$v],
    ];
    $officialSites=[];
    foreach($properties as $property=>[$platform,$builder]){
        foreach(p50_de_wikidata_claims($entity,$property) as $value){
            if(is_array($value))continue;$url=$builder($value);
            $validation=p50_de_validate_social_url($platform,$url,$name,$handle);
            if(!$validation['ok']&&$platform!=='Web')continue;
            $normalized=$validation['normalizedUrl']?:$url;
            p50_de_add_social_evidence($profileId,$platform,$normalized,$sourceType,'Wikidata · '.$qid,$entityUrl,$trustedIdentity?94:90,$validation);$found++;
            if($platform==='Web'&&filter_var($normalized,FILTER_VALIDATE_URL))$officialSites[]=$normalized;
        }
    }
    foreach(array_slice(array_values(array_unique($officialSites)),0,1) as $site){$page=p50_de_collect_page_enrichment($profile,$site,'official_site_wikidata','Site officiel',97,true);$found+=(int)($page['found']??0);}
    $wikiFound=0;
    foreach(['frwiki','enwiki'] as $site){
        if(empty($entity['sitelinks'][$site]['title']))continue;
        $lang=$site==='frwiki'?'fr':'en';$wikiTitle=(string)$entity['sitelinks'][$site]['title'];
        $wikiApi='https://'.$lang.'.wikipedia.org/w/api.php?'.http_build_query(['action'=>'query','prop'=>'extracts|info|pageimages','inprop'=>'url','exintro'=>1,'explaintext'=>1,'redirects'=>1,'piprop'=>'original','titles'=>$wikiTitle,'format'=>'json']);
        $wiki=p50_json_get($wikiApi,12);
        foreach((array)($wiki['query']['pages']??[]) as $page){
            $extract=(string)($page['extract']??'');$pageUrl=(string)($page['fullurl']??('https://'.$lang.'.wikipedia.org/wiki/'.rawurlencode(str_replace(' ','_',$wikiTitle))));$type='wikipedia_exact_'.$lang;
            foreach(p50_de_birth_context_dates($extract) as $date){if($birth!==null&&$date!==$birth)continue;p50_de_add_fact_evidence($profileId,'birth_date',$date,$date,$type,'Wikipédia · '.$lang,$pageUrl,93);$found++;$wikiFound++;}
            $bio=p50_de_text_excerpt($extract,500);if($bio!==''){p50_de_add_fact_evidence($profileId,'bio',$bio,$bio,$type,'Wikipédia · '.$lang,$pageUrl,93);$found++;}
            $cat=p50_de_infer_category($extract);if($cat!==''){p50_de_add_fact_evidence($profileId,'category',$cat,$cat,$type,'Wikipédia · '.$lang,$pageUrl,92);$found++;}
            $image=(string)($page['original']['source']??'');if(filter_var($image,FILTER_VALIDATE_URL)){p50_de_add_fact_evidence($profileId,'photo_url',$image,$image,$type,'Wikipédia · '.$lang,$pageUrl,90);$found++;}
        }
        break;
    }
    if($wikiFound===0){$fallback=p50_de_collect_wikipedia_search($profile);$found+=(int)($fallback['found']??0);}
    $verified=p50_de_profile_verified_count($profileId);
    return ['found'=>$found,'verified'=>$verified,'qid'=>$qid,'identityScore'=>$bestScore,'message'=>'Collecte structurée terminée'];
}


/** Recherche RSS générique, limitée aux résultats publics. */
function p50_de_search_rss_items(array $queries,int $limit=10): array {
    if(!function_exists('simplexml_load_string'))return [];
    $items=[];
    foreach($queries as $query){
        foreach([
            'https://www.bing.com/search?format=rss&q='.rawurlencode((string)$query),
            'https://news.google.com/rss/search?q='.rawurlencode((string)$query).'&hl=fr&gl=CI&ceid=CI:fr'
        ] as $rss){
            $r=p50_http_fetch($rss,10,'application/rss+xml,application/xml,text/xml;q=0.9,*/*;q=0.5');
            if(!$r['ok']||$r['body']==='')continue;
            libxml_use_internal_errors(true);$xml=simplexml_load_string($r['body'],'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA);
            if(!$xml||!isset($xml->channel->item))continue;
            foreach($xml->channel->item as $item){
                $url=trim((string)$item->link);$title=trim((string)$item->title);$description=p50_de_text_excerpt((string)($item->description??''),1200);
                if(preg_match_all('/href=["\'](https?:\/\/[^"\']+)["\']/i',(string)($item->description??''),$links)){
                    foreach((array)($links[1]??[]) as $candidate){
                        $candidate=html_entity_decode((string)$candidate,ENT_QUOTES|ENT_HTML5,'UTF-8');
                        $host=strtolower((string)(parse_url($candidate,PHP_URL_HOST)?:''));
                        if($host!==''&&!str_contains($host,'google.')){$url=$candidate;break;}
                    }
                }
                if(!filter_var($url,FILTER_VALIDATE_URL)||!p50_public_http_url($url))continue;
                $key=hash('sha256',strtolower(rtrim($url,'/')));$items[$key]=['url'=>$url,'title'=>$title,'description'=>$description,'query'=>$query];
                if(count($items)>=$limit)break 3;
            }
        }
    }
    return array_values($items);
}

function p50_de_academic_host_weight(string $url): array {
    $host=strtolower((string)(parse_url($url,PHP_URL_HOST)?:''));$path=strtolower((string)(parse_url($url,PHP_URL_PATH)?:''));
    $host=preg_replace('/^www\./','',$host)?:$host;$pdf=str_ends_with($path,'.pdf');
    // Haute confiance uniquement pour des domaines institutionnels identifiables.
    $official=(bool)preg_match('/(?:^|\.)(?:gouv|gov|edu|ac)\.[a-z]{2,}(?:\.[a-z]{2,})?$/',$host)
        ||str_ends_with($host,'.edu')||preg_match('/\.edu\.[a-z]{2,}$/',$host)
        ||preg_match('/\.ac\.[a-z]{2,}$/',$host);
    $institutionalName=(bool)preg_match('/(?:^|\.)(?:universite|university|ecole|school|lycee|institut|academy|enseignement|formation)[a-z0-9-]*\./',$host);
    if($official&&$pdf)return ['diploma_public_archive','Archive académique publique',97];
    if($official)return ['academic_official','Établissement / institution publique',96];
    if($institutionalName&&$pdf)return ['education_institution_document','Document d’établissement indexé',90];
    if($institutionalName)return ['education_institution','Établissement à confirmer',88];
    if($pdf)return ['education_public_document','Document public indexé',84];
    return ['education_media','Source parcours scolaire',82];
}

function p50_de_education_excerpt(string $text): string {
    $plain=p50_de_text_excerpt($text,12000);if($plain==='')return '';
    $sentences=preg_split('/(?<=[.!?])\s+/u',$plain)?:[];$selected=[];
    foreach($sentences as $sentence){
        $n=p50_normalize_text($sentence);
        if(preg_match('/\b(ecole|lycee|universite|university|diplome|diplomee|diplome|licence|master|baccalaureat|bac|formation|etudes|alumni|promotion|soutenance|marine marchande)\b/',$n))$selected[]=trim($sentence);
        if(count($selected)>=3)break;
    }
    return p50_de_text_excerpt(implode(' ',$selected),700);
}

/**
 * Explore uniquement des archives et pages publiques : écoles, universités,
 * listes d'anciens élèves, diplômes publiés, CV publics et institutions.
 * Aucune date n'est déduite d'un âge scolaire : elle doit être explicitement écrite.
 */
function p50_de_collect_academic_public_records(array $profile,int $limit=7): array {
    $name=(string)$profile['public_name'];$handle=(string)$profile['handle'];$profileId=(string)$profile['profile_id'];
    $aliases=[];$state=p50_de_load_public_state();$map=p50_de_profile_state_map($state);$sp=$map[$profileId]??[];
    foreach(preg_split('/[\/,;|·]+/u',(string)($sp['knownAlias']??''))?:[] as $alias)if(trim($alias)!=='')$aliases[]=trim($alias);
    $realName=trim((string)($sp['realName']??($sp['curatedFacts']['real_name']['value']??'')));if($realName!=='')array_unshift($aliases,$realName);
    $identities=[];
    foreach(array_values(array_unique(array_filter([...$aliases,$name]))) as $identity){
        $tokens=preg_split('/\s+/u',trim($identity))?:[];
        // Un simple prénom ou pseudonyme court produit trop d’homonymes dans les archives scolaires.
        $identityLength=function_exists('mb_strlen')?mb_strlen($identity,'UTF-8'):strlen($identity);
        if(count(array_filter($tokens))<2&&$identityLength<10)continue;
        $identities[]=$identity;
    }
    if(!$identities)return ['found'=>0,'checked'=>0,'details'=>[],'message'=>'Identité civile trop ambiguë pour les archives académiques'];
    $queries=[];
    foreach($identities as $identity){
        $queries[]='"'.$identity.'" (école OR ecole OR lycée OR lycee OR université OR universite OR diplôme OR diplome OR alumni OR promotion OR soutenance)';
        $queries[]='"'.$identity.'" ("date de naissance" OR "né le" OR "née le") (école OR université OR diplôme OR CV)';
        $queries[]='filetype:pdf "'.$identity.'" (diplôme OR diplome OR CV OR "date de naissance")';
        $queries[]='site:edu.ci "'.$identity.'" OR site:ac.ci "'.$identity.'" OR site:gouv.ci "'.$identity.'"';
    }
    $found=0;$checked=0;$details=[];
    foreach(p50_de_search_rss_items($queries,$limit) as $item){
        $hay=$item['title'].' '.$item['description'].' '.$item['url'];$identity=p50_name_score($hay,$name,$handle);
        if($identity<62)continue;
        [$sourceType,$sourceName,$weight]=p50_de_academic_host_weight($item['url']);
        $education=p50_de_education_excerpt($item['description']);
        if($education!==''){p50_de_add_fact_evidence($profileId,'education',$education,$education,$sourceType,$sourceName,$item['url'],$weight);$found++;}
        // Snippet : preuve faible sauf institution publique, et uniquement si la date est explicite.
        foreach(p50_de_birth_context_dates($item['title'].' '.$item['description']) as $date){
            $birthWeight=str_starts_with($sourceType,'academic_official')||str_starts_with($sourceType,'diploma_public_archive')?96:78;
            p50_de_add_fact_evidence($profileId,'birth_date',$date,$date,$sourceType.'_snippet',$sourceName.' · extrait indexé',$item['url'],$birthWeight);$found++;
        }
        $path=strtolower((string)(parse_url($item['url'],PHP_URL_PATH)?:''));
        if(!str_ends_with($path,'.pdf')){
            $page=p50_de_collect_page_enrichment($profile,$item['url'],$sourceType,$sourceName,$weight,false);
            $found+=(int)($page['found']??0);$details[]=$page;
            if(($page['url']??'')!==''){
                $r=p50_http_fetch((string)$page['url'],10,'text/html,*/*;q=0.6');
                if($r['body']!==''){$edu=p50_de_education_excerpt($r['body']);if($edu!==''){p50_de_add_fact_evidence($profileId,'education',$edu,$edu,$sourceType,$sourceName,(string)$page['url'],$weight);$found++;}}
            }
        }
        $checked++;
    }
    return ['found'=>$found,'checked'=>$checked,'details'=>$details,'message'=>'Archives scolaires et universitaires publiques explorées'];
}

/** Extrait les métriques publiques d'un contenu vidéo/post lorsque la plateforme les expose. */
function p50_de_content_metrics(string $url): array {
    $r=p50_http_fetch($url,12,'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5');
    if($r['body']==='')return ['metrics'=>[],'publishedAt'=>null,'title'=>'','thumbnail'=>''];
    $html=$r['body'];$meta=p50_page_metadata($html,$r['finalUrl']?:$url);$metrics=[];$published=null;
    $patterns=[
        'views'=>['/"viewCount"\s*:\s*"?(\d+)/i','/"playCount"\s*:\s*"?(\d+)/i','/"videoViewCount"\s*:\s*"?(\d+)/i'],
        'likes'=>['/"likeCount"\s*:\s*"?(\d+)/i','/"diggCount"\s*:\s*"?(\d+)/i'],
        'comments'=>['/"commentCount"\s*:\s*"?(\d+)/i'],
        'shares'=>['/"shareCount"\s*:\s*"?(\d+)/i'],
    ];
    foreach($patterns as $key=>$list)foreach($list as $pattern)if(preg_match($pattern,$html,$m)){$metrics[$key]=(int)$m[1];break;}
    foreach(['/"datePublished"\s*:\s*"([^"]+)"/i','/"uploadDate"\s*:\s*"([^"]+)"/i','/<meta[^>]+property=["\']article:published_time["\'][^>]+content=["\']([^"\']+)/i'] as $pattern){
        if(preg_match($pattern,$html,$m)){try{$published=(new DateTimeImmutable($m[1]))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}catch(Throwable){}break;}
    }
    return ['metrics'=>$metrics,'publishedAt'=>$published,'title'=>(string)($meta['title']??''),'thumbnail'=>(string)($meta['image']??'')];
}

function p50_de_metric_number(mixed $value): int {
    if(is_int($value)||is_float($value))return max(0,(int)$value);
    $raw=strtoupper(str_replace(["\u{00A0}",' '],'',trim((string)$value)));if($raw==='')return 0;
    if(preg_match('/([0-9]+(?:[.,][0-9]+)?)([KMB])?/',$raw,$m)){
        $n=(float)str_replace(',','.',$m[1]);$factor=match($m[2]??''){ 'K'=>1000,'M'=>1000000,'B'=>1000000000,default=>1};return (int)round($n*$factor);
    }
    return 0;
}

/** Convertit les actualités validées dans l'administration en activités mesurables. */
function p50_de_state_event_datetime(array $event): string {
    foreach(['publishedAt','published_at','detectedAt','createdAt','updatedAt'] as $key){
        $value=trim((string)($event[$key]??''));
        if($value==='')continue;
        $ts=strtotime($value);if($ts!==false)return gmdate('Y-m-d H:i:s',$ts);
    }
    $label=p50_normalize_text((string)($event['publishedLabel']??''));
    $now=time();
    if(preg_match('/il y a ([0-9]+) min/',$label,$m))return gmdate('Y-m-d H:i:s',$now-(int)$m[1]*60);
    if(preg_match('/il y a ([0-9]+) h/',$label,$m))return gmdate('Y-m-d H:i:s',$now-(int)$m[1]*3600);
    if(str_contains($label,"aujourd")||str_contains($label,'recent')||str_contains($label,'actuel'))return gmdate('Y-m-d H:i:s',$now-2*3600);
    return gmdate('Y-m-d H:i:s',$now-6*3600);
}

function p50_de_state_metric_values(array $event): array {
    $metrics=is_array($event['metrics']??null)?$event['metrics']:[];
    $label=trim((string)($event['metric']??''));
    $number=p50_de_metric_number($label);
    $normalized=p50_normalize_text($label);
    if($number>0){
        if(str_contains($normalized,'vue'))$metrics['views']=$number;
        elseif(str_contains($normalized,'partage')||str_contains($normalized,'relais'))$metrics['shares']=$number;
        elseif(str_contains($normalized,'comment'))$metrics['comments']=$number;
        else $metrics['signalVolume']=$number;
    }
    $metrics['platformCount']=count(array_values(array_filter((array)($event['platforms']??[]))));
    $metrics['manualValidated']=true;
    $metrics['signalLabel']=$label;
    return $metrics;
}

function p50_de_import_state_activities(string $profileId): int {
    $state=p50_de_load_public_state();$count=0;
    foreach((array)($state['events']??[]) as $event){
        if(!is_array($event)||(string)($event['profileId']??'')!==$profileId)continue;
        if(($event['originalLinkValidated']??false)!==true)continue;
        $url=trim((string)($event['resolvedUrl']??$event['canonicalUrl']??$event['submittedUrl']??$event['url']??''));
        if(!filter_var($url,FILTER_VALIDATE_URL))continue;
        $platforms=array_values(array_filter(array_map('strval',(array)($event['platforms']??[]))));
        $platform=$platforms[0]??p50_platform($url);if($platform==='')$platform='Web';
        $type=trim((string)($event['type']??'Actualité'))?:'Actualité';
        $title=trim((string)($event['title']??'Actualité validée'))?:'Actualité validée';
        $published=p50_de_state_event_datetime($event);
        $metrics=p50_de_state_metric_values($event);
        $confidenceLabel=p50_normalize_text((string)($event['confidence']??''));
        $confidence=str_contains($confidenceLabel,'eleve')?98:(str_contains($confidenceLabel,'moyen')?94:92);
        p50_de_add_activity($profileId,$platform,$type,$title,$url,$published,$metrics,$confidence);
        $count++;
    }
    return $count;
}

/** Algorithme PASS50 15 critères — données publiques réellement disponibles. */
function p50_de_15c_window(string $profileId,int $hours): array {
    $stmt=db()->prepare("SELECT id,platform,event_type,published_at,collected_at,metrics,confidence FROM p50_activity_events WHERE profile_id=? AND status='verified' AND confidence>=? AND COALESCE(published_at,collected_at)>=DATE_SUB(NOW(),INTERVAL ? HOUR) ORDER BY COALESCE(published_at,collected_at) ASC");
    $stmt->execute([$profileId,p50_de_threshold(),$hours]);$events=$stmt->fetchAll();$links=p50_de_social_links($profileId,true);
    $views=$likes=$comments=$shares=$saves=0;$latest=0;$platforms=[];$conf=[];$velocities=[];$now=time();
    foreach($events as $e){
        $m=decode_json_column($e['metrics']??null,[]);
        $v=p50_de_metric_number($m['views']??($m['signalVolume']??0));
        $l=p50_de_metric_number($m['likes']??0);$c=p50_de_metric_number($m['comments']??0);
        $sh=p50_de_metric_number($m['shares']??($m['reposts']??0));$sv=p50_de_metric_number($m['saves']??0);
        $views+=$v;$likes+=$l;$comments+=$c;$shares+=$sh;$saves+=$sv;
        $ts=strtotime((string)($e['published_at']?:$e['collected_at']))?:0;$latest=max($latest,$ts);
        $age=max(1,($now-$ts)/3600);if($v>0)$velocities[]=$v/$age;
        $platforms[(string)$e['platform']]=true;
        $extraPlatforms=max(0,(int)($m['platformCount']??0)-1);
        for($i=0;$i<$extraPlatforms;$i++)$platforms['signal_'.$e['id'].'_'.$i]=true;
        $conf[]=(int)$e['confidence'];
    }
    foreach($links as $l)$conf[]=(int)$l['confidence'];
    $followers=0; // indisponible publiquement de façon homogène : critère omis si absent.
    $engagement=$views>0?($likes+3*$comments+5*$shares+4*$saves)/$views:null;
    $shareRate=$views>0?($shares+$saves)/$views:null;
    $velocity=$velocities?array_sum($velocities)/count($velocities):null;
    $freshHours=$latest?max(0,($now-$latest)/3600):null;
    $raw=[
      'c1'=>$followers>0?log10(1+$followers):null,
      'c2'=>$views>0?log10(1+$views):null,
      'c3'=>null,
      'c4'=>$engagement,
      'c5'=>$shareRate,
      'c6'=>$comments>0?log10(1+$comments):null,
      'c7'=>$velocity!==null?log10(1+$velocity):null,
      'c8'=>count($platforms)>0?(float)count($platforms):null,
      'c9'=>$shares>0?log10(1+$shares):null,
      'c10'=>null,'c11'=>null,'c12'=>null,
      'c13'=>count($events)>0?(float)count($events):null,
      'c14'=>($views>0&&($likes+$comments+$shares)>0)?max(0,min(100,70+min(25,log10(1+$views)*3)-min(20,abs(($likes+$comments+$shares)/max(1,$views)-0.08)*100))):null,
      'c15'=>$shares>0?log10(1+$shares):null,
    ];
    $weights=['c1'=>.06,'c2'=>.08,'c3'=>.07,'c4'=>.08,'c5'=>.09,'c6'=>.05,'c7'=>.10,'c8'=>.08,'c9'=>.06,'c10'=>.06,'c11'=>.05,'c12'=>.04,'c13'=>.04,'c14'=>.07,'c15'=>.07];
    $scores=[];$sum=0.0;$available=0.0;
    foreach($raw as $k=>$v){if($v===null||!is_finite((float)$v))continue;$x=(float)$v;$score=match($k){'c2','c6','c7','c9','c15'=>max(0,min(100,20+$x*16)),'c4'=>max(0,min(100,$x*500)),'c5'=>max(0,min(100,$x*1000)),'c8'=>max(0,min(100,$x*20)),'c13'=>max(0,min(100,$x*8)),'c14'=>max(0,min(100,$x)),default=>max(0,min(100,50+$x*10))};$scores[$k]=round($score,2);$sum+=$score*$weights[$k];$available+=$weights[$k];}
    $base=$available>0?$sum/$available:0;$coverage=$available*100;$confidenceSource=$conf?(array_sum($conf)/count($conf)):0;$fresh=$freshHours===null?0:max(0,100-min(100,$freshHours*2));$confidence=.5*($coverage/100)+.3*($fresh/100)+.2*($confidenceSource/100);$score=max(0,min(100,$base*(.75+.25*$confidence)));
    arsort($scores);$top=array_slice(array_keys($scores),0,3);
    return ['score'=>(int)round($score),'baseScore'=>round($base,2),'confidence'=>(int)round($confidence*100),'coverage'=>round($coverage,2),'criteria'=>$scores,'raw'=>$raw,'measuredCriteria'=>count($scores),'topCriteria'=>$top,'events'=>count($events),'platforms'=>array_keys($platforms),'latestAt'=>$latest?gmdate('c',$latest):null,'freshHours'=>$freshHours];
}
function p50_de_compute_trend_score(string $profileId): array {
    $w=p50_de_15c_window($profileId,24);$w['classable']=$w['confidence']>=65&&$w['coverage']>=60&&$w['measuredCriteria']>=6;$w['events30']=$w['events'];$w['events7']=$w['events'];$w['sumViews']=0;$w['maxViews']=0;return $w;
}
function p50_de_period_scores(int|string $profile): array {
    if(is_int($profile))return ['2H'=>$profile,'24H'=>$profile,'48H'=>$profile,'7J'=>$profile,'15J'=>$profile];
    return ['2H'=>p50_de_15c_window($profile,2)['score'],'24H'=>p50_de_15c_window($profile,24)['score'],'48H'=>p50_de_15c_window($profile,48)['score'],'7J'=>p50_de_15c_window($profile,168)['score'],'15J'=>(int)round(p50_de_15c_window($profile,360)['score']*.55)];
}

/** Collecteur principal V22 : sources structurées, archives publiques et activité sociale. */
function p50_de_collect_enrichment(array $profile,bool $deep=true): array {
    $profileId=(string)$profile['profile_id'];$found=0;$details=[];
    $structured=p50_de_collect_wikidata($profile);$found+=(int)($structured['found']??0);$details['structured']=$structured;
    $facts=p50_de_verified_facts($profileId);
    if($deep){$academic=p50_de_collect_academic_public_records($profile,p50_de_is_priority_profile($profileId)?9:5);$found+=(int)($academic['found']??0);$details['academic']=$academic;}
    $facts=p50_de_verified_facts($profileId);
    if(!isset($facts['birth_date'])&&$deep){$bio=p50_de_collect_biography_web($profile,p50_de_is_priority_profile($profileId)?6:3);$found+=(int)($bio['found']??0);$details['biography']=$bio;}
    // Les sites Web déjà validés sont analysés même si Wikidata ne connaît pas le profil.
    foreach(p50_de_social_links($profileId,true) as $link){
        if(strcasecmp((string)$link['platform'],'Web')!==0)continue;
        $official=p50_de_collect_page_enrichment($profile,(string)$link['url'],'official_site_verified','Site officiel vérifié',98,true);
        $found+=(int)($official['found']??0);$details['officialSite']=$official;break;
    }
    return ['found'=>$found,'verified'=>p50_de_profile_verified_count($profileId),'details'=>$details,'message'=>'Enrichissement automatique terminé'];
}


function p50_de_collect_state_facts(array $profile): int {
    $state=p50_de_load_public_state();
    $map=p50_de_profile_state_map($state);
    $p=$map[(string)$profile['profile_id']]??null;
    if(!is_array($p))return 0;
    $count=0;$profileId=(string)$profile['profile_id'];
    $date=trim((string)($p['birthDate']??''));
    if($date!==''&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){
        $manual=!empty($p['birthManualLocked']);
        p50_de_add_fact_evidence($profileId,'birth_date',$date,$date,$manual?'manual_owner':'state_import',$manual?'Administrateur PASS50':'État PASS50','',$manual?100:70);$count++;
    }
    $allowed=['real_name','education','occupation','nationality','bio','category','official_website','birth_date'];
    foreach((array)($p['curatedFacts']??[]) as $key=>$seed){
        if(!in_array((string)$key,$allowed,true)||!is_array($seed))continue;
        $value=trim((string)($seed['value']??''));if($value==='')continue;
        if($key==='birth_date'){$value=p50_de_normalize_iso_date($value);if($value==='')continue;}
        $weight=max(90,min(96,(int)($seed['confidence']??94)));
        p50_de_add_fact_evidence($profileId,(string)$key,$value,$value,'curated_research_v22',(string)($seed['source_name']??'Recherche PASS50 V22'),(string)($seed['source_url']??''),$weight);$count++;
    }
    return $count;
}

function p50_de_collect_state_links(array $profile): int {
    $state=p50_de_load_public_state();
    $map=p50_de_profile_state_map($state);
    $p=$map[(string)$profile['profile_id']]??null;
    if(!is_array($p))return 0;
    $count=0;
    $curated=is_array($p['curatedSocialSources']??null)?$p['curatedSocialSources']:[];
    foreach((array)($p['links']??[]) as $platform=>$url){
        if(!is_string($url)||trim($url)==='')continue;
        $validation=p50_de_validate_social_url((string)$platform,$url,(string)$profile['public_name'],(string)$profile['handle']);
        if($validation['normalizedUrl']==='')continue;
        $seed=is_array($curated[$platform]??null)?$curated[$platform]:null;
        $type=$seed?'curated_research_v22':'state_import';$name=$seed?(string)($seed['source_name']??'Recherche PASS50 V22'):'État PASS50';$sourceUrl=$seed?(string)($seed['source_url']??''):'';$weight=$seed?max(90,min(96,(int)($seed['confidence']??94))):70;
        p50_de_add_social_evidence((string)$profile['profile_id'],(string)$platform,$validation['normalizedUrl'],$type,$name,$sourceUrl,$weight,$validation);
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

function p50_de_rebuild_profile_facts(string $profileId): int {
    $stmt=db()->prepare('SELECT DISTINCT fact_key FROM p50_fact_evidence WHERE profile_id=?');
    $stmt->execute([$profileId]);$count=0;
    foreach($stmt->fetchAll() as $row){$key=trim((string)($row['fact_key']??''));if($key==='')continue;p50_de_rebuild_fact($profileId,$key);$count++;}
    return $count;
}

function p50_de_verified_facts(string $profileId): array {
    $stmt=db()->prepare("SELECT fact_key,normalized_value,confidence,evidence_count,source_types,verified_at FROM p50_facts WHERE profile_id=? AND status='verified' AND confidence>=? ORDER BY confidence DESC");
    $stmt->execute([$profileId,p50_de_threshold()]);
    $out=[];
    foreach($stmt->fetchAll() as $row){if(!isset($out[$row['fact_key']]))$out[$row['fact_key']]=$row;}
    return $out;
}

function p50_de_best_fact(string $profileId,string $factKey,int $minConfidence=0): ?array {
    $stmt=db()->prepare("SELECT fact_key,normalized_value,confidence,evidence_count,source_types,status,verified_at,last_seen_at FROM p50_facts WHERE profile_id=? AND fact_key=? AND confidence>=? ORDER BY (status='verified') DESC,confidence DESC,evidence_count DESC,last_seen_at DESC LIMIT 1");
    $stmt->execute([$profileId,$factKey,max(0,min(100,$minConfidence))]);
    $row=$stmt->fetch();
    return $row?:null;
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
    $registry=p50_de_registry_profiles($profileId,1,0,false);
    if($registry){p50_de_collect_state_facts($registry[0]);p50_de_collect_curated_evidence_v221($registry[0]);}
    p50_de_import_state_activities($profileId);
    p50_de_rebuild_profile_facts($profileId);
    $facts=p50_de_verified_facts($profileId);
    $photoCandidate=p50_de_best_fact($profileId,'photo_url',60);
    $links=p50_de_social_links($profileId,true);
    $changed=false;
    foreach((array)($state['profiles']??[]) as &$p){
        if(!is_array($p)||(string)($p['id']??'')!==$profileId)continue;
        $previousEngine=is_array($p['dataEngine']??null)?$p['dataEngine']:[];
        $publicLinks=[];$maxSocial=0;
        foreach($links as $link){$publicLinks[(string)$link['platform']]=(string)$link['url'];$maxSocial=max($maxSocial,(int)$link['confidence']);}
        // Ne jamais écraser des liens directs déjà saisis dans l'état public par une
        // réponse serveur partielle. Les liens vérifiés du moteur prennent la priorité,
        // mais les autres saisies directes sont conservées jusqu'à suppression explicite.
        $preservedLinks=[];
        foreach((array)($p['links']??[]) as $platform=>$url){
            $normalized=p50_de_normalize_social_url((string)$platform,(string)$url);
            if($normalized!=='')$preservedLinks[(string)$platform]=$normalized;
        }
        $mergedLinks=array_merge($preservedLinks,$publicLinks);
        if(($p['links']??[])!==$mergedLinks){$p['links']=$mergedLinks;$changed=true;}
        $p['quality']=is_array($p['quality']??null)?$p['quality']:[];
        $p['quality']['identity']=max(90,(int)($p['quality']['identity']??0));
        $p['quality']['social']=$maxSocial;

        if(isset($facts['birth_date'])){
            $date=(string)$facts['birth_date']['normalized_value'];
            if(($p['birthDate']??null)!==$date){$p['birthDate']=$date;$changed=true;}
            $p['birthYear']=(int)substr($date,0,4);
            $p['ageStatus']='confirmed';
            $p['quality']['birth']=(int)$facts['birth_date']['confidence'];
        }else{
            // Ne jamais effacer une information déjà saisie : elle reste simplement non confirmée.
            if(empty($p['birthDate'])){$p['ageStatus']=$p['ageStatus']??'unconfirmed';$p['quality']['birth']=0;}
        }

        $autoBioValue=(string)($previousEngine['autoBioValue']??'');
        if(isset($facts['bio'])){
            $bio=(string)$facts['bio']['normalized_value'];
            $currentBio=trim((string)($p['bio']??''));
            if($currentBio===''||($autoBioValue!==''&&$currentBio===$autoBioValue)){$p['bio']=$bio;$autoBioValue=$bio;$changed=true;}
        }
        if(isset($facts['occupation'])){$p['occupation']=(string)$facts['occupation']['normalized_value'];$changed=true;}
        if(isset($facts['education'])){$p['education']=(string)$facts['education']['normalized_value'];$changed=true;}
        if(isset($facts['real_name'])){$p['realName']=(string)$facts['real_name']['normalized_value'];$changed=true;}
        if(isset($facts['nationality'])){$p['nationality']=(string)$facts['nationality']['normalized_value'];$changed=true;}
        if(isset($facts['official_website'])){$p['officialWebsite']=(string)$facts['official_website']['normalized_value'];$changed=true;}
        $autoCategoryValue=(string)($previousEngine['autoCategoryValue']??'');
        if(isset($facts['category'])){
            $suggested=(string)$facts['category']['normalized_value'];
            $current=trim((string)($p['category']??''));
            $generic=in_array(p50_normalize_text($current),['','autre','a verifier','influenceur','digital','lifestyle'],true);
            if($generic||($autoCategoryValue!==''&&$current===$autoCategoryValue)){$p['category']=$suggested;$autoCategoryValue=$suggested;$changed=true;}
            else $p['suggestedCategory']=$suggested;
        }

        if($photoCandidate&&empty($p['photoManualLocked'])&&in_array((string)($p['photoStatus']??'missing'),['missing','pending'],true)&&empty($p['photoUrl'])){
            $url=(string)$photoCandidate['normalized_value'];
            if(($p['photoCandidateUrl']??'')!==$url){$p['photoCandidateUrl']=$url;$changed=true;}
            $p['photoStatus']='pending';
            $p['photoSource']='Suggestion automatique PASS50';
            $p['photoNote']='Photo proposée par le moteur : validation humaine obligatoire.';
            $p['quality']['photo']=0;
        }

        $trend=p50_de_compute_trend_score($profileId);
        $previousScores=(array)($p['scores']??[]);
        $scoreUpdated=false;
        if($trend['classable']){
            // Tous les profils disposant d'assez de données sont recalculés,
            // y compris ceux qui étaient déjà classés avant l'installation du moteur.
            $periodScores=p50_de_period_scores($profileId);
            foreach($periodScores as $period=>$value){
                if((int)($previousScores[$period]??-1)!==(int)$value)$scoreUpdated=true;
            }
            $p['scores']=array_merge($previousScores,$periodScores);
            $p['score']=$periodScores['2H'];
            $p['eligible']=true;$p['classable']=true;$p['quality']['score']=(int)$trend['confidence'];
            $badges=array_values(array_filter((array)($p['badges']??[]),fn($b)=>!in_array($b,['HOT','UP','VIRAL'],true)));
            if($trend['score']>=88)$badges[]='HOT';if($trend['score']>=82)$badges[]='UP';if(($trend['events7']??0)>=3)$badges[]='VIRAL';
            $p['badges']=array_values(array_unique($badges));
            $changed=true;
        }
        $p['lastCollectedAt']=gmdate('c');
        $p['dataEngine']=[
            'threshold'=>p50_de_threshold(),
            'publishedAt'=>gmdate('c'),
            'verifiedFacts'=>array_keys($facts),
            'verifiedSocialLinks'=>count($links),
            'photoCandidate'=>(bool)$photoCandidate,
            'autoBioValue'=>$autoBioValue,
            'autoCategoryValue'=>$autoCategoryValue,
            'autoScore'=>(bool)$trend['classable'],'scoreUpdated'=>$scoreUpdated,'previousScores'=>$previousScores,
            'trend'=>$trend,'algorithmVersion'=>'15C-v1','dataConfidence'=>(int)($trend['confidence']??0),'measuredCoverage'=>(float)($trend['coverage']??0),'measuredCriteria'=>(int)($trend['measuredCriteria']??0),
            'priorityWave'=>p50_de_is_priority_profile($profileId)?'V22-16':'',
        ];
        $changed=true;
        break;
    }
    unset($p);
    if($changed){$state['dataEngineMeta']=['threshold'=>p50_de_threshold(),'lastPublishedAt'=>gmdate('c'),'version'=>22];p50_de_save_public_state($state,$userId);}
    return $changed;
}

function p50_de_publish_all(?string $userId=null): int {
    $profiles=p50_de_registry_profiles(null,1000,0,false);$count=0;
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
    $found=0;
    // La page officielle YouTube fournit souvent une bio et une photo fiables.
    $channelPage=p50_http_fetch((string)$youtube['url'],12,'text/html,*/*;q=0.7');
    if($channelPage['body']!==''){
        $meta=p50_page_metadata($channelPage['body'],$channelPage['finalUrl']?:$youtube['url']);
        $identity=p50_name_score(($meta['title']??'').' '.($meta['description']??''),(string)$profile['public_name'],(string)$profile['handle']);
        if($identity>=45){
            $description=p50_de_text_excerpt((string)($meta['description']??''),500);
            if($description!==''){p50_de_add_fact_evidence($profileId,'bio',$description,$description,'official_site_youtube','YouTube officiel',(string)$youtube['url'],94);$found++;$category=p50_de_infer_category($description);if($category!==''){p50_de_add_fact_evidence($profileId,'category',$category,$category,'official_site_youtube','YouTube officiel',(string)$youtube['url'],94);$found++;}}
            $image=(string)($meta['image']??'');if(filter_var($image,FILTER_VALIDATE_URL)){p50_de_add_fact_evidence($profileId,'photo_url',$image,$image,'official_site_youtube','YouTube officiel',(string)$youtube['url'],92);$found++;}
        }
    }
    $channelId=p50_de_youtube_channel_id((string)$youtube['url']);
    if(!$channelId)return ['found'=>$found,'verified'=>0,'message'=>'Identifiant de chaîne YouTube introuvable'];
    $feedUrl='https://www.youtube.com/feeds/videos.xml?channel_id='.rawurlencode($channelId);
    $r=p50_http_fetch($feedUrl,15,'application/atom+xml,application/xml,text/xml;q=0.9,*/*;q=0.5');
    if(!$r['ok']||$r['body']==='')return ['found'=>0,'verified'=>0,'message'=>'Flux YouTube indisponible','httpStatus'=>$r['status']];
    if(!function_exists('simplexml_load_string'))return ['found'=>0,'verified'=>0,'message'=>'Extension XML indisponible'];
    libxml_use_internal_errors(true);
    $xml=simplexml_load_string($r['body'],'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA);
    if(!$xml)return ['found'=>0,'verified'=>0,'message'=>'Flux YouTube invalide'];
    $namespaces=$xml->getNamespaces(true);
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


/** Transforme les liens relatifs/échappés rencontrés dans les pages sociales. */
function p50_de_absolute_content_url(string $raw,string $baseUrl): string {
    $raw=html_entity_decode(trim(str_replace(['\\/','\\u002F','\\u0026'],['/','/','&'],$raw)),ENT_QUOTES|ENT_HTML5,'UTF-8');
    if($raw==='')return '';
    if(str_starts_with($raw,'//'))$raw='https:'.$raw;
    if(preg_match('#^https?://#i',$raw))return $raw;
    $parts=parse_url($baseUrl);if(!$parts||empty($parts['host']))return '';
    $origin=(string)($parts['scheme']??'https').'://'.(string)$parts['host'];
    if(str_starts_with($raw,'/'))return $origin.$raw;
    $basePath=(string)($parts['path']??'/');$dir=rtrim(str_replace('\\','/',dirname($basePath)),'/');
    return $origin.($dir!==''&&$dir!=='.'?'/'.ltrim($dir,'/'):'').'/'.ltrim($raw,'/');
}

function p50_de_is_exact_social_content(string $platform,string $url): bool {
    if(!filter_var($url,FILTER_VALIDATE_URL)||p50_platform($url)!==$platform)return false;
    $path=(string)(parse_url($url,PHP_URL_PATH)?:'');$query=(string)(parse_url($url,PHP_URL_QUERY)?:'');
    return match($platform){
        'YouTube'=>(bool)preg_match('#/(?:watch|shorts|live|embed)(?:/|$)#i',$path)||str_contains($query,'v='),
        'TikTok'=>(bool)preg_match('#/@[^/]+/video/\d+#i',$path),
        'Instagram'=>(bool)preg_match('#/(?:reel|reels|p|tv)/[^/]+#i',$path),
        'Facebook'=>(bool)preg_match('#/(?:reel|reels|videos|watch)/#i',$path)||str_contains($query,'v='),
        'X'=>(bool)preg_match('#/status/\d+#i',$path),
        'Snapchat'=>(bool)preg_match('#/(?:spotlight|story)/#i',$path),
        default=>false,
    };
}

/** Extrait seulement des URL de contenus précis, jamais une page d'accueil ou un profil. */
function p50_de_social_content_urls_from_html(string $html,string $baseUrl,string $platform,int $limit=8): array {
    $raw=[];
    preg_match_all('/(?:href|content)=["\']([^"\']+)["\']/i',$html,$matches);
    $raw=array_merge($raw,(array)($matches[1]??[]));
    preg_match_all('#https?:\\?/\\?/[^"\'<>\\s]+#i',$html,$scriptMatches);
    $raw=array_merge($raw,(array)($scriptMatches[0]??[]));
    $out=[];
    foreach($raw as $candidate){
        $url=p50_de_absolute_content_url((string)$candidate,$baseUrl);
        if($url===''||!p50_de_is_exact_social_content($platform,$url))continue;
        $parts=parse_url($url);if(!$parts)continue;
        $clean=(string)($parts['scheme']??'https').'://'.(string)($parts['host']??'').(string)($parts['path']??'');
        if(!empty($parts['query'])&&in_array($platform,['YouTube','Facebook'],true))$clean.='?'.$parts['query'];
        $out[$clean]=$clean;
        if(count($out)>=$limit)break;
    }
    return array_values($out);
}

/**
 * Visite tous les réseaux officiels vérifiés d'une FI.
 * Les plateformes qui bloquent les robots sont signalées, mais ne bloquent pas le cycle.
 * Les contenus précis accessibles sont enregistrés comme activités, les métadonnées servent
 * à proposer bio/photo/catégorie sans écraser les validations humaines.
 */
function p50_de_collect_social_activity(array $profile): array {
    $profileId=(string)$profile['profile_id'];$name=(string)$profile['public_name'];$handle=(string)$profile['handle'];
    $links=p50_de_social_links($profileId,true);$found=0;$visited=0;$blocked=[];$platforms=[];
    foreach($links as $link){
        $platform=(string)$link['platform'];$url=(string)$link['url'];
        if(in_array($platform,['Web','LinkedIn','YouTube'],true))continue;
        $visited++;$r=p50_http_fetch($url,12,'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5');
        if($r['body']===''){ $blocked[]=$platform.' (HTTP '.(int)$r['status'].')';continue; }
        $final=$r['finalUrl']?:$url;$meta=p50_page_metadata($r['body'],$final);$confidence=max(88,min(96,(int)$link['confidence']));
        $identity=p50_name_score(($meta['title']??'').' '.($meta['description']??'').' '.$final,$name,$handle);
        if($identity>=25){
            $description=p50_de_text_excerpt((string)($meta['description']??''),500);
            if($description!==''){p50_de_add_fact_evidence($profileId,'bio',$description,$description,'official_social_'.strtolower($platform),$platform.' officiel',$final,$confidence);$found++;$category=p50_de_infer_category($description);if($category!==''){p50_de_add_fact_evidence($profileId,'category',$category,$category,'official_social_'.strtolower($platform),$platform.' officiel',$final,max(88,$confidence-2));$found++;}}
            $image=(string)($meta['image']??'');if(filter_var($image,FILTER_VALIDATE_URL)){p50_de_add_fact_evidence($profileId,'photo_url',$image,$image,'official_social_'.strtolower($platform),$platform.' officiel',$final,max(88,$confidence-4));$found++;}
        }
        $contentUrls=p50_de_social_content_urls_from_html($r['body'],$final,$platform,6);
        foreach($contentUrls as $contentIndex=>$contentUrl){
            $type=in_array($platform,['TikTok','Instagram','Facebook','Snapchat'],true)?'video':'post';
            $content=$contentIndex<3?p50_de_content_metrics($contentUrl):['metrics'=>[],'publishedAt'=>null,'title'=>'','thumbnail'=>''];
            $title=trim((string)($content['title']??''));if($title===''||p50_name_score($title,$name,$handle)<20)$title=trim((string)($meta['title']??''));if($title===''||p50_name_score($title,$name,$handle)<20)$title='Contenu récent détecté sur '.$platform;
            $metrics=array_merge(['discoveredFrom'=>$final,'automatic'=>true],(array)($content['metrics']??[]));if(!empty($content['thumbnail']))$metrics['thumbnail']=$content['thumbnail'];
            p50_de_add_activity($profileId,$platform,$type,$title,$contentUrl,$content['publishedAt']??null,$metrics,max(90,$confidence-2));
            $found++;
        }
        $platforms[$platform]=['visited'=>true,'contentFound'=>count($contentUrls),'httpStatus'=>(int)$r['status']];
    }
    return ['found'=>$found,'verified'=>0,'visited'=>$visited,'platforms'=>$platforms,'blocked'=>$blocked,'message'=>$visited?'Réseaux officiels visités autant que possible':'Aucun réseau officiel vérifié à visiter'];
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
    foreach(p50_de_registry_profiles(null,1000,0,false) as $r){
        $id=(string)$r['profile_id'];$sp=$stateMap[$id]??[];
        $facts=p50_de_verified_facts($id);$social=p50_de_social_links($id,false);
        $birthBest=p50_de_best_fact($id,'birth_date',1);
        $photoBest=p50_de_best_fact($id,'photo_url',1);
        $categoryBest=p50_de_best_fact($id,'category',1);
        $bioBest=p50_de_best_fact($id,'bio',1);
        $educationBest=p50_de_best_fact($id,'education',1);
        $nationalityBest=p50_de_best_fact($id,'nationality',1);
        $runStmt=db()->prepare('SELECT status,collector,started_at,finished_at,error_message,items_found,items_verified FROM p50_collection_runs WHERE profile_id=? ORDER BY started_at DESC LIMIT 1');$runStmt->execute([$id]);$lastRun=$runStmt->fetch()?:null;
        $photoConfidence=((string)($sp['photoStatus']??'')==='validated')?100:(int)($photoBest['confidence']??0);
        $scoreConfidence=(int)($sp['quality']['score']??0);
        $liveConfidence=(int)($sp['quality']['live']??0);
        $birthConfidence=(int)($birthBest['confidence']??0);
        $socialConfidence=0;$verifiedSocial=0;
        foreach($social as $l){if($l['status']==='verified'&&(int)$l['confidence']>=$threshold){$verifiedSocial++;$socialConfidence=max($socialConfidence,(int)$l['confidence']);}}
        $identityConfidence=max(90,(int)($sp['quality']['identity']??0));
        $qualities=['identity'=>$identityConfidence,'photo'=>$photoConfidence,'birth'=>$birthConfidence,'social'=>$socialConfidence,'score'=>$scoreConfidence,'live'=>$liveConfidence];
        $complete=count(array_filter($qualities,static fn($v)=>$v>=$threshold));
        $profiles[]=[
            'id'=>$id,'name'=>$r['public_name'],'handle'=>$r['handle'],'region'=>$r['region'],'category'=>$r['category'],
            'eligible'=>(bool)$r['eligible'],'alive'=>(bool)$r['alive'],
            'quality'=>$qualities,'completeness'=>(int)round($complete/count($qualities)*100),
            'facts'=>$facts,'birthBest'=>$birthBest,'birthDate'=>(string)($birthBest['normalized_value']??''),'birthStatus'=>(string)($birthBest['status']??''),'photoBest'=>$photoBest,'categoryBest'=>$categoryBest,'bioBest'=>$bioBest,'nationalityBest'=>$nationalityBest,
            'socialLinks'=>$social,'verifiedSocialCount'=>$verifiedSocial,'lastRun'=>$lastRun,'educationBest'=>$educationBest,
            'priorityWave'=>p50_de_is_priority_profile($id)?'V22-16':'','trendCandidate'=>p50_de_compute_trend_score($id),
            'lastCollectedAt'=>$lastRun['finished_at']??null,
        ];
    }
    $kpis=[
        'profiles'=>count($profiles),
        'eligible'=>count(array_filter($profiles,static fn($p)=>!empty($p['eligible']))),
        'pending'=>count(array_filter($profiles,static fn($p)=>empty($p['eligible']))),
        'birthVerified'=>count(array_filter($profiles,static fn($p)=>(int)($p['birthBest']['confidence']??0)>=$threshold&&($p['birthBest']['status']??'')==='verified')),
        'birthCandidates'=>count(array_filter($profiles,static fn($p)=>!empty($p['birthBest'])&&(int)($p['birthBest']['confidence']??0)<$threshold)),
        'socialVerified'=>count(array_filter($profiles,static fn($p)=>$p['quality']['social']>=$threshold)),
        'photoCandidates'=>count(array_filter($profiles,static fn($p)=>!empty($p['photoBest']))),
        'fullyReliable'=>count(array_filter($profiles,static fn($p)=>$p['completeness']>=67)),
        'autoEnriched'=>count(array_filter($profiles,static fn($p)=>!empty($p['facts'])||!empty($p['photoBest'])||!empty($p['verifiedSocialCount']))),
        'neverCollected'=>count(array_filter($profiles,static fn($p)=>empty($p['lastRun']))),
    ];
    return ['ok'=>true,'engineVersion'=>22.5,'threshold'=>$threshold,'kpis'=>$kpis,'profiles'=>$profiles,'generatedAt'=>gmdate('c')];
}
