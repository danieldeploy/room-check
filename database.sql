CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(64) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    preferred_name VARCHAR(120) NULL,
    email VARCHAR(190) NULL,
    mobile VARCHAR(32) NULL,
    preferred_language ENUM('pt', 'en') NOT NULL DEFAULT 'pt',
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(40) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS verification_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(32) NOT NULL,
    name VARCHAR(80) NOT NULL,
    name_en VARCHAR(80) NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_verification_categories_slug (slug),
    UNIQUE KEY uq_verification_categories_name (name),
    INDEX idx_verification_categories_order (sort_order, id),
    CONSTRAINT fk_verification_category_creator
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO verification_categories (slug, name, name_en, sort_order) VALUES
    ('rooms', 'Quartos', 'Rooms', 10),
    ('shared_bathrooms', 'Casas de banho comuns', 'Shared bathrooms', 20),
    ('corridors', 'Corredores', 'Corridors', 30),
    ('kitchens', 'Cozinhas', 'Kitchens', 40),
    ('terraces', 'Terraços', 'Terraces', 50);

CREATE TABLE IF NOT EXISTS item_lists (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    name_en VARCHAR(120) NULL,
    area VARCHAR(32) NOT NULL DEFAULT 'rooms',
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_item_lists_name (name),
    CONSTRAINT fk_item_list_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS item_list_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    list_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    name_en VARCHAR(80) NULL,
    default_instructions TEXT NOT NULL,
    default_instructions_en TEXT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_item_list_item_name (list_id, name),
    INDEX idx_item_list_order (list_id, sort_order, id),
    CONSTRAINT fk_item_list_item_list FOREIGN KEY (list_id) REFERENCES item_lists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO item_lists (id, name, name_en, area, is_system)
VALUES (1, 'Check Geral', 'General Check', 'rooms', 1);
INSERT IGNORE INTO item_list_items (list_id, name, name_en, default_instructions, sort_order) VALUES
    (1, 'Espelho', 'Mirror', '', 10),
    (1, 'Lampadas', 'Lights', '', 20),
    (1, 'Armarios', 'Wardrobes', '', 30),
    (1, 'Cabeceiras', 'Headboards', '', 40),
    (1, 'Ventoinhas', 'Fans', '', 50),
    (1, 'Cortinas', 'Curtains', '', 60),
    (1, 'Fichas', 'Power sockets', '', 70),
    (1, 'Camas', 'Beds', '', 80),
    (1, 'Luzes', 'Lights', '', 90),
    (1, 'Portas', 'Doors', '', 100),
    (1, 'Fechaduras', 'Locks', '', 110),
    (1, 'Janelas', 'Windows', '', 120),
    (1, 'Chaves', 'Keys', '', 130),
    (1, 'Placa de Saida', 'Exit sign', '', 140),
    (1, 'Caixote de Lixo', 'Waste bin', '', 150),
    (1, 'Paredes', 'Walls', '', 160),
    (1, 'Cabides', 'Hangers', '', 170);

CREATE TABLE IF NOT EXISTS room_checklist_values (
    property_name VARCHAR(80) NOT NULL,
    room_number TINYINT UNSIGNED NOT NULL,
    list_id BIGINT UNSIGNED NOT NULL,
    item_name VARCHAR(80) NOT NULL,
    problem TEXT NOT NULL,
    problem_en TEXT NULL,
    status ENUM('wrong', 'ok') NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (list_id, property_name, room_number, item_name),
    INDEX idx_property_room (property_name, room_number),
    CONSTRAINT fk_checklist_item_list FOREIGN KEY (list_id) REFERENCES item_lists(id)
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

CREATE TABLE IF NOT EXISTS room_verification_intervals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    name_en VARCHAR(120) NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_verification_intervals_dates (start_date, end_date),
    CONSTRAINT fk_verification_interval_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    CONSTRAINT chk_verification_interval_dates CHECK (end_date >= start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS room_item_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    interval_id BIGINT UNSIGNED NOT NULL,
    list_id BIGINT UNSIGNED NOT NULL,
    property_name VARCHAR(80) NOT NULL,
    room_number TINYINT UNSIGNED NOT NULL,
    item_name VARCHAR(80) NOT NULL,
    assigned_to_user_id BIGINT UNSIGNED NOT NULL,
    assigned_by_user_id BIGINT UNSIGNED NOT NULL,
    due_date DATE NOT NULL,
    verification_instructions TEXT NOT NULL,
    verification_instructions_en TEXT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    completed_by_user_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_interval_list_room_item (interval_id, list_id, property_name, room_number, item_name),
    INDEX idx_assignment_interval (interval_id, due_date),
    INDEX idx_assignment_assignee (assigned_to_user_id, completed_at),
    INDEX idx_assignment_due_date (due_date, assigned_to_user_id, completed_at),
    INDEX idx_assignment_room (property_name, room_number),
    INDEX idx_assignment_list (list_id),
    CONSTRAINT fk_assignment_interval FOREIGN KEY (interval_id) REFERENCES room_verification_intervals(id),
    CONSTRAINT fk_assignment_item_list FOREIGN KEY (list_id) REFERENCES item_lists(id),
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
    ('gerente', 'verification_categories.manage'),
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

CREATE TABLE IF NOT EXISTS translation_cache (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    engine_key VARCHAR(64) NOT NULL DEFAULT 'google-basic-nmt-v2',
    source_language CHAR(2) NOT NULL,
    target_language CHAR(2) NOT NULL,
    source_hash CHAR(64) NOT NULL,
    source_text TEXT NOT NULL,
    translated_text TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_translation_cache_engine_pair_hash
        (engine_key, source_language, target_language, source_hash),
    INDEX idx_translation_cache_updated_at (updated_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

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
