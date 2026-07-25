SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE users (
  id CHAR(36) PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(80) NOT NULL,
  role ENUM('owner','admin','editor','verifier','member') NOT NULL DEFAULT 'member',
  email_confirmed_at DATETIME NULL,
  confirmation_token_hash CHAR(64) NULL,
  confirmation_expires_at DATETIME NULL,
  reset_token_hash CHAR(64) NULL,
  reset_expires_at DATETIME NULL,
  deleted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_confirm_token (confirmation_token_hash),
  INDEX idx_users_reset_token (reset_token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sessions (
  token_hash CHAR(64) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_sessions_user (user_id), INDEX idx_sessions_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_preferences (
  user_id CHAR(36) PRIMARY KEY,
  favorites LONGTEXT NOT NULL,
  following LONGTEXT NOT NULL,
  follow_alerts LONGTEXT NOT NULL,
  notification_mode ENUM('instant','daily','off') NOT NULL DEFAULT 'daily',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE app_state (
  id VARCHAR(32) PRIMARY KEY,
  data LONGTEXT NOT NULL,
  updated_by CHAR(36) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_state_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  title VARCHAR(190) NOT NULL,
  body TEXT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_notifications_user_date (user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coules_votes (
  poll_key VARCHAR(190) NOT NULL,
  user_id CHAR(36) NOT NULL,
  profile_id VARCHAR(100) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (poll_key,user_id),
  CONSTRAINT fk_coules_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_coules_poll_profile (poll_key,profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE p50_duel_vote_history (
  id CHAR(64) CHARACTER SET ascii PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  poll_key VARCHAR(190) NOT NULL,
  candidate_a_id VARCHAR(100) NOT NULL,
  candidate_b_id VARCHAR(100) NOT NULL,
  candidate_a_name VARCHAR(190) NOT NULL,
  candidate_b_name VARCHAR(190) NOT NULL,
  candidate_a_photo TEXT NULL,
  candidate_b_photo TEXT NULL,
  selected_profile_id VARCHAR(100) NOT NULL,
  candidate_a_percentage SMALLINT UNSIGNED NULL,
  candidate_b_percentage SMALLINT UNSIGNED NULL,
  total_votes INT UNSIGNED NOT NULL DEFAULT 0,
  candidate_a_rank SMALLINT UNSIGNED NULL,
  candidate_b_rank SMALLINT UNSIGNED NULL,
  candidate_a_score DECIMAL(6,2) NULL,
  candidate_b_score DECIMAL(6,2) NULL,
  state_revision BIGINT UNSIGNED NULL,
  state_updated_at DATETIME NULL,
  voted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_duel_history_user (user_id),
  INDEX idx_duel_history_poll (poll_key),
  INDEX idx_duel_history_voted (voted_at),
  INDEX idx_duel_history_selected (selected_profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE p50_vote_share_sessions (
  id CHAR(64) CHARACTER SET ascii PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  poll_key VARCHAR(190) NOT NULL,
  profile_id VARCHAR(100) NOT NULL,
  history_id CHAR(64) CHARACTER SET ascii NULL,
  vote_updated_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_vote_share_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_vote_share_user_date (user_id,created_at),
  INDEX idx_vote_share_expiry (expires_at),
  INDEX idx_vote_share_history (history_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE p50_vote_share_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  share_id CHAR(64) CHARACTER SET ascii NOT NULL,
  event_name VARCHAR(40) NOT NULL,
  platform VARCHAR(30) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_vote_share_session FOREIGN KEY (share_id) REFERENCES p50_vote_share_sessions(id) ON DELETE CASCADE,
  INDEX idx_vote_share_event_date (event_name,created_at),
  INDEX idx_vote_share_session (share_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
