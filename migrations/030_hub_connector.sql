-- Additive OAuth delegation storage. No passwords or usable bearer tokens.
CREATE TABLE IF NOT EXISTS hub_oauth_grants (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 client_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 scope VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 password_stamp CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at BIGINT NOT NULL,
 expires_at BIGINT NOT NULL,
 idle_expires_at BIGINT NOT NULL,
 last_used_at BIGINT NULL,
 revoked_at BIGINT NULL,
 INDEX idx_hub_oauth_owner (user_id, revoked_at),
 CONSTRAINT fk_hub_oauth_owner FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hub_oauth_codes (
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 grant_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 redirect_uri VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 challenge CHAR(43) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 expires_at BIGINT NOT NULL,
 consumed_at BIGINT NULL,
 CONSTRAINT fk_hub_oauth_code_grant FOREIGN KEY (grant_id) REFERENCES hub_oauth_grants(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hub_oauth_tokens (
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 grant_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 kind VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 expires_at BIGINT NOT NULL,
 consumed_at BIGINT NULL,
 INDEX idx_hub_oauth_token_grant (grant_id),
 CONSTRAINT fk_hub_oauth_token_grant FOREIGN KEY (grant_id) REFERENCES hub_oauth_grants(id) ON DELETE CASCADE
) ENGINE=InnoDB;
