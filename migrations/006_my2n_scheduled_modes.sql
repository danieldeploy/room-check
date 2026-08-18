-- My2N scheduled modes, generalized per bell. Run after 002 and 004.
RENAME TABLE my2n_schedules TO my2n_schedules_legacy;

CREATE TABLE my2n_modes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    mode_key VARCHAR(32) NOT NULL,
    display_name VARCHAR(80) NOT NULL,
    local_start_time TIME NOT NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Lisbon',
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    created_by VARCHAR(190) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_my2n_modes_key (mode_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE my2n_schedules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    mode_id BIGINT UNSIGNED NOT NULL,
    bell_key VARCHAR(100) NOT NULL,
    member_ids_json JSON NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_by VARCHAR(190) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_my2n_schedule_mode_bell (mode_id, bell_key),
    CONSTRAINT fk_my2n_schedule_mode FOREIGN KEY (mode_id) REFERENCES my2n_modes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE my2n_mode_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    operation_id CHAR(36) NOT NULL,
    mode_id BIGINT UNSIGNED NULL,
    trigger_type ENUM('manual','automatic','rollback') NOT NULL,
    local_date DATE NULL,
    actor VARCHAR(190) NULL,
    snapshot_id BIGINT UNSIGNED NULL,
    status ENUM('running','success','failed','rolled_back','rollback_failed') NOT NULL,
    error_message VARCHAR(500) NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_my2n_mode_operation (operation_id),
    UNIQUE KEY uq_my2n_automatic_run (mode_id, trigger_type, local_date),
    INDEX idx_my2n_mode_runs_started (started_at),
    CONSTRAINT fk_my2n_run_mode FOREIGN KEY (mode_id) REFERENCES my2n_modes (id),
    CONSTRAINT fk_my2n_run_snapshot FOREIGN KEY (snapshot_id) REFERENCES my2n_member_snapshots (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE my2n_member_snapshots
    ADD COLUMN operation_id CHAR(36) NULL AFTER id,
    ADD UNIQUE KEY uq_my2n_snapshot_operation (operation_id);

ALTER TABLE my2n_audit_log
    ADD COLUMN operation_id CHAR(36) NULL AFTER id,
    ADD COLUMN mode_key VARCHAR(32) NULL AFTER action,
    ADD COLUMN trigger_type VARCHAR(16) NULL AFTER mode_key,
    ADD COLUMN snapshot_id BIGINT UNSIGNED NULL AFTER actor,
    ADD INDEX idx_my2n_audit_operation (operation_id);

INSERT INTO my2n_modes (mode_key, display_name, local_start_time, timezone, enabled) VALUES
    ('reception', 'Receção', '08:00:00', 'Europe/Lisbon', 0),
    ('out_of_hours', 'Fora de horário', '15:00:00', 'Europe/Lisbon', 0);
