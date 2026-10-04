CREATE TABLE IF NOT EXISTS translation_cache (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_language CHAR(2) NOT NULL,
    target_language CHAR(2) NOT NULL,
    source_hash CHAR(64) NOT NULL,
    source_text TEXT NOT NULL,
    translated_text TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_translation_cache_pair_hash
        (source_language, target_language, source_hash),
    INDEX idx_translation_cache_updated_at (updated_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
