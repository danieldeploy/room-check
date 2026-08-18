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
