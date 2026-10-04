CREATE TABLE room_verification_intervals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_verification_intervals_dates (start_date, end_date),
    CONSTRAINT fk_verification_interval_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    CONSTRAINT chk_verification_interval_dates CHECK (end_date >= start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE room_item_assignments
    ADD COLUMN interval_id BIGINT UNSIGNED NULL AFTER id;

INSERT INTO room_verification_intervals (name, start_date, end_date, created_by_user_id)
SELECT 'Atribuições anteriores', MIN(due_date), MAX(due_date), MIN(assigned_by_user_id)
FROM room_item_assignments
HAVING COUNT(*) > 0;

UPDATE room_item_assignments
SET interval_id = (SELECT id FROM room_verification_intervals ORDER BY id LIMIT 1)
WHERE interval_id IS NULL;

ALTER TABLE room_item_assignments
    MODIFY COLUMN interval_id BIGINT UNSIGNED NOT NULL,
    DROP INDEX uq_room_item_assignment,
    ADD UNIQUE KEY uq_interval_room_item (interval_id, property_name, room_number, item_name),
    ADD INDEX idx_assignment_interval (interval_id, due_date),
    ADD CONSTRAINT fk_assignment_interval FOREIGN KEY (interval_id) REFERENCES room_verification_intervals(id);
