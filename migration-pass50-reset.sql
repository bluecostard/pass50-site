ALTER TABLE users ADD COLUMN reset_token_hash CHAR(64) NULL AFTER confirmation_expires_at;
ALTER TABLE users ADD COLUMN reset_expires_at DATETIME NULL AFTER reset_token_hash;
CREATE INDEX idx_users_reset_token ON users(reset_token_hash);
