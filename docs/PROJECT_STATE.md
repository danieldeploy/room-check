# Management Hub — current project state

Last reviewed: 2026-10-29 (Europe/Lisbon)

This file records the current project state, not an immutable history. Durable
behavioral decisions live under `docs/decisions/`; detailed workflows live
under `docs/flows/`.

## Production and release

- Production branch: `agent/room-item-assignments`.
- Required PR checks: `validate` and `windows-agent`.
- Production deployment is guarded and automatic after an authorized merge, as
  documented in `docs/AUTOMATED_DEPLOYMENT.md`.
- “Integrated”, “deployed”, and “verified” are separate states and must be
  reported separately.
- Production secrets remain outside the repository and outside `public_html`.

## Repository-backed project memory

- Repository documentation is the durable technical memory for this project.
- `docs/PROJECT_MEMORY.md` defines the read/update protocol.
- `docs/flows/` contains end-to-end orientation maps; `docs/decisions/`
  contains durable ADRs.
- `docs/project-memory.json` maps architecture-sensitive runtime paths to the
  documents that must change with them.
- Pull-request CI runs `tools/project_memory_guard.py` so mapped runtime changes
  cannot silently bypass their state/flow documentation.
- Test-only and visual/static-only changes are intentionally exempt unless they
  also alter mapped runtime behavior.

## Development workflow tooling

- `AGENTS.md` now defines selective use of developer-side Superpowers,
  Ponytail review and Grill Me light. These tools are not application runtime
  dependencies and do not override repository/project decisions.
- Routine authorized work remains direct; full brainstorming, full Ponytail and
  full Grill Me are not automatic gates.
- Independent second-opinion/reviewer calls are not a standing project rule.
- A codebase-memory/code-graph MCP may be used as an optional developer index if
  already available, but Management Hub does not depend on one for build,
  deployment or production operation.

## Management Hub / Room Check

- PHP/MySQL application hosted at `check.welcomehostel.pt`.
- Existing room management, role permissions, bilingual behavior, invoice
  automation, Hub OAuth/MCP connector and paired Windows automation remain in use.
- Application operations use `Europe/Lisbon`.

## Cloudbeds invoices

- Cloudbeds invoice collection by email is an existing production workflow.
- Invoice month classification follows invoice issue date.
- The Airbnb CSV/export-month behavior remains the documented exception until
  explicitly changed.

## ZKTeco MySQL backups — independent Drive archiving

Status: **Prepared on feature branch / not yet verified in production**.
The existing 04:00 Europe/Lisbon AccesControl MySQL dump, encrypted Hub intake,
Google Drive destination and 30-day retention remain unchanged.

The current production design chains ZKTeco Drive delivery after
`cron/invoices.php`. This can delay archiving when the invoice worker is busy.
The scoped correction adds an independent five-minute cPanel cron, with
idempotent WHM provisioning after guarded deploy, and removes backup handling
from invoice cron. The existing dedicated MySQL advisory lock still serializes
the paired Windows intake and the Drive upload.

The private ZKTeco manifest now records verified complete receipt, upload attempt
times/counts, sanitized failure codes/times and Drive read-back confirmation.
Old entries retain their existing evidence; missing historical timestamps are
not fabricated. The manager page shows the new timeline.
See `docs/flows/zkaccess-backup.md`.

Acceptance remains: required CI, production commit confirmed, one exact cron
entry verified in WHM, and a successful archive cycle executed by that cron.
This change does not fix the separate cPanel disk-quota pressure.

## Cloudbeds → ZKAccess guest PIN automation

Status: **Prepared / not integrated**.

- Owner priority: City Center Guest House.
- Cloudbeds Property ID for City Center has been verified as `7541`.
- Cloudbeds property API access has been requested and is still pending activation.
- Draft PR #256 prepares the first Cloudbeds webhook/ZKAccess foundation but is
  not part of the production branch at the time of this review.
- City Center ZKAccess levels and controller/door mappings for Rooms 1–6 were
  verified against the live ZKAccess 5.3 database.
- Direct read-only Pull SDK communication with a C3-400 was successfully tested.
- No production guest PIN has been written by the new automation.
- No Cloudbeds webhook or API key is currently enabled for this new flow.

Target flow:

`Cloudbeds webhook → authoritative reservation validation → generate PIN →
program ZKAccess → read/confirm write → write PIN note to Cloudbeds →
send guest message`

Important current decisions:

- Revoking guest access means rotating/replacing the PIN on the old room rather
  than relying on a separate “revoke” operation.
- On a room change, rotate the PIN on the old room and reuse the guest's existing
  PIN on the new room.
- The Cloudbeds reservation note is written only after ZKAccess confirms the
  PIN programming.
- Webhook retries and duplicate events must not create duplicate PIN operations
  or duplicate guest messages.
- Multi-room reservations must reconcile the affected accommodation, not blindly
  cancel the entire reservation.

See `docs/flows/cloudbeds-zkaccess.md` and ADRs 002–003.

## External blockers / pending work

- Cloudbeds property API/webhook access approval.
- Final Cloudbeds room/accommodation identifiers and scopes must be verified
  against the activated API before enabling the ZKAccess workflow.
- ZKAccess write/reconciliation and final guest-message delivery remain to be
  completed and acceptance-tested before this workflow can be called verified.


## cPanel disk-quota recovery (pending verification)

- Owner request 2026-10-29: automatically recover quota so email delivery is not interrupted, without deleting mail or necessary recoverable backups.
- Protected WHM credentials already exist in GitHub environment `management-hub-production`.
- In-flight PR introduces quota-triggered cleanup only for disposable `.npm/_cacache/`, `.cache/pip/` and `.cache/uv/` files older than 14 days; backup retention is audit-only pending verified recovery; never mail, backups or application data. See `docs/CPANEL_DISK_MAINTENANCE.md`.
- The GitHub scheduled bridge is maintained separately on `main`; **both workflows need integration**. This mechanism has not yet been verified against the live WHM UAPI or via a received test email.
- Recorded cPanel usage on 2026-10-29: 6000.04 MB / 6000 MB. Actual disk recovery remains unconfirmed.

- Storage inventory read-only diagnostic handles cPanel Fileman empty size fields and is added to scheduled cleanup; it reports backup age counts and first-level cache entries; no automatic deletion of backup directories.

- Puppeteer cache inventory read-only added for identifying browser versions without touching browser downloads or automation sessions.

- Puppeteer installer archives can be reclaimed only after age and exact metadata checks and confirming a matching extracted browser directory; installed browser versions remain protected.

- Puppeteer archive inventory walks only parent directories and skips extracted browser trees; this prevents the guarded scanner from rejecting ordinary installed-browser files.

## Storage growth investigation (2026-10-29)

- The deployment gate creates a new complete backup on each production release. There is currently no retention/deletion phase. The latest WHM inventory showed 172 release directories, creating an ongoing quota-growth risk.
- Daily protected GitHub Actions maintenance now includes a separate **read-only** report of account directory usage, backup age cohorts and extracted browser-version sizes through scoped WHM API queries. It does not read mail or backup contents.
- Existing automatic deletion remains limited to explicitly allowlisted caches; backup deletion and installed-browser deletion are **not** enabled without recovery/in-use evidence.
- Acceptance remains quota and real incoming email verification after any future cleanup.

- Daily maintenance prioritizes the largest explicitly allowlisted disposable cache files (bounded scan of 400 and at most 40 deletions), avoiding runs that previously reclaimed only ~30 KB.


## Backup compaction without deleting recovery points

- Historical snapshots contain uncompressed database.sql dumps. A private post-release compactor converts up to 50 completed dumps older than 48 hours to database.sql.gz per deployment, preserving all snapshot directories and other archives.
- It verifies the original SHA-256 hashes and gzip roundtrip against original SQL, atomically updates manifest format 2, and only then removes redundant plaintext. Failures preserve the original.
- Legacy format 1 remains readable. Full MySQL restoration remains a separate acceptance check, and actual quota improvement must be measured in production.

- The WHM daily audit now reads deployment readiness and recursive log sizes, and estimates same-day backup redundancy; only aggregated metadata is printed, no private files or credentials are read.

## Protected backup recovery verification (2026-10-29)

- A new WHM retention planner preserves the latest 3 snapshots, one per calendar day (Europe/Lisbon) for 45 days, one per week to 180 days, one per month to 365 days, then one per quarter, plus the oldest recovery point.
- The protected daily GitHub maintenance workflow performs a **read-only first pass**: checks retained manifest file structure and imports the latest uncompressed SQL backup into an ephemeral MySQL 8 container, without touching the live database or outputting SQL content.
- Permanent deletion remains disabled while the protected daily workflow validates restore readiness, limited to 25 snapshot directories and 200 MiB per run, only after a successful temporary MySQL import. Files are permanently removed only via the existing fixed-target WHM API, with **no unlisted files inside a deleted backup directory**; roster changes abort.
- **Open acceptance:** the initial protected dry run 40962862993 failed before restore due to release-backup.lock being misclassified; the matcher is corrected and requires a fresh successful dry-run; application archives remain untested. Do not claim backups were deleted or production deployment recovered until actually verified.

- After 2026-10-29 retention validation, daily cache deletion has an explicit 95-second limit; timeout is surfaced as incomplete without automatic retries, while protected backup restore diagnostics continue independently.

## Daily WHM backup retention — activation after restore acceptance (2026-10-29)

- Protected first dry run `40964594901` returned `ok=true`, SQL restore `passed`, 172 snapshots / 23 retained, 148 candidates, 0 removed. This establishes SQL recovery on disposable MySQL, not full application archive restore.
- WHM-only protected daily maintenance now requests `--apply` after fresh manifest checks and SQL import. A run deletes at most 25 redundant release directories / 200 MiB; retains three latest, daily 45d, weekly 180d, monthly 365d, quarterly older, and oldest. Any unexpected file in a directory blocks deletion. Operations fail closed on unexpected roster changes.
- Deletion is irreversible. Actual quota decrease and remaining snapshots must be verified from the live job log before claiming recovery; avoid replaying uncertain deletions.

## Maintenance rule

Every relevant PR that changes one of the states above must update this file in
the same PR. Do not place passwords, tokens, OTPs, private invoice contents or
guest personal data here.
