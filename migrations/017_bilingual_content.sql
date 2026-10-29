ALTER TABLE users
    ADD COLUMN preferred_language ENUM('pt', 'en') NOT NULL DEFAULT 'pt' AFTER mobile;

ALTER TABLE item_list_items
    ADD COLUMN default_instructions_en TEXT NULL AFTER default_instructions;

ALTER TABLE room_checklist_values
    ADD COLUMN problem_en TEXT NULL AFTER problem;

ALTER TABLE room_item_assignments
    ADD COLUMN verification_instructions_en TEXT NULL AFTER verification_instructions;
