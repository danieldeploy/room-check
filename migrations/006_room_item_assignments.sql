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
    ('gerente', 'room_tasks.assign'),
    ('governanta', 'room_tasks.assign'),
    ('empregada_andares', 'room_tasks.view_own');
