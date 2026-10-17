CREATE TABLE IF NOT EXISTS verification_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(32) NOT NULL,
    name VARCHAR(80) NOT NULL,
    name_en VARCHAR(80) NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_verification_categories_slug (slug),
    UNIQUE KEY uq_verification_categories_name (name),
    INDEX idx_verification_categories_order (sort_order, id),
    CONSTRAINT fk_verification_category_creator
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO verification_categories (slug, name, name_en, sort_order) VALUES
    ('rooms', 'Quartos', 'Rooms', 10),
    ('shared_bathrooms', 'Casas de banho comuns', 'Shared bathrooms', 20),
    ('corridors', 'Corredores', 'Corridors', 30),
    ('kitchens', 'Cozinhas', 'Kitchens', 40),
    ('terraces', 'Terraços', 'Terraces', 50);

-- Preserve any previously created area that is not part of the original five.
INSERT IGNORE INTO verification_categories (slug, name, name_en, sort_order)
SELECT DISTINCT area, area, area, 1000
FROM item_lists
WHERE NULLIF(TRIM(area), '') IS NOT NULL;

INSERT IGNORE INTO role_permissions (role, permission)
VALUES ('gerente', 'verification_categories.manage');
