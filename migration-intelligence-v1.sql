CREATE TABLE IF NOT EXISTS p50_intelligence_snapshots (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
