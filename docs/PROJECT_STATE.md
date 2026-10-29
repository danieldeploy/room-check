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

- Protected disk audit also invokes the existing WHM deployment `status` read-only command to diagnose `repository_not_deployable`; status failure never broadens or changes cleanup permissions.

## Maintenance rule

Every relevant PR that changes one of the states above must update this file in
the same PR. Do not place passwords, tokens, OTPs, private invoice contents or
guest personal data here.
