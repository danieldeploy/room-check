ALTER TABLE translation_cache
    ADD COLUMN engine_key VARCHAR(64) NOT NULL DEFAULT 'mymemory-v1' AFTER id,
    DROP INDEX uq_translation_cache_pair_hash,
    ADD UNIQUE KEY uq_translation_cache_engine_pair_hash
        (engine_key, source_language, target_language, source_hash);

