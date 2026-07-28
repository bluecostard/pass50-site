-- PASS50 YouTube OAuth V1
CREATE TABLE IF NOT EXISTS p50_youtube_oauth_states (
  state_hash CHAR(64) CHARACTER SET ascii PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_p50_youtube_oauth_state_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_p50_youtube_oauth_state_user (user_id),
  INDEX idx_p50_youtube_oauth_state_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p50_youtube_oauth_connections (
  user_id CHAR(36) PRIMARY KEY,
  channel_id VARCHAR(191) NOT NULL,
  channel_title VARCHAR(255) NOT NULL DEFAULT '',
  channel_custom_url VARCHAR(255) NOT NULL DEFAULT '',
  channel_thumbnail_url TEXT NULL,
  access_token_encrypted LONGTEXT NOT NULL,
  refresh_token_encrypted LONGTEXT NULL,
  token_type VARCHAR(32) NOT NULL DEFAULT 'Bearer',
  scopes TEXT NOT NULL,
  access_expires_at DATETIME NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  last_error TEXT NULL,
  connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_refreshed_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_p50_youtube_oauth_connection_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_p50_youtube_oauth_channel (channel_id),
  INDEX idx_p50_youtube_oauth_status (status,access_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
