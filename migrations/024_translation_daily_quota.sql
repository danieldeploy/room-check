CREATE TABLE IF NOT EXISTS translation_daily_usage (
    engine_key VARCHAR(64) NOT NULL,
    quota_date DATE NOT NULL,
    character_limit INT UNSIGNED NOT NULL,
    characters_used INT UNSIGNED NOT NULL DEFAULT 0,
    limit_reached_at DATETIME NULL,
    alert_status VARCHAR(16) NOT NULL DEFAULT 'none',
    alert_attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    alert_next_attempt_at DATETIME NULL,
    alert_sent_at DATETIME NULL,
    alert_message_id VARCHAR(255) NULL,
    alert_last_error VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (engine_key, quota_date),
    INDEX idx_translation_daily_alert (alert_status, alert_next_attempt_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
