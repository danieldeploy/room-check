CREATE TABLE IF NOT EXISTS room_checklist_values (
    property_name VARCHAR(80) NOT NULL,
    room_number TINYINT UNSIGNED NOT NULL,
    item_name VARCHAR(80) NOT NULL,
    problem TEXT NOT NULL,
    status ENUM('wrong', 'ok') NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (property_name, room_number, item_name),
    INDEX idx_property_room (property_name, room_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(64) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(40) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username_key CHAR(64) NOT NULL,
    ip_key CHAR(64) NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_login_attempts_lookup (username_key, ip_key, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(64) NOT NULL,
    details_json JSON NULL,
    ip_key CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_auth_audit_created_at (created_at),
    INDEX idx_auth_audit_actor (actor_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    role VARCHAR(40) NOT NULL,
    permission VARCHAR(64) NOT NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (role, permission),
    INDEX idx_role_permissions_updated_by (updated_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS room_item_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    property_name VARCHAR(80) NOT NULL,
    room_number TINYINT UNSIGNED NOT NULL,
    item_name VARCHAR(80) NOT NULL,
    assigned_to_user_id BIGINT UNSIGNED NOT NULL,
    assigned_by_user_id BIGINT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    completed_by_user_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_room_item_assignment (property_name, room_number, item_name),
    INDEX idx_assignment_assignee (assigned_to_user_id, completed_at),
    INDEX idx_assignment_room (property_name, room_number),
    CONSTRAINT fk_assignment_assignee FOREIGN KEY (assigned_to_user_id) REFERENCES users(id),
    CONSTRAINT fk_assignment_assigner FOREIGN KEY (assigned_by_user_id) REFERENCES users(id),
    CONSTRAINT fk_assignment_completer FOREIGN KEY (completed_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_permissions (role, permission) VALUES
    ('gerente', 'room_check.view'),
    ('gerente', 'room_check.edit'),
    ('gerente', 'zkaccess.view'),
    ('gerente', 'zkaccess.configure'),
    ('gerente', 'my2n.view'),
    ('gerente', 'my2n.credentials'),
    ('gerente', 'my2n.control'),
    ('gerente', 'my2n.schedule'),
    ('gerente', 'my2n.rollback'),
    ('gerente', 'users.manage'),
    ('gerente', 'permissions.manage'),
    ('gerente', 'audit.view'),
    ('gerente', 'room_tasks.assign'),
    ('governanta', 'room_check.view'),
    ('governanta', 'room_check.edit'),
    ('governanta', 'my2n.view'),
    ('governanta', 'room_tasks.assign'),
    ('tecnico_manutencao', 'room_check.view'),
    ('tecnico_manutencao', 'room_check.edit'),
    ('tecnico_manutencao', 'zkaccess.view'),
    ('tecnico_manutencao', 'my2n.view'),
    ('empregada_andares', 'room_check.view'),
    ('empregada_andares', 'room_check.edit'),
    ('empregada_andares', 'room_tasks.view_own');

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
