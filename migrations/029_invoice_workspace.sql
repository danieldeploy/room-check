-- Additive and repeatable: preserve all existing accounts, tasks and documents.
CREATE TABLE IF NOT EXISTS invoice_account_settings (
 account_id BIGINT UNSIGNED PRIMARY KEY,
 is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
 archived_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS invoice_property_settings (
 account_id BIGINT UNSIGNED NOT NULL,
 property_id VARCHAR(64) NOT NULL,
 is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
 PRIMARY KEY (account_id, property_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS invoice_batches (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 request_key CHAR(64) NOT NULL UNIQUE,
 period CHAR(7) NOT NULL,
 source VARCHAR(16) NOT NULL,
 requested_by BIGINT UNSIGNED NULL,
 retry_of BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL,
 INDEX idx_invoice_batch_period (period, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS invoice_batch_tasks (
 batch_id BIGINT UNSIGNED NOT NULL,
 task_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY (batch_id, task_id),
 INDEX idx_invoice_batch_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
