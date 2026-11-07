-- Cloudbeds reservation events for the ZKAccess automation.
-- Additive only: no credential, PIN or guest identity is stored here.
CREATE TABLE IF NOT EXISTS zk_access_webhook_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_version VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
    property_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    property_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    reservation_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    sub_reservation_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    room_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    previous_room_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    event_timestamp DECIMAL(20,6) NOT NULL,
    payload_json JSON NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME NULL,
    processed_at DATETIME NULL,
    last_error VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_zk_access_event_key (event_key),
    INDEX idx_zk_access_queue (status, available_at, id),
    INDEX idx_zk_access_reservation (property_id, reservation_id, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
