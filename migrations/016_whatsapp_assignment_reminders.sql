CREATE TABLE whatsapp_assignment_reminders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assigned_to_user_id BIGINT UNSIGNED NOT NULL,
    due_date DATE NOT NULL,
    property_name VARCHAR(120) NOT NULL,
    scheduled_at DATETIME NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NULL,
    meta_message_id VARCHAR(190) NULL,
    last_error VARCHAR(1000) NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_whatsapp_reminder_employee_date_property (assigned_to_user_id, due_date, property_name),
    KEY idx_whatsapp_reminder_queue (status, scheduled_at, next_attempt_at),
    CONSTRAINT fk_whatsapp_reminder_employee FOREIGN KEY (assigned_to_user_id) REFERENCES users(id),
    CONSTRAINT fk_whatsapp_reminder_creator FOREIGN KEY (created_by_user_id)
        REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
