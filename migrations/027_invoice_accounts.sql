-- Additive v2 schema. Legacy jobs/documents remain available for rollback.
CREATE TABLE IF NOT EXISTS invoice_accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    portal VARCHAR(24) NOT NULL,
    label VARCHAR(120) NOT NULL,
    enabled TINYINT UNSIGNED NOT NULL DEFAULT 0,
    schedule_day TINYINT UNSIGNED NOT NULL DEFAULT 5,
    schedule_time CHAR(5) NOT NULL DEFAULT '04:00',
    period_basis VARCHAR(24) NOT NULL DEFAULT 'issue_month',
    auth_method VARCHAR(16) NOT NULL DEFAULT 'password',
    status VARCHAR(32) NOT NULL DEFAULT 'not_configured',
    login_verified_at DATETIME NULL,
    sms_token_hash CHAR(64) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_invoice_account_label (portal, label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_account_properties (
    account_id BIGINT UNSIGNED NOT NULL,
    property_id VARCHAR(64) NOT NULL,
    label VARCHAR(120) NOT NULL,
    PRIMARY KEY (account_id, property_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO invoice_accounts (id, portal, label, enabled, schedule_day, schedule_time, period_basis, auth_method, created_at)
SELECT 1, 'booking', 'Welcome / City Center', enabled, schedule_day, schedule_time, 'issue_month', 'sms', UTC_TIMESTAMP()
FROM invoice_settings WHERE id = 1;
INSERT IGNORE INTO invoice_account_properties VALUES
    (1, '1140306', 'Welcome Guest House'), (1, '539828', 'City Center Guest House');

CREATE TABLE IF NOT EXISTS invoice_tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    legacy_id BIGINT UNSIGNED NULL UNIQUE,
    account_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(16) NOT NULL,
    property_id VARCHAR(64) NOT NULL,
    period CHAR(7) NOT NULL,
    state VARCHAR(32) NOT NULL DEFAULT 'queued',
    active_key VARCHAR(180) NULL UNIQUE,
    schedule_key VARCHAR(180) NULL UNIQUE,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NULL,
    requested_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    result_code VARCHAR(64) NULL,
    imported_count INT UNSIGNED NOT NULL DEFAULT 0,
    duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
    INDEX idx_invoice_tasks_queue (state, next_attempt_at, id),
    INDEX idx_invoice_tasks_account (account_id, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO invoice_tasks
    (legacy_id, account_id, kind, property_id, period, state, active_key, schedule_key, requested_by, created_at, started_at, finished_at, result_code, imported_count, duplicate_count)
SELECT id, 1, kind, property_id, period,
    CASE WHEN state = 'running' THEN 'queued' ELSE state END,
    CASE WHEN state = 'running' THEN CONCAT('1:', property_id, ':', period, ':', kind)
         WHEN active_key IS NULL THEN NULL ELSE CONCAT('1:', active_key) END,
    CASE WHEN schedule_key IS NULL THEN NULL ELSE CONCAT('1:', schedule_key) END,
    requested_by, created_at, started_at, finished_at, result_code, imported_count, duplicate_count
FROM invoice_jobs;

CREATE TABLE IF NOT EXISTS invoice_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    legacy_id BIGINT UNSIGNED NULL UNIQUE,
    account_id BIGINT UNSIGNED NOT NULL,
    property_id VARCHAR(64) NOT NULL,
    invoice_number VARCHAR(128) NOT NULL,
    issued_on DATE NULL,
    period CHAR(7) NOT NULL,
    period_basis VARCHAR(24) NOT NULL,
    format VARCHAR(8) NOT NULL DEFAULT 'pdf',
    sha256 CHAR(64) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    job_id BIGINT UNSIGNED NULL,
    pipeline_state VARCHAR(32) NOT NULL DEFAULT 'collected',
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_invoice_document_number (account_id, property_id, invoice_number),
    UNIQUE KEY uq_invoice_document_hash (account_id, property_id, sha256),
    INDEX idx_invoice_documents_period (period, account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO invoice_documents
    (legacy_id, account_id, property_id, invoice_number, issued_on, period, period_basis, sha256, size_bytes, job_id, created_at)
SELECT p.id, 1, p.property_id, p.invoice_number, p.issued_on, p.period, 'issue_month', p.sha256, p.size_bytes, t.id, p.created_at
FROM portal_invoices p LEFT JOIN invoice_tasks t ON t.legacy_id = p.job_id;

CREATE TABLE IF NOT EXISTS invoice_auth_challenges (
    id CHAR(32) NOT NULL PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    task_id BIGINT UNSIGNED NOT NULL,
    method VARCHAR(16) NOT NULL,
    state VARCHAR(16) NOT NULL DEFAULT 'waiting',
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    event_hash CHAR(64) NULL UNIQUE,
    INDEX idx_invoice_challenge_account (account_id, state, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_notification_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    enabled TINYINT UNSIGNED NOT NULL DEFAULT 0,
    recipient_user_id BIGINT UNSIGNED NULL,
    template_name VARCHAR(100) NOT NULL DEFAULT 'invoice_collection_failed_v1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO invoice_notification_settings (id) VALUES (1);

CREATE TABLE IF NOT EXISTS invoice_failure_alerts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    task_id BIGINT UNSIGNED NOT NULL,
    dedupe_key VARCHAR(180) NOT NULL UNIQUE,
    state VARCHAR(24) NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NULL,
    result_code VARCHAR(32) NULL,
    created_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    meta_message_id VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
