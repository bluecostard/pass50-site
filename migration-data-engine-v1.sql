-- PASS50 Data Engine v1

CREATE TABLE IF NOT EXISTS p50_profile_registry (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p50_collection_runs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p50_fact_evidence (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p50_facts (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p50_social_link_evidence (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p50_social_links (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p50_ranking_snapshots (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p50_activity_events (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p50_engine_settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value LONGTEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO p50_engine_settings(setting_key,setting_value)
VALUES('confidence_threshold','90')
ON DUPLICATE KEY UPDATE setting_value=setting_value;


CREATE TABLE IF NOT EXISTS p50_algorithm_scores (
  profile_id VARCHAR(100) NOT NULL,
  period_key VARCHAR(16) NOT NULL,
  score DECIMAL(6,2) NOT NULL DEFAULT 0,
  confidence DECIMAL(6,2) NOT NULL DEFAULT 0,
  coverage DECIMAL(6,2) NOT NULL DEFAULT 0,
  criteria_json LONGTEXT NOT NULL,
  raw_json LONGTEXT NOT NULL,
  calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(profile_id,period_key),
  INDEX idx_p50_algorithm_period_score(period_key,score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
