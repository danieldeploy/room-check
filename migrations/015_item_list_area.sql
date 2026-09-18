ALTER TABLE item_lists
    ADD COLUMN area VARCHAR(32) NOT NULL DEFAULT 'rooms' AFTER name;

UPDATE item_lists
SET area = 'rooms'
WHERE area = '' OR area IS NULL;
