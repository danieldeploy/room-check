CREATE TABLE IF NOT EXISTS my2n_member_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_ids_json JSON NOT NULL,
    source VARCHAR(32) NOT NULL,
    created_by VARCHAR(190) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS my2n_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    action VARCHAR(64) NOT NULL,
    actor VARCHAR(190) NULL,
    before_member_ids_json JSON NULL,
    requested_member_ids_json JSON NULL,
    confirmed_member_ids_json JSON NULL,
    dry_run TINYINT(1) NOT NULL DEFAULT 1,
    success TINYINT(1) NOT NULL DEFAULT 0,
    error_message VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_my2n_audit_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS my2n_schedules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    mode_name VARCHAR(64) NOT NULL,
    local_time TIME NOT NULL,
    member_ids_json JSON NOT NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Lisbon',
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    last_run_at DATETIME NULL,
    created_by VARCHAR(190) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
