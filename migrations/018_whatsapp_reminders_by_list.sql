ALTER TABLE whatsapp_assignment_reminders
    ADD COLUMN list_id BIGINT UNSIGNED NULL AFTER assigned_to_user_id,
    ADD KEY idx_whatsapp_reminder_employee (assigned_to_user_id);

ALTER TABLE whatsapp_assignment_reminders
    DROP INDEX uq_whatsapp_reminder_employee_date_property;

INSERT INTO whatsapp_assignment_reminders
    (assigned_to_user_id, list_id, due_date, property_name, scheduled_at, status,
     attempt_count, next_attempt_at, meta_message_id, last_error,
     created_by_user_id, created_at, updated_at, sent_at)
SELECT
    reminder.assigned_to_user_id,
    assignment.list_id,
    reminder.due_date,
    reminder.property_name,
    reminder.scheduled_at,
    reminder.status,
    reminder.attempt_count,
    reminder.next_attempt_at,
    reminder.meta_message_id,
    reminder.last_error,
    reminder.created_by_user_id,
    reminder.created_at,
    reminder.updated_at,
    reminder.sent_at
FROM whatsapp_assignment_reminders AS reminder
JOIN (
    SELECT DISTINCT assigned_to_user_id, due_date, property_name, list_id
    FROM room_item_assignments
) AS assignment
  ON assignment.assigned_to_user_id = reminder.assigned_to_user_id
 AND assignment.due_date = reminder.due_date
 AND assignment.property_name = reminder.property_name
WHERE reminder.list_id IS NULL;

DELETE FROM whatsapp_assignment_reminders
WHERE list_id IS NULL;

ALTER TABLE whatsapp_assignment_reminders
    MODIFY COLUMN list_id BIGINT UNSIGNED NOT NULL,
    ADD UNIQUE KEY uq_whatsapp_reminder_employee_date_property_list
        (assigned_to_user_id, due_date, property_name, list_id),
    ADD KEY idx_whatsapp_reminder_list (list_id),
    ADD CONSTRAINT fk_whatsapp_reminder_list
        FOREIGN KEY (list_id) REFERENCES item_lists(id);
