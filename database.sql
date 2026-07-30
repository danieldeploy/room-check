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
