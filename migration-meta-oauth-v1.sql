CREATE TABLE IF NOT EXISTS p50_meta_oauth_connections (
    user_id VARCHAR(100) PRIMARY KEY,
    meta_user_id VARCHAR(100) NOT NULL,
    meta_user_name VARCHAR(255) NOT NULL DEFAULT '',
    access_token_encrypted LONGTEXT NOT NULL,
    scopes TEXT NOT NULL,
    token_expires_at DATETIME NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    last_error VARCHAR(255) NULL,
    connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_refreshed_at DATETIME NULL,
    INDEX idx_p50_meta_status (status, token_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p50_meta_oauth_assets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(100) NOT NULL,
    platform VARCHAR(32) NOT NULL,
    asset_id VARCHAR(100) NOT NULL,
    profile_id VARCHAR(100) NULL,
    asset_name VARCHAR(255) NOT NULL DEFAULT '',
    username VARCHAR(255) NOT NULL DEFAULT '',
    profile_url TEXT NULL,
    picture_url TEXT NULL,
    parent_page_id VARCHAR(100) NULL,
    access_token_encrypted LONGTEXT NOT NULL,
    tasks TEXT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    last_checked_at DATETIME NULL,
    last_error VARCHAR(255) NULL,
    connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_p50_meta_asset (user_id, platform, asset_id),
    INDEX idx_p50_meta_profile (profile_id, platform, status),
    INDEX idx_p50_meta_asset_status (platform, status, last_checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;