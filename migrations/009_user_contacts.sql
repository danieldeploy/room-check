ALTER TABLE users
    ADD COLUMN email VARCHAR(190) NULL AFTER display_name,
    ADD COLUMN mobile VARCHAR(32) NULL AFTER email,
    ADD UNIQUE KEY uq_users_email (email);
