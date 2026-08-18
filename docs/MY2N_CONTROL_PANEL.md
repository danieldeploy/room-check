# My2N control panel

## Goal
Integrate My2N intercom control into the existing Room Check application without changing the established room-management behavior.

## Functional scope
- Select which mobile devices receive calls from each bell.
- Allow a mobile to answer one bell, multiple bells, or no bells.
- Allow an apartment to have multiple bells.
- Show bell identity in assignment and status tables.
- Support a Reception schedule from 08:00 to 15:00.
- Support an Out-of-hours schedule from 15:00 to 08:00.
- Support manual activation, automatic scheduling and rollback.
- Display device SIP state such as `REGISTERED`, `NOT_REGISTERED` and provider states such as `NEVER_REGISTERED` when returned.
- Store changes and operational actions in an audit trail.

## Domain model
Do not hard-code a single bell, apartment or mobile. Treat these as dynamic entities with explicit relationships:

- Apartment: may have multiple bells.
- Bell: belongs to an apartment/site context and may call multiple mobiles.
- Mobile: may be assigned to multiple bells.
- Assignment: represents the many-to-many relationship between bells and mobiles and may participate in schedules/modes.

Confirm the current schema before adding or changing tables. Use migrations and document rollback.

## Security
- My2N credentials and `sipPassword` must remain server-side and outside Git.
- Never expose provider secrets in HTML, JavaScript, API responses, logs or screenshots.
- Apply role permissions and CSRF protection to control actions.
- Audit actor, action, target, time, result and rollback where applicable.
- Use `Europe/Lisbon` consistently for schedules and stored/displayed timestamps.

## Continuation checkpoint
Start by inspecting commit `ec9d4b48ce6f88e7fa594b9d4a05c6cc00b8a612` and the current branch state. Do not repeat completed work or deploy until explicitly authorized.

## Scheduled modes operations

- Apply `migrations/006_my2n_scheduled_modes.sql` only after a database backup. It preserves the old schedule table as `my2n_schedules_legacy` and creates per-bell assignments.
- Populate one `my2n_schedules` row for every bell in each mode, then explicitly set `my2n_modes.enabled = 1`. Both modes are disabled after migration and `MY2N_ALLOW_WRITES` remains the independent, default-off write guard.
- Run `cron/my2n-scheduler.php` at least once per minute. It derives the effective occurrence in `Europe/Lisbon`; the unique run ledger makes repeated invocations idempotent, including the 00:00–08:00 continuation of the prior out-of-hours occurrence.
- Manual activation requires `my2n.schedule`; snapshot rollback requires `my2n.rollback`. Every operation records a UUID, trigger, actor/result and snapshot without provider credentials.
- On a multi-bell failure, already changed bells are immediately compensated from the new snapshot. A `rollback_failed` result requires operator review: inspect the run/audit entry and provider state before using the recorded snapshot again.
- Operational rollback: enter the snapshot ID in the panel. This creates another snapshot first, so the rollback itself can be reversed.
- Schema rollback: stop the cron, disable writes, verify no operation is running, and execute `migrations/006_my2n_scheduled_modes_rollback.sql`. This removes phase-006 operational history and restores the legacy schedule table; retain the pre-migration backup for recovery.
