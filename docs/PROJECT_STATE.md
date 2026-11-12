# Management Hub — current project state

## Cross-automation exception alerts — 2026-11-07

The owner requires WhatsApp alerts for detected unexpected exceptions, execution
errors and blocked/unverified completion across all existing and future project
automations. The durable rule is in `AGENTS.md` and
`docs/decisions/ADR-005-automation-exception-alerts.md`.

Status: **Planned** for universal runtime coverage; this change records the rule
only. Existing WHM/invoice notifications remain unchanged. The latest confirmed
WHM template is `whm_storage_alert_v1`, with server-specific fixed wording and
three parameters. A separate general automation template is recommended, but
has not been submitted, approved or enabled by this change. No message is sent.

Future implementation must inventory module coverage, preserve approved retry,
deduplication and escalation rules, and verify detection through actual delivery.
Meta acceptance alone is not proof of handset delivery. Do not report every
automation as covered merely because the requirement has been documented.

## Owner-authorized log cleanup — 2026-11-06 (prepared)

The owner explicitly requested execution of all proven-safe cleanup, rather
than another status-only verification. A one-off CLI helper in the existing
guarded release profiles plaintext logs locally for origin/date/severity and
losslessly compacts only single-link, same-owner plaintext logs inactive for
at least 365 days. This is a conservative inactivity gate for compaction, not
an expiry/deletion policy: every historical byte, including fatal incidents,
survives in a private gzip with recovery metadata before the redundant source
is removed. Active/recent logs, compressed logs, links, mail and application
files remain untouched. The main 110.8 MiB log may remain ineligible.

The new fixed private marker/report prevents repeat or ambiguous operations.
The old full-audit marker/report and daily cursors are preserved. The existing
one-off WHM backup/npm workflow reads a strictly sanitized result, rechecks
storage, and runs the already-approved restore-gated backup and exact NPM
cache cleanup. No new cron, service, credential, recipient or threshold.

Acceptance: PHP roundtrip, active/symlink preservation, owner-only archive,
private manifest, idempotence and uncertain receipt tests; strict Python report
projection; existing backup/NPM regression checks; mandatory validate/windows-agent,
then installed SHA, private recovery/compaction receipt and actual quota.
Integrated/deployed/deleted byte counts remain unconfirmed until live results.

## Monthly invoice archive folder (2026-11-06)

- Owner request: preserve the accounting root/year/Portuguese month structure,
  replacing the final `online` folder with lowercase `pdf` for invoice uploads.
- The shared Drive layout now targets `pdf`; Booking, Hostelworld and Cloudbeds
  keep the existing collection, issue-date classification and TOConline delivery.
  Airbnb CSV/export-month behavior and TOConline exclusion remain unchanged.
- Existing monthly folders must be renamed in place, retaining Drive IDs and all
  documents. Pending deliveries retain `drive_parent`; new cache entries discover
  the renamed folder. No database reset, document reimport or email resend.
- Regression coverage exercises reuse with a historical `online` cache entry,
  month aliases and persistent folder/document identity across upload retries.
- Prepared in this change. CI, deployment and the live Drive rename/readback
  remain separate acceptance checks. Sibling `taloes` folders remain untouched.
  See `docs/invoices-multiplatform.md` for the transition contract.

## Daily WHM log verification (2026-11-05)

Historical full scan is confirmed: production `8342de8` / deployment 384;
protected audit run 41940619898 succeeded with 13/13 logs fully streamed,
118.19 MiB on disk, 151.33 MiB expanded and 696351 chunks read. The 51 fatal
and four parse-error occurrences are historical totals, not dated current Hub
incidents. No logs were deleted. The completed one-off release hook is removed;
its private report and marker remain intact.

The reviewed daily implementation reuses the existing five-minute ZKAccess
cron before DB/Drive work, with its own file lock and isolated failures. The
first pass establishes explicit byte-position baselines; subsequent Lisbon
calendar days read appended/new content only. Partial passes resume on the
next existing tick. No new cron, daemon, endpoint or credential is introduced.
The existing protected daily maintenance workflow verifies the installed
scanner hash, reads only allowlisted aggregates and includes them in its
private TXT with exact readback verification. Missing/stale/partial content
becomes maintenance_issue and a failed workflow, never a silent healthy result.
New active fatal/parse/HTTP 5xx errors have a separate log_errors incident;
archived errors are reported separately. Existing quota priority is preserved.

Local regression checks cover baseline, append, rename/truncate, gzip,
idempotence, bounded continuation, privacy, reader validation and failure TXT.
Production acceptance succeeded: PR #331, commit `51a438c`, deployment 385;
run 41946097000 verified 13-log baseline produced by the existing cron at
2026-11-05 01:10:02 UTC and exact private TXT readback. Latest sampled quota
72.17%; two eligible duplicate backups removed after successful MySQL restore.
The next calendar day's incremental pass is now verified by protected run
[42207376884](https://github.com/danieldeploy/room-check/actions/runs/42207376884)
on 2026-11-06. The installed and last-deployed commit were both `2a97a67`
(deployment 386). The existing cron completed at 00:00:03 UTC, covering from
2026-11-05 01:10:02 UTC: 14 files, 10 unchanged, 3 reset/replaced files,
7,564,251 bytes / 55,359 chunks read, zero pending tail bytes. Active severity
counts were empty; archived counts included 12 HTTP 5xx occurrences, which
are not new active incidents. This verifies this observed day, not future runs.

The scheduled GitHub bridge started at 11:03 UTC, later than its nominal
03:17 UTC schedule. All maintenance steps passed. Quota was 72.97% in the
verified TXT (approximately 4,378 / 6,000 MiB used); logs remained 118.5 MiB,
including the approximately 110.8 MiB recently modified error log. Browser
version inventory was empty. Temporary MySQL restore passed; zero backups or
caches were deleted during this run. The private TXT was written and read back
exactly, with no incident alert and no new root directories.

Log reduction remains **pending**, separately from daily verification. The
historical report supplies severity/reason counts but no dated origin breakdown
for its 51 fatal occurrences. Those totals must not be assigned to the Hub or
treated as current faults. Before reducing the active error log, establish its
writer/origin and resolved-versus-needed history, preserve recoverable evidence,
and follow the existing no-blind-truncation/no-fixed-retention decision. No
additional log deletion or rotation was performed during this acceptance check.

The Meta API probe could not discover the WABA (`waba_id_unavailable`). This
was not proof of pending template approval: the existing authorized Meta UI
was refreshed and confirmed `whm_storage_alert_v1` active, Utility, Portuguese
(POR), with exactly three parameters. No new Meta template or recipient.
A reviewed one-time activation test now uses the existing manager and client,
records an exclusive pre-send receipt and enables WHM alerts only after Meta
accepts the dedicated test. A configured explicit false still disables alerts.
Uncertain sends remain disabled and are never automatically repeated. The
normal dispatcher moves from the unverified reminders entrypoint to the
already verified ZKAccess tick, under a separate MySQL lock, with a private
heartbeat. The protected daily TXT verifies both activation and fresh dispatch.
Production activation and ordinary dispatch heartbeat are now verified by run
42207376884: activation `meta_accepted`, dispatch `healthy_no_message`,
checked at 2026-11-06 11:05:01 UTC. No routine WhatsApp was sent. The machine
report still records handset delivery as not confirmed; it cannot independently
establish delivery to the phone. Never reset the one-time activation receipt.
See `docs/CPANEL_DISK_MAINTENANCE.md` for timing, limits and recovery.

Last reviewed: 2026-11-06 (Europe/Lisbon)

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

## Abas Chrome criadas por automações — regra transversal (2026-11-04)

- Owner decision: every current and future browser automation must register
  tabs it **creates** and close only those tabs after its task finishes safely.
  No always-on watchdog, cron, extra Windows service or periodic full-browser
  scan. Manual/reused tabs, active operations, unsaved drafts and pages awaiting
  human verification are preserved; persistent Chrome profiles, cookies and
  sessions must remain intact.
- The existing AccesControl invoice runner already closes one newly created
  controlled tab in normal cleanup, but not every possible later created tab.
  This change introduces per-task ownership covering both initial and later
  controlled Booking tab creation, with tests and an explicit human-challenge
  exception. Other new automation entrypoints must adopt the contract from
  `AGENTS.md`.
- Status in this PR: **prepared; CI, merge, installation in the Windows
  AccesControl app and live acceptance remain to be verified separately**.
  See `docs/invoices-multiplatform.md` for behavior and acceptance.

## ChatGPT shared Chrome/Explorer cleanup on AccesControl (2026-11-04)

- The browser-tab ownership rule was integrated via PR #326 (commit
  `17fba71d93ae5b3102e48ef90281a7758d552ea7`); the Windows agent files
  were installed and checked with a real temporary Chrome tab, preserving
  existing tabs and running Chrome sessions. It adds no background watcher.
- A reusable **local helper for ad-hoc chats** is installed at
  `%LOCALAPPDATA%\\ChatGPTAutomation\\ManagedUI\\managed-ui.mjs`, with
  `explorer-ownership.ps1`, a README and a prepared global-instructions text.
  It opens/registers exact task-owned Chrome targets and Explorer HWNDs, and
  attempts safe closure only when `finish` is explicitly invoked. Tests
  confirmed created Chrome/Explorer windows close while prior windows remain;
  a synthetic filled textarea was preserved pending safe manual completion.
- This helper does **not** hook all ChatGPT plugins, cloud browsers or commands
  that open windows without it. Unowned/manual windows are deliberately untouched.
  ChatGPT global Custom Instructions cannot be edited through the currently
  connected account tools; the user must save the prepared text in ChatGPT
  Settings -> Personalization for cross-conversation guidance. This remains
  guidance, not universal tool-level enforcement.
- The ad-hoc helper has no cPanel deployment component; this PR only makes
  its usage/limitations durable in the Management Hub repository.

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

2026-11-11 live paired-agent inspection confirms the scheduled Windows task is
installed, enabled and succeeding. The authenticated Hub manifest records daily
verified Drive delivery for November 1–10, most recently November 10 at 04:05:14
Europe/Lisbon (dump received at 04:02:06). Windows daily archive files through
November 10 are already removed. These receipts supersede the old October
cPanel Git/cron deployment blockers; they do not replace current remote metadata
or restore acceptance. The schedule is enabled at 04:00 Europe/Lisbon.

Owner-authorized policy in this change: **three days total on Drive**, retaining
the existing minimum-two verified recovery safeguard during outages. Remove
redundant local daily archive bytes from both Windows and cPanel as soon as
Drive completion is verified; preserve incomplete/pending transfers. The Hub
requires a fresh Drive ID/parent/size/checksum match before removing local
ciphertext. Keep encrypted recovery keys and the manifest until remote expiry:
they are needed to decrypt the Drive archive and are not redundant backup copies.
Windows removes matching uploaded archives on the next existing five-minute tick,
without the previous extra-day delay. No new scheduler or endpoint is added.
The dedicated cPanel cron continues to be provisioned/verified by guarded deploy.

Read-only Drive metadata also confirms the November 8–10 archive IDs in the
expected backup folder. A restore of the separate final November 10 SQL backup
completed into a disposable Windows database: all 100 tables imported and the
temporary database was dropped. This is not a fresh restore from an encrypted
daily Drive object. The original database was not replaced.

Status: policy/cleanup change **prepared**, CI, installed commit, cron inspection,
three-day remote retention and local cleanup readback remain to be confirmed.
See `docs/flows/zkaccess-backup.md` for recovery and acceptance.


## ZKAccess door-name event consistency — 2026-11-11

Status: operational repair **Verified** for door 2; native refresh requested for
all four Welcome main doors. The installed vendor callback was invoked at
18:13:59 Europe/Lisbon after checking the intended door identities and unchanged
configuration. It emitted the native collector/monitor refresh notifications,
without re-saving door fields, changing credentials or opening doors remotely.
The owner physically tested door 2; the new 18:23:46 event stores the correct
`WLC Main 1st Floor` label. Other doors await a new real-event acceptance check.
The cause of the earlier missing/effective refresh remains unproven; do not
present the prior script, SQL or SDK as the confirmed cause.

`docs/ZKACCESS_CONFIGURATION.md` records observed state, installed-code findings,
the actual native recovery phase, preservation checks and future implementation
acceptance. This documentation change introduces no runtime code, daemon,
scheduler, vendor binary patch or automatic PIN generation. Existing historical
labels remain intact; Cloudbeds API/physical PIN gates remain separate.

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

## Read-only hosting recovery diagnostics (2026-10-30)

- A scoped diagnosis verifies remaining modified tracked metadata and unexpected .github entries in the cPanel staging clone against the installed SHA before any further repair. Unknown server filenames and contents are never printed.
- A separate metadata-only helper can check recent mailbox file timestamps without opening emails. It has no deletion capability and requires live delivery verification.
- The existing private quarantine containing previous recovered clone files must be preserved. Deployment remains blocked until the dirty working tree is reconciled.

## Maintenance rule

Every relevant PR that changes one of the states above must update this file in
the same PR. Do not place passwords, tokens, OTPs, private invoice contents or
guest personal data here.

## cPanel Git repository readiness incident (2026-10-29)

The ZKTeco independent backup archival PR #290 was reviewed, passed the
required `validate` and `windows-agent` checks, and merged as
`3a416b54a56cd8c5dbfdaa19c8ac7ebf7dbcf6da`. The guarded WHM preflight
stopped **before** publishing the new endpoint or provisioning its cron:
`repository_not_deployable`. The immediately previous production commit had
already failed the same preflight. WHM's protected status showed
`deployable=false`, `repository_busy=false`, with the existing installed
commit still `ee8c516cb8393aa8b292005d842dca984c03aac0` at 22:24Z.

A dedicated protected **read-only** diagnostic now checks the cPanel Git clone's
root and known source directories through WHM Fileman metadata, comparing
bounded names against the public checkout. It does not print unknown filenames,
file contents, or credentials, and cannot modify the clone. Its results must
be reviewed before any repair or deployment retry. The ZKTeco replacement cron
is **not** yet confirmed installed; old functioning backups remain untouched.

## cPanel staging-clone tracked repair (2026-10-30)

- Protected CI diagnostic from commit 116280d identified 578 tracked files present, two changed-size tracked files (`.github/workflows/ci.yml`, `docs/PROJECT_STATE.md`), two extra regular files in the `.github` area, and no known Git lockfiles. The nine earlier untracked files were already preserved in a separate private quarantine; do not replay that recovery.
- Prepared a one-time exact repair in `deploy/cpanel_git_tracked_repair.py` (before regular WHM deployment). Only if the installed commit is still ee8c516, the worktree inventory remains exactly as previously diagnosed, and the protected GitHub HEAD is fast-forward: preserve the two changed tracked files and two extras in a new private 0700 quarantine, restore the two tracked files byte-for-byte from GitHub's blob at the **installed** commit and verify Git deployable=true. No changes to `.git`, `public_html`, mail, backups, credentials or databases.
- A partial repair or uncertain WHM response blocks repeated writes until read-only inspection. Restoration is not yet confirmed on the server, and the normal deploy continues to require its original backup, exact commit and health gates.

## cPanel quota recovery and one-off disposable cache (2026-10-30)

- Six owner-authorized, protected WHM backup-retention batches successfully removed **149** redundant release snapshots in total. Latest verified quota was **82.9% of 6 GB**; **24** policy-retained release snapshots remained. Each deletion batch passed the latest SQL restore import in disposable MySQL 8 and respected the existing 25-directories/200 MiB limits. This does not prove full application archive restoration or email delivery.
- Normal scheduled cache cleanup still respects the **90%** quota trigger. Because quota is now below that trigger, disposable caches are not automatically removed.
- New **one-off** protected workflow .github/workflows/cpanel-cache-reclaim-once.yml runs after its own production-branch integration (or deliberate manual dispatch) and only bypasses the quota threshold, never the existing exact-file security checks. It reuses the tested 14-day age, existing allowlisted cache paths, metadata recheck, 40-files/200 MiB budget, protected WHM secret and concurrency group. Installed Puppeteer browsers, mail, databases, logs, active application, and retained backups are never eligible.
- One-off cache result, actual before/after quota, completion or timeout must be observed from its GitHub Actions log. Do not claim cache deleted until the log confirms it. If a run times out, inspect actual state before any retry. Do not expand this cache scope to installed browsers or logs without separate evidence.

## Finalizar cache NPM e auditar logs/Puppeteer sem aceder ao email (2026-10-30)

- O último inventário comprovado depois do segundo lote de cache indicou quota **82.0% de 6 GB**, NPM **9.1 MiB**, logs **150.8 MiB** e Puppeteer **704.8 MiB**. O segundo lote de cache excedeu 780 segundos; parte da NPM foi apagada, mas não foi publicado um contador completo. Não repetir esse lote sem alterar a pesquisa.
- O workflow de uso pontual `.github/workflows/cpanel-npm-finish.yml` utiliza `deploy/cpanel_npm_finisher.py` para fazer **uma única pesquisa recursiva** da cache específica `.npm/_cacache` via Fileman API 2 (legado só para leitura), em vez de centenas de chamadas de `list_files`. Só `--apply` elimina ficheiros individuais de cache, nunca diretórios. Reutiliza validação de caminho, tamanho e mtime imediatamente antes de cada eliminação, limite de **40 ficheiros/200 MiB**, idade mínima **14 dias** e ambiente WHM protegido. A rotina automática de 90% permanece intacta. O estado final depende dos resultados reais do GitHub Actions.
- A leitura de `~/logs` fornece apenas quantidades, tamanhos, classes genéricas e antiguidade dos três maiores ficheiros, **sem ler o conteúdo nem divulgar nomes/domínios**. Esta alteração não elimina logs.
- As três instalações Puppeteer identificadas são `chrome 153.0.8010.36` (391.1 MiB), `headless 153.0.8010.36` (259.7 MiB) e `headless 148.0.7778.97` (52.0 MiB); não é prova de uso ou inutilidade. Não remover browsers instalados sem provar dependências dos processos e testar o fluxo relevante.
- Por decisão expressa do proprietário, manter emails intocados, incluindo limpeza e testes de entrega, até uma fase posterior.

## Resultado verificado da limpeza NPM e revisão de logs/Puppeteer (2026-10-30)

- O workflow protegido [41129358547](https://github.com/danieldeploy/room-check/actions/runs/41129358547), integrado através do PR #303, executou **oito lotes seriais concluídos com sucesso**. Eliminou 290 ficheiros regulares com mais de 14 dias de `.npm/_cacache` (~4.826 MiB). O último lote confirmou `files=0`, `eligible_files=0` e `total_mb=0`. Não houve eliminação de diretórios. A pasta `.npm` ainda ocupa aproximadamente 3.8 MiB por elementos fora dessa cache; esses outros elementos não foram apagados.
- A quota no fim era ~82.06% segundo a UAPI, com 4920.6 MiB de 6000 MiB usados segundo a auditoria DiskUsage. A quota pode variar por alterações de outros processos. Os dois indicadores não são perfeitamente idênticos.
- Os **11 logs** analisados totalizam 150.68 MiB. A classificação de metadados (sem ler conteúdo ou imprimir nomes/domínios) identificou 3 ficheiros de tipo erro (~144.78 MiB), incluindo o maior (110.67 MiB, atualizado há 1 dia) e outro de 33.94 MiB (sem alterações há 372 dias). Existem 4 arquivos comprimidos (~5.27 MiB) e 4 outros logs (~0.63 MiB). **Nenhum log foi eliminado.** O log antigo é candidato a avaliação de retenção; não remover o recente sem diagnóstico de rotação/uso.
- A pasta Puppeteer continua com ~704.8 MiB: Chrome 153.0.8010.36 (391.1 MiB), Chrome Headless Shell 153.0.8010.36 (259.7 MiB), Chrome Headless Shell 148.0.7778.97 (52.0 MiB). O projeto fixa Puppeteer 25.11.0/Chrome 153; `headless: true` usa Chrome completo nas versões modernas. Potencial de poupança das duas shells ~311.7 MiB **não é autorização para eliminação sem verificação de processos/dependências**. Nenhuma instalação de browser foi removida.
- Por instrução do proprietário, emails e testes reais de receção ficam fora desta intervenção, para uma fase posterior.

## Limpeza adicional de logs antigos — execução isolada (31/10/2026)

- A manutenção diária protegida [41189983019](https://github.com/danieldeploy/room-check/actions/runs/41189983019) removeu mais 1 backup redundante (~6.98 MB), verificou restauro SQL (passed) e registou quota 82.5% de 6 GB, sem limpeza de emails. O conjunto de 26 backups permaneceu dentro da política, com 25 protegidos.
- Auditoria recente de `~/logs`: ~151 MiB, com um log de erros ainda ativo (~110.7 MiB) e outro ficheiro de erros não alterado há aproximadamente 373 dias (~33.9 MiB). Os logs comprimidos encontrados não cumprem a retenção automática atual.
- A pedido do proprietário, o novo workflow isolado `.github/workflows/cpanel-old-log-reclaim.yml` só pode apagar **um ficheiro de log de erros** diretamente em `~/logs`, regular (não symlink), não comprimido, com **mais de 365 dias sem alteração** e entre **20 e 60 MiB**, após reconfirmar pesquisa, metadata exata e resultado de exclusão. Nenhum nome de domínio/conteúdo de log é incluído nos resultados. Uma candidatura ambígua bloqueia tudo. Os testes obrigatórios de CI devem passar primeiro. Este workflow não altera a retenção diária nem autoriza excluir logs recentes.
- A instalação Chrome 153 (391.1 MiB) e as duas Chrome Headless Shell 153 (259.7 MiB) e 148 (52.0 MiB) continuam preservadas: o runner permite executablePath configurado na runtime e a ausência de referências estáticas não comprova ausência de uso no alojamento. Não apagar sem confirmar a execução real.
- Os emails e os testes de receção ficam para uma fase posterior por instrução do proprietário.
- Estado de aceitação deste trabalho: script e workflow preparados em PR; **eliminação real e quota pós-limpeza só se confirmam pelos logs da execução protegida**.

## Bloqueio de remoção do log muito antigo — 31/10/2026

- O PR #305 integrou a remoção isolada de log de erro com >=365 dias. A execução protegida [41201812000](https://github.com/danieldeploy/room-check/actions/runs/41201812000) identificou um único candidato de 33.94 MiB, mas parou com `uapi_failed` durante verificação ou eliminação. A auditoria posterior confirmou que **o log continua presente** e a quota permanece em ~82.5%; não houve recuperação deste ficheiro.
- Por segurança, **não repetir automaticamente** a operação falhada, nem utilizar métodos alternativos sem conhecer a causa. A nova alteração do mesmo workflow substitui temporariamente o passo destrutivo por `cpanel_log_access_probe.py`, apenas de leitura. Compara acesso UAPI a metadata por caminho relativo/absoluto, API2 statfiles e list_files, relatando somente sucesso/erro, permissões agregadas e correspondência de tamanho/mtime, nunca nomes, domínios, conteúdo dos logs ou credenciais.
- A conclusão depende da execução protegida e da verificação de permissões. O log recente de 110.7 MiB, browsers e emails continuam protegidos.

## Correção de verificação para remoção de log muito antigo (31/10/2026)

- A execução protegida e exclusivamente de leitura [41202204788](https://github.com/danieldeploy/room-check/actions/runs/41202204788) confirmou que **Fileman/get_file_information (UAPI)** recusa tanto o caminho relativo como absoluto em `~/logs`. **Fileman/statfiles (API2)** funciona, reconhece exatamente um ficheiro regular de 33.94 MiB, titular `welcome` e permissão de escrita. `Fileman/list_files` (UAPI) também consegue listar a entrada.
- A rotina pontual de eliminação passa a obter a confirmação de `type`, `user`, `mode` e `mtime` da API2 `statfiles`, sem executar operações de escrita por API2. Continua a exigir tamanho/mtime exatos em duas pesquisas independentes, único ficheiro de erro plano diretamente em `~/logs`, mínimo 365 dias de idade, tamanho 20–60 MiB e confirmação de ausência depois de uma só chamada de `Fileman/delete_file` UAPI.
- O PR reabilita o passo destrutivo sob testes/ambiente de produção protegidos; **o resultado ainda depende da execução real**. Caso a API de eliminação também recuse, nenhum método alternativo deverá ser usado sem auditoria posterior. A restrição de não tocar em emails, browser ou log ativo mantém-se.

## Verificação de dependências de browsers Puppeteer Linux — 31/10/2026

- A eliminação do log muito antigo foi **confirmada** pela execução protegida [41202585352](https://github.com/danieldeploy/room-check/actions/runs/41202585352): uma cópia de 33.94 MiB eliminada, zero candidatos restantes e os logs desceram de ~150.9 para ~117 MiB, com quota UAPI 82.74% -> 82.18%. Nenhuma mensagem de email foi aberta/alterada.
- O inventário Linux contém Chrome 153 (391.1 MiB), Headless Shell 153 (259.7 MiB) e Headless Shell 148 (52.0 MiB). Puppeteer 25.11.0 no repositório usa `headless: true` no runner, que seleciona o Chrome completo, mas também aceita `runtime.executablePath` configurado e outros programas do alojamento podem usar shells diretamente. Ausência de referência no repositório não comprova falta de uso.
- `.github/workflows/cpanel-headless-audit.yml` executa apenas um diagnóstico protegido sem escrita: inventário de versões/execuções e seus atributos de acesso (não fiáveis sob relatime/noatime), mais contagem de tarefas cron com referências explícitas a Shell/Chrome/Node/Puppeteer, sem imprimir comandos, domínios, arquivos privados ou credenciais. O inventário de cron **não** comprova ausência de executáveis usados por processos interativos, jobs encadeados ou variáveis de ambiente.
- **Nenhuma remoção de browsers foi autorizada pela lógica do diagnóstico.** Antes de apagar binários instalados, é necessário obter prova positiva suficiente (configuração e processos ou comportamento funcional, existência de binário alternativo testado) e plano de recuperação; não executar uma eliminação recursiva por suposição. Os emails seguem fora de âmbito.

## Resultado final da auditoria de dependências Headless Shell (31/10/2026)

- O workflow protegido [41203927397](https://github.com/danieldeploy/room-check/actions/runs/41203927397) terminou com sucesso, exclusivamente em leitura.
- Inventário Linux: Chrome completo **153.0.8010.36** (303 ficheiros, **391.08 MiB**); Headless Shell **148.0.7778.97** (3 ficheiros, **52.04 MiB**, último atime registado há **17 dias**); Headless Shell **153.0.8010.36** (287 ficheiros, **259.74 MiB**, atime há **53 dias**).
- Cron da conta: **5 tarefas**; **0** referências explícitas a `chrome-headless-shell`, `puppeteer` ou às versões identificadas; **1** tarefa contém execução Node e **5** são invocações de scripts/wrappers, pelo que as dependências indiretas e o `executablePath` configurado não estão comprovados.
- O servidor SSH recusou ligação pela porta 22 (timeout); processos Linux em execução e ambientes de runtime não puderam ser inspecionados. `atime` não é prova fidedigna de utilização ou falta de utilização por causa das opções relatime/noatime.
- **Decisão de segurança:** nenhuma das duas instalações Headless Shell foi eliminada. Potencial de libertação: **311.78 MiB**, condicionado a prova positiva de não utilização e mecanismo testado de recuperação. A inspeção não atribui autorização de remoção a partir de ausência de referências.
- Auditoria read-only posterior: `~/.cache` ~704.8 MiB, `logs` ~117.1 MiB depois da eliminação confirmada do log antigo, quota DiskUsage ~4934.5/6000 MiB. Emails preservados.

## Verificação adicional pela API WHM protegida (31/10/2026)

- O WHM API token protegido de `management-hub-production` já funciona em `server50.romania-webhosting.com:2087` como revendedor `fazenda`, com inspeção delegada da conta `welcome`. O acesso não depende de SSH e já recuperou backups, cache NPM e um log antigo. Falha SSH na porta 22 **não** equivale a indisponibilidade WHM.
- A auditoria inicial [41203927397](https://github.com/danieldeploy/room-check/actions/runs/41203927397) confirmou cinco tarefas cron, sem referências explícitas a `chrome-headless-shell`, mas uma execução Node.js e scripts intermediários. Falta validar usos indiretos e caminhos `executablePath`.
- O workflow novo, de **leitura apenas**, `.github/workflows/cpanel-whm-runtime-capabilities.yml` interroga a lista pública de funções WHM API1 (filtrando apenas nomes ligados a processos) e os pontos de entrada que as tarefas cron referenciam, através de `Fileman/get_file_content` com caminhos estritamente limitados à árvore de código da conta. Só produz contagens de padrões Chrome/Puppeteer e disponibilidade de função. Não imprime os scripts, comandos, nomes de ficheiros, segredos nem conteúdo de email. Proíbe entrada em pastas de mail, ficheiros de configuração e travessias de diretórios.
- Esse diagnóstico **não elimina browsers nem serviços** e ainda não constitui prova de que as duas versões Headless Shell são dispensáveis; a conclusão depende da execução real protegida e dos resultados do endpoint WHM. Nenhuma eliminação por suposição.


## Corrigir leitura de cron na auditoria WHM (31/10/2026)

- O primeiro diagnóstico protegido `cpanel-whm-runtime-capabilities.yml` [41205480771](https://github.com/danieldeploy/room-check/actions/runs/41205480771) não fez alterações, mas terminou com `invalid_cron_entry`: a lista API2 Cron inclui linhas de metadata sem campo `command`.
- A correção mantém a rejeição de linhas malformadas, mas ignora as que não têm comando, de acordo com a auditoria de cron já existente. Teste de regressão atualizado. O diagnóstico continua **só de leitura**, sem consultar mail, sem devolver comandos ou ficheiros privados e sem eliminar browsers.
- A prova de utilização de Headless Shell continua pendente até uma execução protegida bem sucedida e revisão dos resultados da API.

## Acionar novamente auditoria WHM corrigida (31/10/2026)

- O PR #311 corrigiu a aceitação de linhas de cron sem campo de comando; testes aprovados. O workflow existente tinha filtro de push apenas para o seu próprio ficheiro, pelo que a correção do script não disparou a auditoria. O filtro passa também a abranger `deploy/cpanel_whm_runtime_capabilities.py`. A execução continua protegida, apenas de leitura, e sem eliminação de browsers, logs ou emails. O resultado real deverá ser confirmado nos logs protegidos.

## Tentar retirada do navegador Headless Shell obsoleto 148 (31/10/2026)

- O utilizador autorizou tentar eliminar as instalações Headless Shell desnecessárias. A versão 148.0.7778.97 (~52 MiB) é anterior ao Puppeteer 25.11.0 do projeto, cujo Chrome completo 153 e Headless Shell 153 foram inventariados. A documentação do Puppeteer confirma que Chrome e Headless Shell são ambos descarregados por defeito na instalação, mesmo quando só é usado Chrome. A origem exata do download no servidor não foi registada.
- A execução protegida [41206221737](https://github.com/danieldeploy/room-check/actions/runs/41206221737) inspecionou cinco scripts cron e encontrou zero referências diretas a Puppeteer/Headless Shell e zero executablePath explícitos, mas os cinco scripts usam includes, e não há acesso à lista de processos Linux via token WHM atual. Logo, a ausência de uso não está matematicamente provada.
- O workflow isolado `.github/workflows/cpanel-retire-headless-148.yml` só elimina **a versão 148** se a pasta contiver a estrutura esperada de três ficheiros regulares (45–60 MiB) e sem symlinks, não tiver atime nas últimas 48 horas, os cron/scripts conhecidos não fizerem referência direta ao Shell, e a configuração runtime JSON do runner (quando presente) não indicar o Headless Shell. Repete preflight imediatamente antes, invoca `Fileman::delete_file` uma única vez, confirma a ausência da versão 148 e a preservação do Chrome completo 153 e Shell 153; mede a quota. Qualquer verificação recusada bloqueia a eliminação. Não lê nem altera emails.
- O Chrome completo 153 e o Shell 153 **não são removidos** por esta operação, por poderem ser necessários à versão atual. O estado real da eliminação e o espaço libertado dependem exclusivamente do log da execução protegida; não declarar sucesso antes da confirmação.

## Confirmação real: removido Headless Shell 148 (31/10/2026, 14:58 UTC)

- Execução protegida [41212568877](https://github.com/danieldeploy/room-check/actions/runs/41212568877): dry run aprovado; preflight antes da remoção identificou exatamente **3 ficheiros**, total **52.04 MiB**, na pasta `.cache/puppeteer/chrome-headless-shell/linux-148.0.7778.97`; 5 scripts cron sem referências explícitas a Shell/executablePath; `invoice-runtime.json` não estava presente na pasta prevista.
- A chamada única `Fileman::delete_file` da API WHM/cPanel removeu efetivamente a pasta antiga; a listagem posterior confirmou ausência. Quota UAPI **82.75% -> 81.88%**. A auditoria DiskUsage após remoção registou **4909.9 / 6000 MiB**, `.cache` **652.8 MiB** e browsers remanescentes Chrome completo 153 (**391.1 MiB**) e Headless Shell 153 (**259.7 MiB**).
- Foi confirmado que o Management Hub continua acessível através da ligação do plugin (`hub_get_profile`, resposta OK). Isto **não é teste completo de execução de automações Puppeteer**; não afirmar que todos os fluxos foram testados.
- **A instalação Headless Shell 153 foi preservada**, pois corresponde à versão atual do Puppeteer e as dependências indiretas, a configuração efetiva de runtime e os processos Linux não puderam ser inteiramente auditados com o token de revendedor. Não remover só porque não consta das cron expressions. Os emails ficaram intocados.
- A Shell 148 é um binário regenerável descarregado pelo Puppeteer, não um backup de dados. Em caso de dependência inesperada, reinstalar explicitamente a build 148 através do instalador oficial de browsers Puppeteer. Não limpar os 259.7 MiB atuais sem teste funcional seguro e via de restauro.

## Verificar dependências indiretas do Headless Shell 153 (31/10/2026)

- Pedido expresso para eliminar **259.74 MiB** da pasta `.cache/puppeteer/chrome-headless-shell/linux-153.0.8010.36` apenas se se comprovar que as automações não dependem dela. A versão anterior 148 (52.04 MiB) já foi removida e a quota observada desceu a 81.88% na execução protegida 41212568877.
- O projeto fixa Puppeteer 25.11.0/Chrome for Testing 153.0.8010.36 e a documentação oficial estabelece que `headless: true` seleciona o Chrome completo; `headless: 'shell'` seleciona a Shell separada. No entanto, `executablePath`, variáveis de ambiente ou scripts incluídos em cron podem alterar a escolha.
- O inventário WHM anterior [41206221737](https://github.com/danieldeploy/room-check/actions/runs/41206221737) examinou cinco scripts chamados pelo cron, sem referências diretas a Shell, mas com includes indiretos em todos. A lista de processos Linux não está acessível através do token do revendedor WHM.
- O novo workflow protegido `.github/workflows/cpanel-headless-153-source-scan.yml` e script `deploy/cpanel_headless_153_source_scan.py` fazem análise de **leitura apenas** às árvores de código PHP/Node da conta, ignorando mail, backups, logs, uploads, imagens, armazenamento de reservas, bibliotecas de terceiros e perfis Chrome. Apenas contagens e indicadores de referência à Shell, `headless: 'shell'`, `PUPPETEER_EXECUTABLE_PATH` e `executablePath` são comunicados. Não mostram código, nomes de ficheiro nem dados privados, não modificam ficheiros e falham fechados em árvores excessivas ou erros.
- **A execução de leitura não autoriza apagar a Shell enquanto a utilização indireta, as configurações e eventuais processos não forem suficientemente verificados.** Caso a evidência seja conclusiva, eliminar apenas a pasta exata 153 com preflight repetido, proteção do Chrome completo, verificação da quota e possibilidade de reinstalação; não tocar nos emails.

## Ajuste de limite da inspeção de fontes Puppeteer (31/10/2026)

- A primeira auditoria protegida de referências indiretas do Headless Shell 153 [41222919465](https://github.com/danieldeploy/room-check/actions/runs/41222919465) foi exclusivamente de leitura e terminou sem eliminação com `code_source_exceeds_limit`: pelo menos um dos ficheiros de código da conta ultrapassava 160 KiB.
- Para concluir a inspeção, ampliou-se somente o orçamento de leitura (até 2 MiB por ficheiro, 32 MiB no total, 850 ficheiros e 260 diretórios). Persistem as exclusões de mail, logs, backups, uploads, reservas, bibliotecas de terceiros e perfis Chrome. A auditoria permanece estritamente de leitura, não autoriza eliminação e falha fechada se esses limites forem ultrapassados.

## Auditoria Shell 153: pasta inacessível pela API (31/10/2026)

- A segunda execução read-only [41223311365](https://github.com/danieldeploy/room-check/actions/runs/41223311365) deixou de atingir o limite de dimensão por ficheiro, mas falhou com `api_request_failed` durante a exploração de uma árvore da conta. Não ocorreu alteração nem eliminação.
- O scanner passa a registar **apenas contagens** de diretórios inacessíveis, separadas por código privado e web, e disponibiliza o resumo parcial antes da auditoria de binários. Caminhos, conteúdo, nomes de ficheiro, e dados confidenciais continuam ausentes do output. Uma inspeção com diretórios inacessíveis fica marcada **incompleta**; não pode justificar eliminação do Headless 153.

## Verificar Jailed Shell no cPanel pela API WHM (2026-10-31)

- O projeto instala `puppeteer@25.11.0` como dependência do `invoice-runner`, com script `install-chrome: puppeteer browsers install chrome`. O download correspondente em `/home/welcome/.cache/puppeteer` coincide com o Chrome 153 do projeto; o processo exato que efetuou o download não foi identificado por um registo inequívoco. A Shell 148 já foi removida e a 153 preservada.
- A falha de SSH na porta 22 observada no Windows (timeout) **não comprova** que Jailed Shell esteja desativado: pode existir uma porta alternativa ou bloqueio de rede/firewall. Verificar em vez de presumir.
- O novo workflow protegido `.github/workflows/cpanel-shell-status-audit.yml` usa exclusivamente leitura via WHM API 1 `accountsummary` e `listaccts` com pesquisa restrita a `welcome`. Classifica somente o campo shell em jailed, normal, disabled, other ou not_verified, sem publicar domínios, contactos, chaves ou segredos. Não altera permissões de acesso ou estados de automação.
- Resultado real apenas após execução protegida bem-sucedida; Jailed Shell ativo não significa necessariamente que a porta 22 esteja acessível externamente ou que uma chave SSH válida esteja configurada.


## Remoção autorizada do Puppeteer Linux obsoleto (31/10/2026)

- O proprietário confirmou a decisão original de setembro: no alojamento partilhado não foi possível executar o Chrome com as dependências e sandbox necessários; as tarefas de browser passaram para o agente Windows AccesControl (PR #72 e #78). O cron PHP no cPanel mantém manutenção, documentos, Drive e notificações, sem executar Chrome em modo Windows.
- Autoriza eliminar **apenas** as instalações Linux Chrome 153 e Headless Shell 153 na cache `/home/welcome/.cache/puppeteer`, mais os módulos npm `puppeteer`, `puppeteer-core` e `@puppeteer/browsers` se a identidade e os conteúdos da instalação privada `/home/welcome/room-check-private/invoice-runner/node_modules` puderem ser validados. Não remover o source code do runner, outras dependências Node, dados, emails, backups, PHP cron, nem o agente Windows.
- Workflow de uso único `.github/workflows/cpanel-linux-puppeteer-cleanup-once.yml` protegido por `management-hub-production`: testes, dry-run, inventário e controlo de referências cron, remoção exata via WHM/cPanel e verificação posterior de quota. A execução pode bloquear caso haja configuração/uso inesperado. Não afirmar remoção antes de confirmar o log do GitHub Actions.


## Limpeza pontual de backups duplicados e cache NPM (31/10/2026)

- Autorização do proprietário para avançar com limpeza de elementos efetivamente dispensáveis, após a remoção confirmada de Chrome/Puppeteer Linux. A auditoria anterior (workflow 41236447456) observou ~38 backups (240 MiB), dos quais 14 pareceram redundantes por dia (~92 MiB), mas esta estimativa não valida a política nem confirma idade, integridade ou elegibilidade.
- A rotina pontual `.github/workflows/cpanel-backup-npm-cleanup-once.yml` reutiliza **sem mudar a política** o módulo `cpanel_backup_retention.py`: manter três backups recentes, o mais antigo, um por dia até 45 dias, um por semana até 180 dias, um por mês até 365 dias e um por trimestre depois, excluir somente duplicados com mais de 48 horas, limite de 25 diretórios/200 MiB. Revalidar estrutura dos manifestos e restaurar o SQL do último backup no serviço MySQL 8 efémero antes de remover qualquer diretório.
- A mesma execução usa `cpanel_npm_finisher.py --apply` apenas para ficheiros regulares `.npm/_cacache` com mais de 14 dias, reconfirmando size/mtime; verifica logs apenas em leitura, **não remove o log ativo de ~110,7 MiB**, nem toca em emails, backups protegidos, aplicação ou Windows.
- Operação protegida pelo ambiente `management-hub-production`, com testes antes da auditoria e inventário de disco depois. Só afirmar apagamentos e quantidades após consultar o log real da execução; devido ao limite de 48 horas, nem todos os duplicados estimados são elegíveis hoje. A restauração integral dos tarballs da aplicação continua não demonstrada.


## WHM: relatórios privados TXT diários e WhatsApp por incidentes (01/11/2026)

- Pedido do proprietário: guardar relatórios de cada execução da manutenção num TXT privado da conta cPanel, **sem envio diário**. Enviar mensagens WhatsApp somente perante alerta ou falha, utilizando o mecanismo e Gerente já configurados no Hub. Continuar a auditoria de crescimento, backups, caches, logs e novas instalações/pastas, sem inspeção de conteúdos de emails ou dados privados.
- `deploy/cpanel_daily_disk_report.py` escreve `/home/welcome/room-check-private/whm-disk-AAAA-MM-DD.txt` com Fileman via POST autorizado pelo segredo protegido; verifica leitura de confirmação. Usa Europe/Lisbon, compara com último TXT e sinaliza >=80%/90%, crescimento >=250MB, logs >=70MB, regressão de Puppeteer Linux e falhas de manutenção. Guarda apenas metadados, contagens e hashes de pastas novas, sem nomes de diretórios desconhecidos nem segredos. Linux process inspection remains unavailable via the WHM reseller token.
- O workflow diário `cpanel-auto-cleanup.yml` chama o gravador após o fluxo existente, mesmo quando uma etapa falha, e passa o resultado das etapas; mantém as garantias de backups e cache. A escrita real só fica confirmada após a primeira execução do job diário protegido já agendado; não declarar o TXT guardado antes da leitura de confirmação no log.
- `WhatsAppDiskHealthNotifier`, chamado por `cron/whatsapp-reminders.php`, reutiliza `WhatsAppCloudClient`, a credencial já protegida e o número do Gerente selecionado em `invoice_notification_settings`. Evita repetição diária do mesmo incidente e não reenvia mensagens de aceitação incerta. Não altera os alertas de faturas e de empregadas.
- **Bloqueio externo antes do WhatsApp real:** criar/aprovar na Meta o template `whm_storage_alert_v1` / `pt_PT` / 3 parâmetros e ativar `whatsapp.disk_alert.enabled` no `config.local.php` privado. O código vem desativado por defeito; não usar templates de outros módulos. A verificação em produção só fica concluída após aprovação Meta, versão PHP instalada confirmada e envio de alerta controlado aceite pelo fornecedor.
- Ver `docs/CPANEL_DISK_MAINTENANCE.md` para escopo, template, política, fluxo e limitações. Não inventar destinatários, nem expor relatórios privados pela web.


## Consulta pontual da aprovacao Meta do template WHM (01/11/2026)

- O proprietario autorizou confirmar no Meta o estado de `whm_storage_alert_v1` (locale `pt_PT`) e realizar um teste para o Gerente ja configurado **somente se o template estiver aprovado e com o contrato correto de tres parametros**.
- O workflow protegido `.github/workflows/whm-meta-template-probe-once.yml` so consulta o estado via Graph API e nao faz envios. Lê temporariamente os dados WhatsApp ja protegidos em `room-check-private/whatsapp-secrets.json` no runner protegido usando a API WHM existente, sem os imprimir, gravar em logs ou colocar no repositorio. Valida a WABA contra o telefone emissor e verifica estado, categoria UTILITY e tres placeholders. Apenas estados sanitizados sao registados.
- Nao interpretar `NOT_FOUND`, `UNVERIFIED` ou falta de permissao Graph como `REJECTED`. Um template apenas proposto em documentacao nao significa que foi submetido a aprovacao Meta.
- Se `APPROVED` e compativel, um teste real isolado exige confirmar o destinatario Gerente configurado, fazer um unico envio com identificacao clara de TESTE e verificar aceite Meta; nunca confundir aceite com entregue nem disparar alertas falsos de incidentes. Se nao aprovado, nao enviar.


## WHM/WhatsApp: alertas progressivos 90/95/98/100 (01/11/2026)

- Instrução explícita do proprietário para submeter o template já revisto `whm_storage_alert_v1` na conta profissional WhatsApp Business **Active Lines Unip. Lda.** (não na conta de teste/pessoal), categoria Utility, idioma Português de Portugal (`pt_PT`), com exatamente 3 parâmetros e o texto constante de `docs/CPANEL_DISK_MAINTENANCE.md`.
- Os alertas WhatsApp de **quota de disco** passam a disparar apenas nos patamares de ocupação **90%, 95%, 98% e 100%**. Retirado o patamar de 80%. Cada episódio (da primeira medição >=90% até uma medição <90%) emite no máximo uma mensagem por patamar atingido; a descida de ocupação não reenvia patamares inferiores. Um salto para 98% num só relatório manda um único alerta de 98% e aguarda 100% para o próximo. Os incidentes por crescimento anormal, logs, browsers e falhas continuam como antes.
- `deploy/cpanel_daily_disk_report.py` classifica o nível mais elevado observado por dia e regista-o no TXT privado; `src/Notifications/WhatsAppDiskHealthNotifier.php` conserva o máximo já notificado em `whm-disk-whatsapp-state.json` (0600). Compatível com estado anterior `quota90`, preservando não repetição após o deploy. A quota acima de 100% é apresentada como 100% ao notificador; valores reais de MB permanecem no relatório.
- O destinatário mantém-se o Gerente já selecionado no Hub; não criar novos números ou credenciais. O template Meta continua desativado no servidor até aprovação efetiva e teste real controlado. **Submissão e aprovação precisam de verificação independente no WhatsApp Manager; a presença apenas em código não comprova existência na Meta.**
- Os testes de limites, subida, descida, recuperação abaixo de 90%, migração de estado e envio Meta ambíguo foram adicionados. Cumprir `validate` + `windows-agent`, PR e deploy; distinguir o código integrado, instalado e a entrega de mensagens real.


## Meta WhatsApp - template WHM submetido (01/11/2026)

- O proprietario aprovou o texto do template e autorizou a submissao. No WhatsApp Manager da conta profissional **Active Lines Unip. Lda.**, o template `whm_storage_alert_v1`, idioma Portugues (POR, `pt_PT`), categoria `Utility`, 3 parametros (`{{1}}`, `{{2}}`, `{{3}}`) e texto exato em `docs/CPANEL_DISK_MAINTENANCE.md` foi submetido para revisao em 01/11/2026. A Meta exibiu `Your message template has been submitted for review`; a lista confirmou estado **In review**, ainda nao aprovado. Nenhum WhatsApp de teste foi enviado.
- **Risco de classificacao:** antes da submissao a Meta mostrou `Category does not match`, recomendou Marketing e avisou que o template selecionado como Utility poderia ser rejeitado. O pedido foi submetido mantendo Utility, sem trocar categoria/custos. Se rejeitado, rever fundamento e escolher correcao/apelo apropriado antes de re-submeter; nao reenviar automaticamente nem assumir que foi aprovado.
- Os limiares 90/95/98/100 estao implementados na producao via PR #323, commit `03ee944224a85e485fd4d1e8c14155ccb5cb9e8f` e workflow CI `41309448293` (job deploy succeeded, deploy_id 377). O envio permanece desativado por predefinicao ate aprovacao da Meta, ativacao segura de `whatsapp.disk_alert.enabled` no `config.local.php` privado e teste real controlado com Gerente configurado. A verificacao funcional da entrega WhatsApp permanece pendente. 


## Inspecao de conteudo dos logs WHM (04/11/2026)

- O proprietario pediu analise do conteudo dos ~118 MiB em `/home/welcome/logs`, com foco no ficheiro de erros ~110,7 MiB; **nao autorizou eliminacao cega, prazo de 14 dias, truncamento, alteracao de rotacao nem exposicao de conteudos**. Preservar evidencias historicas enquanto problemas do Management Hub puderem ser retomados apos semanas de inatividade.
- A rotina diaria existente (`cpanel-auto-cleanup.yml`, `cpanel_daily_disk_report.py`) **ja mede e compara os tamanhos dos logs** e sinaliza crescimento >=70 MiB: nao adicionar outro cron nem apagar logs automaticamente. A analise de conteudo e pontual, com workflow protegido `cpanel-log-content-audit-once.yml` e `deploy/cpanel_log_contents_analysis_once.py`.
- Usa somente WHM UAPI/LogManager em leitura para contar classes conhecidas de erros nas ultimas linhas de cada PHP-FPM/docroot/homedir error log de dominios da conta `welcome`, e UAPI Fileman somente para logs de texto muito pequenos (ate 64 KiB). Imprime apenas contagens agregadas, tamanhos, id ordinal por tamanho, tipo e idade da ultima modificacao. **Nunca** imprime as linhas ou nomes reais, IP, dominios, emails, caminhos nem credenciais; nenhum conteudo vai para o repository ou GitHub artifact.
- Por limite de resposta de API (1 MiB) e acesso de revendedor sem shell, nao declarar analisado o conteudo inteiro do log de ~110,7 MiB: os seus dados historicos so ficam classificados se estiverem cobertos pelas linhas finais da UAPI LogManager, que e uma amostra. Arquivos comprimidos ficam preservados e sem leitura de conteudo nesta fase.
- Acoes futuras como compactar, eliminar ou alterar configuracao de rotacao dependem da utilidade constatada, da origem concreta dos erros, de uma forma de conservar o historico e da validacao do mecanismo de escrita ativo; nenhuma destas modificacoes faz parte desta auditoria.


## Leitura local integral dos logs da conta welcome (04/11/2026)

- A primeira auditoria protegida `cpanel-log-content-audit-once.yml`, PR #325, foi executada **sem apagamentos**: 13 ficheiros, 118,01 MiB, dos quais 110,78 MiB num log de erros modificado no próprio dia. 7 arquivos comprimidos, 1 outro log de erros antigo (~0,18 MiB, 2339 dias) e outros pequenos registos. A UAPI `LogManager/view_error_log` não devolveu conteudo PHP para os 2 dominios (zero linhas de 6 tentativas); a API de leitura de ficheiros não permite transferir com segurança os 110,78 MiB para o runner. Só os metadados ficaram confirmados.
- Para cumprir a inspecao do **conteudo integral** sem descarregar dados privados, foi desenvolvido `cron/whm-log-audit-once.php` como script PHP local **sem acesso HTTP**, instalado em `/home/welcome/room-check-private/cron` pelo deploy normal. O script percorre no máximo 150 ficheiros regulares da pasta `/home/welcome/logs`, até 250 MiB de ficheiros e 450 MiB de dados expandidos. Usa leitura por linhas, e descompressão `.gz` se disponível, com memória limitada. Não segue symlinks, não altera logs, crons, backups, emails ou configurações.
- A execução é acionada **uma só vez** após a rotina privada existente `cron/whatsapp-reminders.php`, apenas com HOME igual a `/home/welcome`; um marcador 0600 impede a repetição. O resultado agregado sem conteúdo/fichero/domínios/IP é escrito para `/home/welcome/room-check-private/whm-log-full-20261104.json`, com permissões 0600. Não imprime linhas de log, nem grava raw logs em GitHub. A rotina de lembretes mantém a sua funcionalidade e libertação de lock, e o diagnostico não interfere com ela.
- Depois de verificar o resultado em produção, retirar o gancho temporario numa revisão, sem modificar/criar qualquer cron cPanel. Qualquer eventual compactação/limpeza exige inventário e confirmação de utilidade, sem prazo fixo de 14 dias. A monitorização de tamanho e crescimento diário já continua em GitHub Actions.


- O workflow protegido `.github/workflows/cpanel-log-content-audit-once.yml` foi estendido com uma segunda fase de consulta: depois do deploy do PHP privado e da seguinte execução do cron existente, aguarda pelo JSON de nome fixo e só mostra chaves e contagens estritamente permitidas via `deploy/cpanel_read_private_full_log_audit_once.py`. O ficheiro e os logs brutos nunca saem do cPanel. Falha se não aparecer o relatório dentro do limite, se estiver incompleto ou se contiver estrutura inesperada.

### 2026-11-06 — Manual scan workflow in Management Hub

- Added Talões e PDFs, mandatory for Gerente, with narrow `receipts.view` /
  `receipts.run` permissions delegable to Governanta through the existing matrix.
  Ambiguous company/date/document review remains manager-only.
- The user chooses a year and months, then starts processing only their folders
  under `temp_scan`. The issue date determines the destination even when its
  year/month differs from the selection; unclear scans await manager review. JPG/JPEG moves to the month's `taloes`,
  PDF moves to `pdf`, preserving IDs/content and removing the processed file from
  its temporary month folder. Unselected months remain untouched.
- Reuses the existing cron, encrypted vault, Drive OAuth/folders and TOConline
  transport/ledger/DKIM confirmation. Google OCR reads a temporary copy; original
  JPG/PDF is emailed unchanged. No extra daemon or cron. Repeated
  requests never resend the same scan. Only an authenticated external duplicate
  authorizes trashing that new scan; historical originals remain intact.
- Requires manager Google consent for existing scans. No consent or processing
  is activated by deployment. No genuine scan cycle has been performed;
  live OCR/move/email/duplicate acceptance remains pending the grant and genuine
  scans. Existing portal pdf workflow and Airbnb exception are preserved.
- Targeted tests cover roles, selected/unselected periods, wrong year/month,
  corrected filing, mixed JPG/PDF routing, source cleanup, original attachment,
  ambiguity, repeated clicks, interrupted move/OCR, authenticated JPEG duplicate
  replies, preserved accepted originals and history loss. See the invoice flow
  documentation for bounds and recovery.

### 2026-11-06 — Issue-date routing and per-page PDF scans

- Prepared correction: selected year/month folders bound intake; each invoice is
  archived under its own issue year/month. Existing unsent mismatch reviews resume;
  permissions, JPG→taloes/PDF→pdf, portal collection and Airbnb are preserved.
- Multi-page PDFs split before OCR; each page has a stable Drive ID, date/company
  validation and independent TOConline submission/duplicate outcome. Unreadable
  pages stay for review. Master leaves temp_scan only after all page destinations
  are verified; signed duplicate proof removes only the relevant new page.
- New private local splitter reuses configured Node, vendors pinned MIT pdf-lib
  1.17.1 without npm/browser installs, and bounds time/memory/pages/bytes. Release
  probes actual Node with a synthetic PDF after backup, before application copies.
- Local tests cover real splitting/rotation/byte stability, invalid inputs, retries,
  cross-year/month classification, per-page duplicate, partial retention and cleanup.
  Live acceptance remains pending manager Drive scope upgrade and genuine scans;
  no real invoices were submitted as development fixtures.

- PR #337 integrated at `372a1f13a4adf84e1096448e7a908fe57372a887` after both
  required checks passed. Production deployment 390 failed; read-only inspection
  confirms checkout at that commit but last successful deployment remains
  `8d67fd623ded3a04cfe29dbf65b77e7bbe5e8f0f` (389). Do not retry publication blindly.
  The protected receipt-release diagnostic reads only exact deployment 390,
  bounded runtime metadata and installed code checksums. It never prints raw
  configuration/logs, reads invoices, sends mail or writes to the server.

- Read-only diagnostic run `42250031533` confirmed deployment 390 stopped at the
  receipt split probe; installed receipt source SHA-256 still matches 8d67fd6
  (`391b4a3f6f8e9231157967948220d20bd1c3ebcac7bcc3b792aba6c7fb877712`).
  `/usr/bin/node` is unavailable; no explicit invoice Node setting exists. The
  existing cron uses `/home/welcome/nodevenv/booking-vault-agent/22/bin/node`,
  confirmed present. Private invoice directory remains 0700; no new app files
  were copied. The splitter now tries that existing runtime only when the default
  is unavailable in this known account layout; explicit runtime overrides remain
  authoritative. No Node install, agent change or credential update is needed.
- Recovery is scoped solely to inspected failure 390 at exact commit 372a1f1,
  still requiring a corrected descendant, current production HEAD, backup,
  serialized deploy and synthetic page extraction before application copies.
  Any different/new failure blocks recovery. Live scan acceptance still awaits
  manager Drive consent and genuine files.


## Receipt report and history separation — 2026-11-12

Prepared: `receipts.run` opens the process/year/month selector and a reduced report:
source-file and invoice/page totals, Drive-processed count, confirmed duplicates
with supplier name/number/issue date, and the same safe translated errors as the
manager. It never renders file names, Drive IDs/links, accepted-document details
or correction/retry forms. PDF masters do not double-count their invoice pages.
Processed means archived in Drive, not TOConline acceptance.

`receipts.history` replaces legacy `receipts.view`, which was implicitly added
and persisted alongside run; legacy rows are ignored, avoiding accidental history
access for an operator on upgrade. Manager history remains mandatory. Explicit
future history grants are available through the existing matrix; `receipts.review`
is independently delegable, includes history, and is mandatory for the manager.
Neither new permission is granted to Governanta. Existing run grants remain.

Supplier names are recorded from explicit supplier/issuer labels or a unique legal company heading, or entered
by an authorized reviewer. Old/ambiguous names show “Company not identified”;
never infer suppliers from customer NIF, filenames or historical originals.
Current Drive OAuth, portal/archive/TOConline behavior and direct Drive sharing
are unchanged. No real scan submission is initiated by deployment. Production
acceptance and installed commit require separate verification after CI/release.

Supplier recognition follow-up: bare customer headings and Bill to/recipient
headings terminate the supplier section too. A legal-company fallback requires
an identified customer boundary; a lone unlabelled company is insufficient.
Regression fixtures cover customer-only OCR and separate supplier/customer names.

## Azure invoice diagnostics — 2026-11-12

Prepared manager-only encrypted connection and synchronous UI initiation/asynchronous Azure result checks. Existing owner-created North Europe F0 resource; actual key, connection acceptance and two real PDF tests pending deployment. No existing receipt/Drive/TOConline state is changed by diagnostics. Uncertain submissions are retained and not replayed. See `docs/azure-receipt-tests.md`.

## Inline receipt preview — 2026-11-12

Prepared: history-authorized users can show/close PDF/JPEG below each document's
error without a new tab or Google login. The authenticated Hub endpoint resolves
only recorded receipt IDs, connects its Drive client and verifies original bytes.
Pending PDF children use their exact pinned page cache (rebuilt from unchanged
source when needed); archived items use their verified destination. Removed
duplicates/retired masters are unavailable. Run-only operators receive no preview
controls and are denied by the endpoint. No public sharing, queue changes, mail
or reprocessing. Local/CI and deployed functional acceptance are separate gates.

Multi-page clarification: existing extracted pages are labelled Show page, not
complete invoice. A separate inline Show complete source PDF control retrieves
only that child's recorded parent and warns that it may contain other invoices.
The embedded PDF viewer provides page navigation; no automatic invoice grouping
is claimed. Future grouped invoice PDFs use the same viewer for all their pages.
Retired sources remain unavailable; no removed file is restored.

Inline preview acceptance correction: deployment 398 installed 5703316, but live
preview failed because the standalone endpoint did not load Auth (unlike the
receipt page via SessionBar). Explicit Auth bootstrap fixes that dependency; the
endpoint fixture no longer preloads Auth and reproduces the production bootstrap.
The failure was visible and no invoice processing or Drive mutation occurred.
Live document acceptance must be repeated after the corrected release.

Preview styling: use the shared Hub primary-button/form-actions controls for
show/close and retry. Release 398 remains installed after the auth-fix merge:
push CI 43308456324 failed Windows browser supervision with
controlled_chrome_not_ready, so deployment was skipped (not a failed server
mutation). Next guarded release includes the Auth fix and common button styles.

## Receipt QR, grouping and completion — 2026-11-12

Previous preview release **399 / e14ca034** was confirmed installed. Authenticated
Dell acceptance showed both an individual one-page PDF and the six-page source,
including open/close and the global button styling. Mobile embedded PDF rendering
remains explicitly deferred by the owner.

This change replaces automatic per-page Google Docs OCR with local fiscal QR
precedence and bounded Gemini structure/field interpretation. The existing
`gemini-3.5-flash-lite` free-tier project/key is the intended configuration;
`admin/invoice-gemini.php` stores it in InvoiceVault. Azure stays manager diagnostics,
not a second automatic read. QR fields override all printed/model conflicts.
Scanned PDFs without a usable text layer have unknown structure even if QR reads;
Gemini must return printed pagination evidence, checked independently in PHP.

Pre-release tests: local decoder read 12 QR-bearing pages across the two authorized
PDF samples; live Gemini schema test kept a two-page invoice together, marked a
separate repeated copy, and flagged a last page whose preceding page was absent.
The earlier trial that incorrectly called that last page complete motivated the
mandatory per-page evidence and code completeness validation. No real Drive
cleanup or TOConline submission was performed by these diagnostic tests.

Queue implementation retains a complete source/group manifest, verified pending
copies under `temp_scan/pendentes`, immutable submitted history and per-source
cleanup. Unsent legacy OCR guesses are reconciled against the TOConline ledger,
re-read, and their stale archive copies retired only after replacement verification.
Receipt-only sends now connect their Drive client before fetching content; the
previous missing-initialization failure was reproduced and regression-tested.

The existing cron performs bounded batches and persisted short retry windows;
HTTP status polling is read-only every five seconds and preserves open previews
and edited forms. Reports distinguish processed, pending, errors and confirmed
external completion. Operator permissions remain report-only; optional history and
review grants stay separate. Exceptions use the existing approved invoice alert
recipient/template with a private deduplication ledger.

Release gates: pinned local decoder installation and synthetic runtime probe before
application copies; required validate/windows-agent checks; exact installed commit
and authenticated batch acceptance after publication. The API key must be configured
before the new worker can recover the pending batch. Actual accepted/duplicate
TOConline responses, not email submission, determine external completion.
