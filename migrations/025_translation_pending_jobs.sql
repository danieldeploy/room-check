CREATE TABLE IF NOT EXISTS translation_pending_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    engine_key VARCHAR(64) NOT NULL DEFAULT 'google-basic-nmt-v2',
    job_key CHAR(64) NOT NULL,
    channel VARCHAR(32) NOT NULL,
    operation_type VARCHAR(80) NOT NULL,
    payload_json JSON NOT NULL,
    source_language CHAR(2) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    generation INT UNSIGNED NOT NULL DEFAULT 1,
    not_before DATETIME NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    locked_at DATETIME NULL,
    completed_at DATETIME NULL,
    last_error VARCHAR(1000) NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_translation_pending_job (engine_key, job_key),
    INDEX idx_translation_pending_due (engine_key, status, not_before, id),
    CONSTRAINT fk_translation_pending_creator
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
