CREATE TABLE item_lists (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    area VARCHAR(32) NOT NULL DEFAULT 'rooms',
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_item_lists_name (name),
    CONSTRAINT fk_item_list_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE item_list_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    list_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    default_instructions TEXT NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_item_list_item_name (list_id, name),
    INDEX idx_item_list_order (list_id, sort_order, id),
    CONSTRAINT fk_item_list_item_list FOREIGN KEY (list_id) REFERENCES item_lists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO item_lists (name, area, is_system) VALUES ('Check Geral', 'rooms', 1);
SET @room_check_list_id = LAST_INSERT_ID();

INSERT INTO item_list_items (list_id, name, default_instructions, sort_order) VALUES
(@room_check_list_id, 'Espelho', '', 10),
(@room_check_list_id, 'Lampadas', '', 20),
(@room_check_list_id, 'Armarios', '', 30),
(@room_check_list_id, 'Cabeceiras', '', 40),
(@room_check_list_id, 'Ventoinhas', '', 50),
(@room_check_list_id, 'Cortinas', '', 60),
(@room_check_list_id, 'Fichas', '', 70),
(@room_check_list_id, 'Camas', '', 80),
(@room_check_list_id, 'Luzes', '', 90),
(@room_check_list_id, 'Portas', '', 100),
(@room_check_list_id, 'Fechaduras', '', 110),
(@room_check_list_id, 'Janelas', '', 120),
(@room_check_list_id, 'Chaves', '', 130),
(@room_check_list_id, 'Placa de Saida', '', 140),
(@room_check_list_id, 'Caixote de Lixo', '', 150),
(@room_check_list_id, 'Paredes', '', 160),
(@room_check_list_id, 'Hangers', '', 170);

ALTER TABLE room_checklist_values
    ADD COLUMN list_id BIGINT UNSIGNED NULL AFTER room_number;
UPDATE room_checklist_values SET list_id = @room_check_list_id WHERE list_id IS NULL;
ALTER TABLE room_checklist_values
    MODIFY COLUMN list_id BIGINT UNSIGNED NOT NULL,
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (list_id, property_name, room_number, item_name),
    ADD INDEX idx_checklist_list (list_id),
    ADD CONSTRAINT fk_checklist_item_list FOREIGN KEY (list_id) REFERENCES item_lists(id);

ALTER TABLE room_item_assignments
    ADD COLUMN list_id BIGINT UNSIGNED NULL AFTER interval_id;
UPDATE room_item_assignments SET list_id = @room_check_list_id WHERE list_id IS NULL;
ALTER TABLE room_item_assignments
    MODIFY COLUMN list_id BIGINT UNSIGNED NOT NULL,
    DROP INDEX uq_interval_room_item,
    ADD UNIQUE KEY uq_interval_list_room_item (interval_id, list_id, property_name, room_number, item_name),
    ADD INDEX idx_assignment_list (list_id),
    ADD CONSTRAINT fk_assignment_item_list FOREIGN KEY (list_id) REFERENCES item_lists(id);
