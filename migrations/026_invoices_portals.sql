-- Additive migration; safe to re-run. All stored timestamps are UTC.
CREATE TABLE IF NOT EXISTS invoice_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    schedule_day TINYINT UNSIGNED NOT NULL DEFAULT 5,
    schedule_time CHAR(5) NOT NULL DEFAULT '04:00',
    browser_ready TINYINT(1) NOT NULL DEFAULT 0,
    browser_checked_at DATETIME NULL,
    login_verified_at DATETIME NULL,
    worker_seen_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO invoice_settings (id) VALUES (1);

CREATE TABLE IF NOT EXISTS invoice_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind VARCHAR(16) NOT NULL,
    property_id VARCHAR(16) NOT NULL,
    period CHAR(7) NOT NULL,
    state VARCHAR(32) NOT NULL DEFAULT 'queued',
    active_key VARCHAR(100) NULL,
    schedule_key VARCHAR(100) NULL,
    requested_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    result_code VARCHAR(64) NULL,
    imported_count INT UNSIGNED NOT NULL DEFAULT 0,
    duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_invoice_active (active_key),
    UNIQUE KEY uq_invoice_schedule (schedule_key),
    INDEX idx_invoice_queue (state, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_invoices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    portal VARCHAR(24) NOT NULL DEFAULT 'booking',
    property_id VARCHAR(16) NOT NULL,
    invoice_number VARCHAR(128) NOT NULL,
    issued_on DATE NOT NULL,
    period CHAR(7) NOT NULL,
    sha256 CHAR(64) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    job_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_portal_invoice (portal, property_id, invoice_number),
    UNIQUE KEY uq_portal_invoice_hash (portal, property_id, sha256),
    INDEX idx_portal_invoice_period (period, property_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_permissions (role, permission) VALUES
    ('gerente', 'invoices.view'), ('gerente', 'invoices.run');
