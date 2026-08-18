-- Operational data created after this migration is intentionally removed.
DROP TABLE IF EXISTS my2n_mode_runs;
DROP TABLE IF EXISTS my2n_schedules;
DROP TABLE IF EXISTS my2n_modes;
ALTER TABLE my2n_member_snapshots DROP INDEX uq_my2n_snapshot_operation, DROP COLUMN operation_id;
ALTER TABLE my2n_audit_log DROP INDEX idx_my2n_audit_operation,
    DROP COLUMN snapshot_id, DROP COLUMN trigger_type, DROP COLUMN mode_key, DROP COLUMN operation_id;
RENAME TABLE my2n_schedules_legacy TO my2n_schedules;

