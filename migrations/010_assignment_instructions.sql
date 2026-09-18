ALTER TABLE room_item_assignments
    ADD COLUMN verification_instructions TEXT NOT NULL AFTER due_date;
