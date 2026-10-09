ALTER TABLE room_item_assignments
    ADD COLUMN due_date DATE NULL AFTER assigned_by_user_id;

UPDATE room_item_assignments
SET due_date = DATE(assigned_at)
WHERE due_date IS NULL;

ALTER TABLE room_item_assignments
    MODIFY COLUMN due_date DATE NOT NULL,
    ADD INDEX idx_assignment_due_date (due_date, assigned_to_user_id, completed_at);
