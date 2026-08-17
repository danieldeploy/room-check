CREATE TABLE IF NOT EXISTS role_permissions (
    role VARCHAR(40) NOT NULL,
    permission VARCHAR(64) NOT NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (role, permission),
    INDEX idx_role_permissions_updated_by (updated_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_permissions (role, permission) VALUES
    ('gerente', 'room_check.view'),
    ('gerente', 'room_check.edit'),
    ('gerente', 'zkaccess.view'),
    ('gerente', 'zkaccess.configure'),
    ('gerente', 'my2n.view'),
    ('gerente', 'my2n.control'),
    ('gerente', 'my2n.schedule'),
    ('gerente', 'my2n.rollback'),
    ('gerente', 'users.manage'),
    ('gerente', 'permissions.manage'),
    ('gerente', 'audit.view'),
    ('governanta', 'room_check.view'),
    ('governanta', 'room_check.edit'),
    ('governanta', 'my2n.view'),
    ('tecnico_manutencao', 'room_check.view'),
    ('tecnico_manutencao', 'room_check.edit'),
    ('tecnico_manutencao', 'zkaccess.view'),
    ('tecnico_manutencao', 'my2n.view'),
    ('empregada_andares', 'room_check.view'),
    ('empregada_andares', 'room_check.edit');

CREATE TABLE IF NOT EXISTS zk_automation_settings (
    id TINYINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    dry_run TINYINT(1) NOT NULL DEFAULT 1,
    schedule_time TIME NOT NULL DEFAULT '12:55:00',
    room_search_term VARCHAR(40) NOT NULL DEFAULT 'Room',
    runner_version VARCHAR(80) NOT NULL DEFAULT 'V5.1 Direct POST',
    last_run_at DATETIME NULL,
    last_status VARCHAR(40) NULL,
    last_message VARCHAR(500) NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_zk_settings_updated_by (updated_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO zk_automation_settings
    (id, enabled, dry_run, schedule_time, room_search_term, runner_version)
VALUES
    (1, 0, 1, '12:55:00', 'Room', 'V5.1 Direct POST');
