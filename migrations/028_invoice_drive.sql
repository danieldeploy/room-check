-- Additive, repeatable migration: independent archive and business states.
CREATE TABLE IF NOT EXISTS invoice_document_delivery (
 document_id BIGINT UNSIGNED PRIMARY KEY,
 company_state VARCHAR(24) NOT NULL DEFAULT 'review',
 company_name VARCHAR(120) NULL,
 toconline_state VARCHAR(24) NOT NULL DEFAULT 'pending',
 drive_state VARCHAR(24) NOT NULL DEFAULT 'pending',
 drive_id VARCHAR(128) NULL,
 drive_parent VARCHAR(128) NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 next_attempt_at DATETIME NULL,
 last_attempt_at DATETIME NULL,
 last_error VARCHAR(40) NULL,
 verified_at DATETIME NULL,
 local_deleted_at DATETIME NULL,
 INDEX idx_invoice_drive_queue (drive_state, next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO invoice_document_delivery (document_id) SELECT id FROM invoice_documents;
CREATE TABLE IF NOT EXISTS invoice_drive_settings (
 id TINYINT UNSIGNED PRIMARY KEY,
 email VARCHAR(190) NOT NULL DEFAULT 'daniel.ciorcas@welcomehostel.pt',
 folder_id VARCHAR(128) NULL,
 state VARCHAR(24) NOT NULL DEFAULT 'not_configured',
 checked_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO invoice_drive_settings (id) VALUES (1);
CREATE TABLE IF NOT EXISTS invoice_drive_folders (
 path_key CHAR(64) PRIMARY KEY,
 drive_id VARCHAR(128) NOT NULL,
 parent_id VARCHAR(128) NOT NULL,
 name VARCHAR(190) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS invoice_drive_alerts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 account_id BIGINT UNSIGNED NOT NULL,
 period CHAR(7) NOT NULL,
 error_code VARCHAR(40) NOT NULL,
 state VARCHAR(24) NOT NULL DEFAULT 'pending',
 attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
 next_attempt_at DATETIME NULL,
 created_at DATETIME NOT NULL,
 sent_at DATETIME NULL,
 UNIQUE KEY uq_drive_alert (account_id, period, error_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
