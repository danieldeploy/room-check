# Invoices and Portals — multi-account archive

The module separates account authentication, collection, private temporary storage,
company review, verified Google Drive archive, and downstream accounting status.
Platform failures are isolated. Portal maps must be validated per account before
scheduled collection is enabled; adding an account does not make its connector ready.
Expedia and HostelsClub remain disabled. Existing room management is unchanged.

## Installation

1. Back up application and database. Apply additive migrations 027 and 028 after 026.
2. Deploy the branch through the existing cPanel Git workflow. Runner and cron scripts
   belong outside public_html, under room-check-private. Install locked Node dependencies
   with `npm ci` in the private invoice-runner folder (Node >=22.12).
3. Configure private_dir, node_binary and runner_script in the existing private local
   configuration. Preserve the vault master key. Keep vault directory 0700 and files 0600.
4. Cron invokes the private cron/invoices.php each minute. GET_LOCK prevents overlapping
   workers. No raw provider errors, passwords, OTPs, cookies or tokens are written to logs.
5. Complete browser preflight and account-specific maps before enabling schedules.

## Google Drive connection

Register a Google OAuth web client with exact callback
`https://check.welcomehostel.pt/admin/invoice-drive.php`.
For first-time setup, the manager imports the downloaded Web application client JSON
in Invoices > Settings over HTTPS. The CSRF-protected form validates the Google endpoints,
client fields and exact callback, and stores only client_id and client_secret in
`drive-oauth.enc`. It never echoes credentials or imports tokens from the uploaded file.
Reimporting identical credentials preserves the encrypted file and refresh_token;
changing an existing client requires a PRIVATE CLI setup via InvoiceVault::save,
preserving refresh_token only when the OAuth client stays unchanged. Never commit
credentials or expose them through public_html or JavaScript. No tokens in URL query logs.
The manager uses Connect Google Drive and signs in as daniel.ciorcas@welcomehostel.pt.
The app requests drive.file plus drive.metadata.readonly, verifies the authenticated
account, and requires Google consent. Metadata access lets it discover the existing
accounting folders and detect filename collisions; it does not grant download or
content-write access to unrelated documents. File creation stays under drive.file.
Set the existing accounting root and run the real destination test before enabling
uploads. A previously connected account must reconnect to grant metadata access.

Archive hierarchy: accounting root / year / Portuguese month_year / pdf.
Reuse existing month folders using either an underscore or a space (for example,
Junho_2026 or Junho 2026); ambiguous matching folders stop with drive_conflict.
The configured root must contain the years, not point to one month's pdf folder.
Owner decision, 2026-11-06: the final folder is lowercase `pdf`, replacing `online`.
Rename existing monthly `online` folders in place after checking that no `pdf`
folder conflicts. Preserve folder/file IDs, parents, contents and delivery history;
do not copy, reimport or resend invoices. The new path cache discovers the renamed
folder by name; existing document `drive_parent` values and retries keep that ID.
Historical `online` cache entries need no database rewrite. Complete and verify
the Drive rename together with this release; do not revert just the folder name
while this layout is active. New months create `pdf` through the existing worker.
This is a folder-name change only: issue-month rules, the Airbnb CSV/export-month
exception and TOConline eligibility remain unchanged. Sibling `taloes` folders
and receipt processing are outside this step.
Filenames use portal_property_month, for example booking_city_center_junho.pdf.
An occupied filename gets an invoice-number/document-ID suffix; existing Drive
files are never replaced. The worker waits for a successful destination test
(state ready) before attempting delivery. Existing pending remote identities are
preserved across retries. The Google authorization must include metadata access to pre-existing folders;
verify folder discovery and a complete upload with the real API before claiming
that the connection works. Server-side database stores pre-generated remote file IDs before
upload so ambiguous network outcomes reuse the same identity. The original is removed
only after remote ID, parent, size and MD5 match, and local SHA-256 matches the collected
record. Shared content-addressed originals stay until every referencing document is verified.
One initial attempt + eight retries, each 10800 seconds after the previous attempt.
After exhaustion: retain local original, stop automatic retry, queue WhatsApp failure notice.
Manual retry resets attempts but preserves remote identity. Business and TOConline states
are independent of archive success. A matching Active Lines anywhere in extracted PDF/CSV
text validates the company; otherwise review is required. PDFs need pdftotext available.

## SMS / email / TOTP

### Booking implementation order (decision, 23 September 2026)

1. Test Booking in the dedicated, persistent Windows Chrome profile, which keeps
   the human verification already completed there. If an extranet session is still
   active, return `session_active`: the task ends, but it does not verify saved
   credentials, change account readiness or enable scheduling. Do not force a
   logout merely to manufacture a fresh login test. The test needs no invoice map.
2. If Booking offers SMS 2FA, receive the code through the existing correlated
   Android-to-Hub flow and finish the login automatically. A fresh login after
   session expiry can request SMS, but Booking decides whether to present it;
   never claim that an SMS was tested unless it was actually requested and used.
3. On an expired session, retry normal login first. Queue a manager WhatsApp
   intervention notice only for an actual human-verification challenge. Do not
   treat ordinary expiry as requiring a person, and do not try to bypass CAPTCHA.
   The manager's Booking account-1 access test and later retries use the same
   separate, persistent Chrome login profile; an active collection profile is
   preserved for collection and discovery. A real challenge leaves its tab open
   for human completion, then the next access test resumes the newest Booking
   sign-in tab in that profile without needing another deployment. `op_token`
   alone is not a CAPTCHA signal and its value never enters the private diagnostic.
   WhatsApp delivery still requires the configured notification recipient/template.
4. After an independently verified login, record the navigation to invoices and
   validate collection for Welcome Guest House and City Center Guest House.
   Monthly collection uses the previous month's **invoice issue date** in
   Europe/Lisbon, on the configured day. A completed structural discovery alone
   does not prove login or a downloaded invoice.

The public Booking Connectivity API documentation does not establish an invoice
download endpoint for this account; do not base the implementation on an
unverified API assumption. Keep the login test separate from the invoice map.

The per-account Android device token is displayed once and stored only as a hash.
POST invoice-auth.php with Authorization: Bearer TOKEN;
JSON fields account_id, sender, message, received_at (Unix seconds), optional sim. Only a single active
challenge, configured sender/keyword/SIM and recent message are accepted. OTP values are
encrypted, consumed once and removed. Reconfigure the Android receiver for this protocol;
legacy Booking receiver is separate and must not be assumed compatible.

Email 2FA uses read-only IMAP over verified TLS, exact sender/recipient and subject filter,
UID validity and freshness checks. TOTP implements RFC6238; the account seed stays encrypted.
CAPTCHA or unexpected challenges produce needs_auth, not automatic bypass.

## WhatsApp template

Create/approve a utility template `invoice_collection_failed_v1`, language pt_PT, with
five body parameters: platform, account, period, problem/documents, required intervention.
Suggested body:
Management Hub — Faturas e Portais
Plataforma: {{1}}
Conta: {{2}}
Período: {{3}}
Problema: {{4}}
Ação necessária: {{5}}

Choose an active manager with a mobile number in the module and enable only after approval.
Drive alerts group by account, period and error after the upload retry budget is exhausted.
WhatsApp delivery has its own retry/status; portal collection continues independently.

## Remaining account setup / explicitly deferred business workflow

Real portal maps, credentials, the Google OAuth client, Android forwarding and the WhatsApp
template require installation/validation in the connected accounts. No production readiness
is implied by unit tests. Email attachment collection and CSV-to-PDF/TOConline processing
are not enabled by this release: CSV originals are preserved and marked awaiting processing.
The Airbnb transformation must be defined with real CSV samples before activating delivery.

Google API reference: https://developers.google.com/workspace/drive/api/guides/manage-uploads
\n## Separação do arquivo ZKTeco\n\nO cron de faturas conserva exclusivamente as tarefas de faturas, Drive e TOConline.\nA entrega dos backups da base MySQL do ZKTeco foi retirada de\n`cron/invoices.php` e passa para `cron/zkaccess-backups.php`, com um agendamento\ncPanel próprio e bloqueio MySQL separado. Ver `docs/flows/zkaccess-backup.md`.\n

## Ciclo de vida das abas de automação no AccesControl (2026-11-04)

Decisão transversal: a automação que abre uma aba temporária é responsável por
registar essa aba (identidade do objeto/target) e fechá-la depois de concluir a
tarefa. Esta regra aplica-se também a futuras automações de Booking, Facebook,
Meta Business, Cloudbeds e outros portais, não apenas às faturas. Não se cria
watchdog, serviço permanente, cron ou verificação global de cinco em cinco
minutos: o trabalho ocorre no próprio agente Windows, apenas durante uma tarefa.

O agente de faturas possui um Chrome persistente para Booking: só as páginas
explicitamente criadas por essa tarefa são suas; páginas existentes, reutilizadas
ou abertas manualmente nunca são encerradas. A primeira página e quaisquer
outras páginas criadas na mesma execução devem ser registadas. No `finally`,
após libertar guardas/interceções, o agente fecha apenas páginas suas cujo
estado seja seguro (sem editor preenchido, operação em curso ou dúvida).
Falha de consulta do estado, interrupção ou verificação humana pendente
conservam a aba. O navegador é **desligado da ligação CDP**, nunca encerrado;
perfil, cookies, 2FA humano e sessões persistentes continuam disponíveis.
Navegadores isolados descartáveis conservam o seu encerramento existente.

Para futuras automações com outros pontos de entrada, a regra de `AGENTS.md`
exige o mesmo registo explícito e limpeza após a operação, com testes
reproduzíveis de três casos: uma aba criada é fechada; uma aba preexistente
não é fechada; um rascunho ou pedido de intervenção humana é preservado.
A aceitação operacional exige observar uma tarefa real que abre e fecha a
sua própria aba, sem terminar a sessão nem interromper a seguinte tarefa.

## Manual scan intake (2026-11-06)

The separate `admin/receipts.php` module uses `receipts.view` / `receipts.run`.
Both are mandatory for Gerente; Governanta receives neither until the manager
assigns them through the existing permissions matrix. Run grants the reduced processing report only; it does not grant individual
history or portal settings/accounts. History uses explicit `receipts.history`
and corrections use explicit `receipts.review` (which includes history).
Both remain mandatory for Gerente and optional, initially unassigned, for Governanta. Every mutation uses CSRF and the existing
`room_check_invoices` advisory lock.

After scanning, a user with run permission chooses a year and one or more months
and explicitly starts intake. Only the corresponding named month folders below
`temp_scan` in the configured accounting root are read, including their real
subfolders. Portuguese month names accept case variants and Marco/Março. Missing
months are reported; duplicate month folders stop discovery. Unselected months
and loose files in temp_scan are untouched. File/folder names never replace the
invoice's issue date. The selection limits discovery only; each invoice's issue
year/month selects its destination, even across year boundaries. A mismatch is
processed normally. Only ambiguous/invalid data requires review. Replacing the
bytes of a recorded file requires uploading a new file ID, so old OCR is never
reused for changed content. Existing unsent mismatch reviews resume under this
rule; previously archived or submitted history is never reprocessed.

There is no new scheduler, service or automatic intake on deployment. The
existing invoice cron continues queued work, one invoice or source cleanup per invocation.
Repeat requests are idempotent by Drive ID; changing a pending discovery's
selection is rejected instead of silently ignored. One invoice per JPG/JPEG or PDF page,
<=10 MiB, maximum 50 PDF pages. MIME, extension and content must agree. Unsupported
files stay in Drive for correction. Snapshot bounds: 40 folders per selected
month, 500 files total. The encrypted history stops at 2,000 documents without
pruning. Existing queued work continues using its recorded source folders.

Receipt access is an explicit, manager-only Google OAuth upgrade to `drive`.
The previous `drive.file`/metadata grant cannot read/move new mobile scans.
The consent screen must allow this scope; a partial grant is rejected before
replacing the existing credential. Existing portal authorization and verified
archive readiness remain in force. The public privacy copy describes the
optional broader grant. Code restricts intake to the selected accounting tree.

Google Drive's PDF/JPEG-to-Docs OCR uses a tagged temporary copy. The original
is never re-rendered. Automatic classification requires one distinct explicitly
labelled issue date, one document number and the configured recipient NIF near
a customer label. Invalid/future/ambiguous dates, wrong company or multiple
identities require manager review. The manager opens the original and confirms
date, number, company and one document per page/image. Uncertain OCR creation is
recovered by its appProperties tag, never blindly recreated. Temporary OCR copies
are trashed after reading. An unresolved creation requires manual review.

After date/company checks, JPGs and single-page PDFs move with ID/name/content
preserved; extracted PDF pages upload to their individually selected destination:

| Input | Destination below accounting root |
| --- | --- |
| JPG / JPEG | `YYYY/<Portuguese month>_YYYY/taloes` |
| PDF | `YYYY/<Portuguese month>_YYYY/pdf` |

Existing year/month aliases and the shared folder cache are reused. Persisted
move intent and checksum validation recover an interrupted move. This removes
the processed original from `temp_scan/<month>` without leaving another source
copy. Nothing is removed before a verified destination exists. The original is
checked again before email. Portal invoice collection and Airbnb's exception
remain unchanged.

Receipt cleanup uses Drive's `capabilities.canTrash`, independently of folder write
permission. An explicit denial preserves the source and verified invoice copies,
stops automatic retries, records `drive_trash_permission` and requires intervention
before manual Retry resumes only cleanup. Existing generic trash denials are
reclassified on the next worker tick. The application does not transfer ownership,
change sharing or silently remove a file's parent instead of trashing it. The
five-second status response includes a safe translated global error, so a resolved
missing-key/connection banner disappears with the live report.

`receipts-workflow.enc` is the encrypted private queue/history, covered by the
existing private-vault backups. The shared TOConline ledger uses disjoint
`receipt:<Drive ID>` targets and `receipt-<identity hash>.pdf/.jpg` attachment
names. Identity includes source ID and content hash: repeating this scan never
resends, while a separate scan gets its own externally correlated result.
The existing sender, destination, pilot, enabled flag, MIME transport and DKIM
reply verification are reused. JPG is sent as image/jpeg, PDF as application/pdf.
TOConline supports both formats; JPG/single-page originals or the exact archived
extracted PDF page are attached.
No receipt entry overwrites a portal or older receipt's content/identity record.

Intent is persisted before mail; unknown outcomes are never automatically
resent. Only a signed, uniquely correlated `toc_existing` reply authorizes
trashing this intake's file ID after rechecking archived parent, size and
checksum. Historical accepted originals are never searched for or deleted.
Unknown, rejected, unsigned or multiple-filename replies never delete files.
Lost queue/transport history fails closed. Processing failures back off and
stop after five attempts; a permitted user can request a retry after correction.

Acceptance: `tests/invoice-receipts.php`, existing Drive/TOConline/pipe tests and
PT/EN contracts. Covers year/month selection, mixed formats, source removal,
mismatches, corrected filing, interruption, unchanged attachments and preservation
of an accepted historical JPG when its new duplicate is trashed. Live OCR,
source movement, delivery and signed duplicate cleanup require the new Google
grant plus genuine scans. Empty folders/public login are not live acceptance.
Do not send files from `Teste Faturas` as production test data.

References: Google Drive upload conversion and OAuth scope guides; TOConline
[Robot de arquivo e classificação de documentos](https://manual.toconline.pt/support/solutions/articles/3000108314-robot-de-arquivo-e-classifica%C3%A7%C3%A3o-de-documentos)
confirms PDF/JPG support for email archiving.


### Multi-page scan handling (2026-11-06)

`InvoiceReceiptPdf` invokes the existing configured private Node binary, without
shell, network or browser, using the vendored MIT pdf-lib 1.17.1 bundle. It copies
individual pages without rasterisation, preserving dimensions/rotation/content;
no promise is made that a signature on the multi-page original applies to the
extracted derivatives. A one-page PDF remains byte-identical. Bundle provenance
and integrity are recorded beside the vendor file; no npm install is needed.
Limits: 10 MiB input/page, 50 pages, 50 MiB total output, 256 MiB Node heap and
30-second process deadline. Malformed/encrypted/over-limit files remain in source.

Split parent and all reserved child Drive IDs commit together to the encrypted
history before OCR/uploads. Each page gets independent OCR, date/NIF review,
archive, submission and authenticated duplicate result. The UI shows page number
and links to the source PDF until that page is archived. Retries reuse IDs and
checksums; missing private derivatives regenerate only from the unchanged source.
Private workspaces are removed on exit and content-addressed page caches after
verified archival. No temporary PDF or extracted text is publicly served/logged.

The master remains in `temp_scan/<month>` until every child has a verified Drive
archive. Then each destination is freshly verified (or the exact child has already
been removed following authenticated duplicate confirmation) before the master is
placed in Drive Trash. Partial review/failure cannot retire the master; interrupted
cleanup is idempotent. Trash remains reversible. Only a verified TOConline duplicate
may remove a final child copy; acceptance/pending/rejection of another page has no
effect on its siblings. Source cleanup does not claim TOConline acceptance.

The existing release runs a private synthetic two-page extraction probe with the
active configured Node binary after backup and before application copies. A failed
probe blocks release. Automated tests cover page content/rotation, deterministic
regeneration, invalid/oversized PDFs, wrong month/year, mixed formats, interrupted
child upload, per-page duplicates, incomplete source retention and complete cleanup.
Live Google OCR/Drive/TOConline acceptance still requires the broader Drive grant
and genuine scan files; deployment alone does not prove this cycle.

The 2026-11-06 production runtime inspection found `/usr/bin/node` absent and the
existing Node 22 virtual environment used by the account cron available. Only
when the default invoice Node path is unavailable, the splitter may use
`/home/<account>/nodevenv/booking-vault-agent/22/bin/node` for the established
`/home/<account>/room-check-private/invoices` layout. Explicit `node_binary`
overrides never silently fall back. The same selection is used in the release
probe and worker; the existing Node agent/configuration remains unchanged.

### Operator report and explicit historical/review access (2026-11-12)

The module/home entry accepts run OR history. Run no longer implies historical
access. The formerly implicit and persisted receipts.view key is retired; an
explicit receipts.history grant is required, preserving the manager via locked
permissions without promoting legacy operator rows. The matrix can assign history
and review independently for future need; review includes the needed history.
Run-only POSTs cannot review or retry an individual document, even with guessed IDs.

Every authorized viewer sees totals (source files, invoices/pages, Drive-processed,
duplicates and errors). A split PDF master is a source file, not another invoice;
its pages each count as invoices. Processed counts verified Drive archives,
including later duplicates, and never claims downstream acceptance. Duplicate
rows show only supplier company, invoice number and issue date. Error details
use the existing allowlisted bilingual diagnostics, never raw provider logs/OCR.
The reduced HTML contains no original filename, Drive ID, document link, normal
invoice details or correction form; it is filtered server-side before rendering.

Supplier extraction accepts explicit supplier/issuer labels or a unique legal
company heading before the customer section; ambiguity remains unidentified. An authorized reviewer can enter a supplier
name without changing the existing issue-date/NIF/document confirmation. Missing
or historical names remain explicitly unidentified; no automatic replay is used
to backfill them. Existing accounting routing and signed duplicate cleanup remain.

Acceptance: role matrix/legacy rows, explicit future delegation, actual reduced
HTML with hostile supplier strings and private IDs/filenames, mixed PDFs with
master/page counts, duplicate dates, missing company names, review/source errors,
unauthorized individual POSTs, manager history/review and preserved scan workflow.

Supplier boundary clarification: customer labels need not contain a colon and
include Bill to/recipient variants. Legal-heading fallback is used only before
an identified customer section; customer-only/unknown layout stays unidentified.

### Azure experiment retired (2026-11-15)

The owner retired the unused manager-only Azure diagnostic. Its page, client,
UI translations and dedicated tests are removed. It was never the automatic
receipt provider. Deployment removes the two stale installed PHP files and the
exact private Azure configuration/history/lock files after verified recovery
copies; unfinished operations retain private state. No shared vault key,
receipt ledger, document, Drive permission or current provider is removed.
External Microsoft resource retirement requires its own verified inventory.

### Authenticated inline receipt preview

Show/close document toggles a lazy PDF/JPEG preview below the document error.
`admin/receipt-document.php` requires login plus receipts.history on every GET;
run-only permission is insufficient even with a guessed ID. Only an ID already
recorded in the encrypted receipt history is resolved, never an arbitrary URL.
The Drive client authenticates before retrieving and verifying bytes. Pending
children show the selected page, using existing checksum-pinned private cache
and deterministic reconstruction when missing; archived documents use Drive.
Preview may recreate only private derived page cache, never change queue state,
submit, move or delete documents. Removed duplicates/retired masters fail closed.
Responses are no-store, PDF/JPEG only, without raw provider details. Closing
aborts loading and revokes the browser blob; no new tab or public Drive grant.
Tests cover selected-page identity, unchanged workflow/send/move state, archive
retrieval after cache cleanup, unknown/removed IDs and permission boundaries.

Multi-page clarification: existing extracted pages are labelled Show page, not
complete invoice. A separate inline Show complete source PDF control retrieves
only that child's recorded parent and warns that it may contain other invoices.
The embedded PDF viewer provides page navigation; no automatic invoice grouping
is claimed. Future grouped invoice PDFs use the same viewer for all their pages.
Retired sources remain unavailable; no removed file is restored.

The standalone document endpoint must explicitly load Auth; its test bootstrap
provides only database configuration, never hidden authentication dependencies.
Actual role-denial status is checked to detect bootstrap exceptions masked as
a generic preview failure.

Receipt preview open/close and retry controls reuse the shared primary-button
and form-actions classes; no separate button design or permission change.

## QR-first receipts and grouped invoices (supersedes automatic per-page OCR)

`InvoiceReceiptAnalysis` combines local QR decoding, optional Gemini evidence and a
strict complete page manifest. Fiscal QR fields A/B/F/G/O take precedence over model
fields. A model cannot merge different fiscal identities, lose/duplicate a source
page or make a missing printed page complete merely by returning `complete=true`.
The local `receipt-tools` runtime renders scans sequentially with bounded pixels,
uses a pinned offline ZXing WASM decoder, and retains exact source PDF pages when
extracting a group. Original dimensions, rotation and page content are preserved.
A textless scanned PDF has uncertain structure and requires Gemini even with QR.

Gemini has one fixed HTTPS host/model, no redirects or tools, bounded request/output,
JSON schema, encrypted content-hash cache and an attempt budget of 400/day with
10-second spacing. Daily accounting uses the provider's Pacific reset day; actual
project quota may be lower. Transient retries persist 5/15/45-second eligibility,
respect Retry-After, and stop after five transient failures. The existing cron can
wait briefly within its bounded batch; longer waits resume on the next invocation.
No new cron, service, paid-tier switch or automatic Azure fallback is introduced.
Sources: https://ai.google.dev/gemini-api/docs/models/gemini-3.5-flash-lite and
https://aistudio.google.com/docs/rate-limits (consulted 2026-11-12); concrete quotas
must still be verified in the owner's project rather than inferred from those pages.

Each selected source gets a durable manifest and stable Drive IDs for invoice
groups, including repeated copies as separate documents. A repeated-copy suggestion
never authorizes deletion; the authenticated TOConline verdict does. Each group is
saved to actual issue year/month (`pdf` or `taloes`) or, if unresolved, to
`temp_scan/pendentes`. The latter is outside selected month intake, prevents recursive
re-import and keeps only relevant invoice pages available via the authenticated
preview. Source cleanup verifies every group copy before trashing that one original;
structural ambiguity preserves the complete source. Other months/files are untouched.

Legacy recovery is limited to sources whose children have no TOConline ledger
submission and only pending/retry states. Stable old records are marked superseded,
not discarded; wrongly dated archive copies stay until the new complete manifest
has verified replacement copies. Existing submitted, uncertain and accepted history
is immutable. Pending manual confirmation cannot override a valid QR date/number or
recipient. Manual page groups require complete coverage and no prior submission.
A missing physical page may require a corrected rescan; never manufacture it.

`admin/receipts.php?status=1` uses the same role gate and reduced report projection.
Run-only users get totals, duplicate company/number/date and safe errors, never
file IDs/history/preview/correction fields. History refresh preserves open viewers
and unsaved forms. Finalizado requires external accepted/duplicate states and
completed local work; submission/review is Finalizado com pendentes, not success.
The receipt exception ledger reuses invoice notification settings and the approved
invoice template, never the server-storage template. Uncertain alert delivery is
recorded without blind resend.

The QR does not contain the supplier's legal name. If that name is unknown, Gemini
also reads it; names paired with a validated QR issuer are cached privately for
future scans. This supports the operator's duplicate company/number/date report
without inventing a company name or mistaking the customer for the supplier.

Release diagnostics classify only the private PDF subprocess failure category;
raw stderr never reaches the report, logs or browser. The synthetic deployment
probe still blocks application copies when local QR/PDF execution is unavailable.

The PDF/QR child uses inline WebAssembly bounds checks (`--disable-wasm-trap-handler`)
to operate within shared-host virtual-memory limits; the 512 MB JS heap, page/pixel
caps and timeout remain enforced. This does not change portal browser execution.

Gemini credentials are opaque: do not infer validity from a legacy key prefix.
Configuration checks bounded printable ASCII without whitespace/control bytes;
the provider determines authentication. Vault storage and secret-free status
remain unchanged.


### Reply recovery and deferred source cleanup
The receipt workflow reuses the portal mail sender, cryptographic reply parser and
ledger. The guarded hosting reply filter must preserve existing mailbox filing.
A one-off local Maildir recovery can feed retained replies through that same pipe,
limited to exact outstanding receipt keys, bounded reads and the normal signed
recipient/date/outcome checks. It never resubmits invoices or modifies email.
Recovery state is encrypted and repeat-safe, including Maildir filename changes.

The owner has deferred source cleanup. The private source_cleanup_paused flag
preserves masters in temp_scan while archive and TOConline state continue. Existing
source permission errors become retained split masters, not new active work or
successful deletion. Genuine invoice errors remain reviewable; only confirmed
TOConline duplicates permit retiring their newly archived invoice copy. Source
cleanup requires a later explicit resume, not a deploy, rerun or permission change.

## Shared outbound capacity

All portal and scan deliveries share a rolling ten-attempt hourly budget,
including uncertain attempts and explicit diagnostic sends. Duplicate ledger
aliases do not consume extra capacity. Capacity is checked before committing
send intent; unsent scans wait with the existing countdown and retry through
the existing invoice cron, without failed-attempt increments. Throttling does
not clear submission history, reset confirmed outcomes or resend queued mail.
The budget leaves headroom for other domain mail but cannot guarantee hosting
capacity when unrelated mail also consumes the domain quota. Mail-server
acceptance still requires a separate authenticated TOConline acknowledgement.

## QR-created purchase acknowledgements

The observed successful purchase-association template has supplier NIF and fiscal
invoice number, but no uploaded filename. Parse only its single, complete fiscal
identity and known success wording. The existing DKIM verifier still protects
sender, recipient, subject, date, decoding controls and complete body. Match only
one recorded receipt submission for the configured company/sender, with exact
supplier NIF and full invoice number and a signed date no earlier than its send
attempt (allowing the existing clock-skew tolerance). Include terminal duplicate
scans in the ambiguity check; never guess between copies or infer an external
duplicate from an acceptance message. Unknown/multiple rows stay unconfirmed.

The private pipe retains only these necessary structured fields, not raw mail.
Recovery version 2 revisits bounded recent retained messages skipped by the old
filename-only correlation, through the same verifier and receiver. It does not
submit, delete, move or flag mail, and does not reset the outbound ledger.

Purchase association can refer to a newly created or already existing purchase;
the optional creation footnote is not an archive-success requirement. A signed
acknowledgement matching multiple recorded scans sets only the nonterminal
copies to reply-received/review, retaining ambiguity evidence. It does not
select a copy, declare a duplicate or alter any final outcome. Existing reports
and exception alerts expose this review requirement.

## Independent receipt runs and completed-source retention (2026-11-14)

Owner-authorized policy supersedes the source-cleanup hold and original-trashing
rules above: move the unchanged original to the sibling `temp_scan_processed`
folder only after every grouped invoice is resolved. Unconfirmed, incomplete or
failed invoices keep their original in its selected `temp_scan` month. Verify
retained invoice bytes again before moving; persist the destination before the
Drive mutation and reconcile an uncertain move using the existing ID/checksums.
Keep full-source previews available after movement. Do not change ownership or
sharing. The existing deploy hook applies this authorized policy once; the
existing invoice cron performs bounded work and reports failures normally.

Each request now records its date/time, year, selected months and document
membership. Existing history becomes one legacy run without resubmission. New
requests are blocked while an execution is processing or awaiting an automatic
retry. Once only human/provider pending outcomes remain, any selected months can
start a new run, including the same months. Existing Drive IDs are always skipped
during discovery: no reassignment, reread or reset of a prior pending scan. Manual
review/failure items are excluded from automatic duplicate consolidation; legacy
recovery cannot revive documents belonging to an older run. Discovery failures can be retried within the same
run. A run is complete only after invoice resolution and source movement;
missing folders, review and unconfirmed email are not completion. The process
selector displays Europe/Lisbon timestamps. Filters and five-second polling keep
the selected run and distinguish all documents, TOConline-confirmed outcomes,
and problems/pending documents. Existing operator/history/review permission
boundaries remain unchanged, including the preview endpoint.

Complete, unambiguous copies with identical decoded QR fields (or identical
bytes and fiscal identity without QR) can be consolidated. QR identity never
proves completeness. Prefer a confirmed retained copy; otherwise use the model's
bounded legibility score and stable source order. Uncertain or complementary
pages are excluded. Pending equivalent copies are not resent. Authenticated
acceptance of the fiscal invoice is required before removal. A previously
ambiguous signed reply can resolve a set only if every candidate in that reply
is equivalent; a matching subset is insufficient. Keep the acknowledgement and
submission history. Record local consolidation separately as
`toc_duplicate_local`, never as a provider duplicate rejection. Verified
`toc_existing` replies still authorize removing only the duplicate final copy.
Retire a local duplicate only after freshly verifying the retained copy (or a
confirmed existing TOConline archive). Originals follow the move policy above.

Invoice and full-source previews use native expandable disclosures with the
Hub styling. Closing aborts outstanding retrieval and releases blob URLs;
polling preserves open previews and unsaved corrections. Mobile PDF rendering
remains the previously deferred browser limitation.

Acceptance covers legacy migration, run isolation, overlap rejection, missing
folder retries, permissions, complete versus incomplete copies, exact QR
comparison, signed ambiguous acknowledgements, readability selection, duplicate
cleanup recovery, retained original previews and uncertain source movement.
Live Drive movement and authenticated UI acceptance are verified after deployment;
unit tests alone do not establish production success.

Exception alerts retain the process identity and selected periods, so starting a
new run does not resend an old run's existing incident or mislabel its months.

Rejected submission errors are rendered separately from polled workflow errors,
receive focus and retain the submitted year/month selection. Five-second polling
updates the start button from the full workflow activity, even while viewing a
previous pending run. The advisory worker lock still protects every mutation.

## Compact receipt review and targeted missing-page recovery (2026-11-14)

The module is A4 Invoices and Small Receipts / Faturas A4 e pequenos talões.
The selected process's status and read-only Refresh share the process/filter card;
automatic five-second updates preserve open viewers and unsaved edits. New-run
eligibility remains global. Date formatting changes presentation only: dd-mm-yyyy
with the localized month name, and mm-yyyy for the original intake folder.
The review displays issuer_nif, never the configured recipient NIF as a supplier.
Recipient matching and fiscal QR precedence remain mandatory.

Reports distinguish PDF/JPG originals from invoice groups. A multi-page invoice
counts once. Processed means a resolved invoice: confirmed TOConline acceptance
and archive, or confirmed duplicate and completed removal. Pending is the remaining
invoice count; awaiting a reply alone is not an error. Source retirement is checked
separately by overall process completion. The duplicate count and repeated manager
error list are removed; safe duplicate details remain, and run-only operators keep
safe error details in a disclosure without gaining individual history or previews.

A receipts.review user can request missing-page verification for an unsent pending
PDF invoice. This queues `repairing` under the existing shared lock/cron. Intake
and discovery retries cannot run concurrently with this work. Only unrecorded
files from that invoice's original source month are examined (at most 19 new files,
20 assembled parts, 10 MiB total and 50 pages). Existing documents never change
process merely because a new intake selects their month again.

Each candidate must identify the same issuer, recipient, full invoice number and
issue date, with no conflicting total/QR. A candidate containing other invoices is
not claimed. The combined analysis must confirm exactly one complete invoice and
an unambiguous printed page sequence. The installed pdf-lib runtime preserves page
content/dimensions/rotation and restores printed order. Unreadable, mismatching,
repeated or incomplete pages remain pending with a translated reason. A checked
box is only a request to verify, never evidence that the invoice is complete.

The replacement gets one durable ID and reuses normal Drive/archive/TOConline
submission and authenticated reply handling. Original and additional sources share
that replacement child in the encrypted history; the old derivative is superseded,
retained until confirmed replacement, and cannot be submitted. Other pending
invoices remain untouched. Additional sources move to temp_scan_processed only
when their children resolve; a mixed original stays while any other invoice is
pending. Cache reconstruction uses pinned source references and the saved page
order. Already submitted invoices cannot be repaired through this operation.
Saving edited details on a known incomplete invoice keeps it pending; it cannot
bypass missing-page verification. No new provider, credential, service or cron.

Acceptance: repair lifecycle tests cover mismatch, duplicate/mixed candidates,
no new scan, concurrent discovery, submitted-history immutability, cache loss,
normal single submission and source retention across authenticated acknowledgement.
The PHP/native PDF bridge tests cover deterministic assembly and page order.
Production end-to-end repair requires a genuine missing scan and a real provider
acknowledgement; synthetic fixtures do not prove those external outcomes.

Receipt content is server-localized and excluded from the legacy browser partial
translator via its existing skip boundary, including status/report refreshes.
Navigation stays outside the boundary. This prevents English wording corruption
without changing invoice data, permissions or processing.

## Receipt retries, status reads and process summaries — 2026-11-14

This rule applies to **A4 invoices and small receipts**, not portal collection.
Existing invoice identity, fiscal QR precedence, grouping, role permissions and
`temp_scan_processed` retention are unchanged.

- Temporary Drive/Gemini failures persist an initial failure and at most five
  further attempts in six-minute slots within thirty minutes. A delayed cron
  skips missed slots; it cannot extend the deadline or invent attempts. Provider
  minimum delays are respected; a delay beyond the window requires intervention.
  Auth, permission, storage, configuration and document-review errors stop.
  Discovery/global connection failures use the same policy as individual scans.
- Gemini daily quota is distinguished from short throttling. Persist a full
  24-hour pause across midnight/restarts and allow one provider probe. A failed
  probe blocks until explicit human retry; success records recovery. Normal
  model pacing consumes no failure attempt. Existing safety usage counts remain.
- Receipt TOConline submissions get six additional state checks, due at 5, 10,
  15, 20, 25 and 30 minutes. Use the existing authenticated asynchronous email
  receiver, never resend to obtain a reply. Replies already received are handled
  before expiry; a later valid signed reply can still resolve the record. Other
  portals retain their established 72-hour policy and shared mailbox handling.
- Status GETs do not execute processing or consume attempts. Poll every five
  seconds while any selected work is runnable (including mixed manual/automatic
  records); display a local countdown and query when a retry is due. Awaiting
  TOConline polls at sixty seconds only until its deadline. Manual-only,
  completed and stopped-worker states stop polling. Global start availability
  remains synchronized when viewing an older run while another run is active.
  Any failed/timed-out status GET stops automatic reads and reveals **Status
  check failed.** plus **Refresh**. A successful manual read hides both and
  applies the returned schedule. Open document previews and drafts remain intact.
- Receipt WhatsApp uses one private consolidated summary per process outcome,
  after other active work, short retries and confirmation waits have stopped.
  Include process/time/months, totals, safe reasons, recovered failures and next
  action. A daily quota pause gets one summary; the next-day result is a new
  outcome. Human continuation has a cycle identifier, so another failure with
  identical counts is not lost. Repeated observations do not resend. Historical
  stopped processes are baselined, not replayed at deployment. A send interrupted
  after starting is uncertain and never blindly retried. Meta acceptance does
  not prove handset delivery.

### Receipt WhatsApp template contract

Meta UI inspection found the existing `invoice_collection_failed_v1` active,
Utility, `pt_PT`, five parameters, but fixed wording **É necessária a sua
intervenção** and a portal-specific URL. It must remain for portal errors and
must not be reused for successful receipt summaries. The receipt summary uses
`receipt_process_summary_v1`, Utility, `pt_PT`, exactly five body parameters:

```text
Management Hub - Resumo do processamento
Módulo: {{1}}
Processo: {{2}}
Meses: {{3}}
Resultado: {{4}}
Próximo passo: {{5}}
Consultar: https://check.welcomehostel.pt/admin/receipts.php
```

Reuse the existing enabled notification setting, active manager recipient,
WhatsApp client and protected credentials. No new recipient or credential.
The template was submitted in the existing Active Lines Unip. Lda. account on
2026-11-14. Meta subsequently reclassified it as **Marketing**, still **In
review**. Receipt sending must stay disabled until Utility/pt_PT approval is
verified. The protected acceptance probe defaults to disabled and normal
summaries wait for its private `meta_accepted` receipt. Auto-review rejected an
external body rewrite without specific owner approval; no rewrite was made.
A once-only guarded-release probe uses clearly labelled synthetic text, stores
its private receipt before sending and never resends an uncertain result. It
neither submits invoices nor changes their workflow state. Template approval,
provider acceptance, handset delivery and the real next-day event remain
separate acceptance evidence.

### Buyer tax-number review and invoice-only discard (2026-11-14)

PDF and JPG/JPEG intake applies the same buyer rule. A missing/unreadable buyer
NIF, final-consumer designation (including `999999990`), or a NIF different from
the configured company remains in review with a specific translated reason.
The displayed supplier tax number continues to use `issuer_nif`; it is never
replaced by the buyer/company setting.

`receipts.review` authorizes explicit **Confirm this document belongs to our
company**, alongside the required invoice details in one correction form. This permission is
mandatory in the CSRF-protected, locked POST handler; it is not hard-coded to a
role. The private workflow records actor, timestamp, destination company,
document ID, SHA-256 and the extracted buyer value. It does not rewrite
`company_nif`, fiscal QR fields or document bytes. The same classification gate
is used before Drive filing, TOConline submission and signed purchase-response
correlation. It classifies the document in the Hub; it does not correct the
supplier's invoice or promise TOConline acceptance.

Buyer confirmation re-evaluates independent blockers: grouping, known missing
pages, uncertain identity/details and fiscal status. It cannot mark an incomplete
invoice complete. Ordinary date/number review cannot implicitly override the
buyer. The missing-page action is offered/accepted only for an actual missing-page
condition. Repair keeps exact invoice identity/page evidence; a replacement with
new bytes does not inherit the old buyer confirmation and may require a fresh
confirmation. Existing pending records are usable without a global reanalysis,
reassignment of runs, or resetting submitted history.

For a buyer-error invoice, an authorized reviewer can explicitly choose deletion.
This choice is unavailable after valid buyer confirmation, even if another field
still needs review in a historical record.
The durable transition is `review → discarding → discarded`; the existing worker
and bounded retry policy perform and verify Trash. A denied or uncertain Drive
operation remains unresolved, with an error and retry action. Only a selected
single-invoice copy is eligible: a mixed or ungrouped original cannot be deleted.
The original combined PDF and other invoices remain intact. Once every child is
resolved, the existing original-retention policy moves the whole source unchanged
to `temp_scan_processed`. Private cached copies are cleaned only when no unresolved
invoice shares them. A tombstone remains in history; discovery and regrouping
cannot silently resurrect a deleted invoice. Nothing already submitted can be
manually discarded through this action.

Deleted invoices count as completed local work and have a separate deleted count.
They say **Not sent to TOConline**, are excluded from the TOConline-confirmed
filter, cannot be previewed again through their discarded ID, and do not imply
external acceptance. Report-only users receive totals, not individual review or
delete actions. Polling is active during deletion/retries and stops on human-only
pending work as before.

Acceptance: synthetic PDF/JPEG buyer cases, independent blockers, raw evidence
retention, one send per ID, changed-content/destination invalidation, denied Trash,
repeated worker ticks, mixed-master retention/completion, repaired replacement
reconfirmation, and real endpoint role isolation are covered in receipt tests.
No real new invoice submission is part of deployment verification for this change.

### Paper invoice presentation and history counts (2026-11-14)

The module label is **Paper Invoices — A4 and Small Formats** (PT: **Faturas em
papel — A4 e formatos pequenos**). History's Documents below / Processed / Pending
counts use the same `summary` and `resolved` rules as the processing report, on
all records matching the selected run and status filter before pagination.
Original PDFs with extracted children do not add invoices. Their source cards
remain accessible as labeled disclosures, including grouping and failed-source
retry actions; source problems still keep the process pending even if invoice
counts are resolved. No workflow state or stored date is changed by presentation.
The duplicate table's paper-copy instruction does not delete anything digitally.
Field captions use shared settings styles; month names beside checkboxes retain
their existing typography. Scan/start help precedes Year, below User instructions.
Acceptance must check all/success/problems, polled history, source failures,
multiple runs and pagination without weakening run-only report permissions.

### Complete correction and execution activity (post-PR368, 2026-11-14)

The first pending form lists all missing required details together: issue date,
supplier name, invoice number and supplier NIF. Empty inputs have red placeholders
(`DD/MM/AAAA`, `Input company name`, `Input invoice number`, `Input NIF` in EN).
Typed values use ordinary text styling. The date format is day/month/year and is
validated before conversion to stored ISO; QR-derived date, number and supplier
NIF remain locked. The supplier field is never filled with our buyer NIF.

`Confirm and continue` enables only after valid required fields and explicit
confirmations. Editing does not submit. The CSRF/permission/worker-lock protected
POST validates the complete request again and commits corrections plus any buyer
classification atomically. A failed request keeps the typed form but does not
save partial buyer approval. A repeated/stale confirmation cannot restart the
document. Existing valid historical buyer confirmations remain usable. Known
missing pages retain the separate save-details/repair path and cannot be marked
complete by this form; grouping and fiscal-status blockers also remain enforced.

Successful review queues the invoice through the existing Drive/TOConline worker,
including authenticated duplicate handling, verified cleanup and source-retention
gates. The correction form disappears while automatic work continues. A new real
failure can return a compact actionable error. The background-processing banner
reflects remaining automatic work and disappears when it stops; a successful
POST alone no longer creates a processing flash.

Intake year, all months and Start are disabled during automatic work anywhere in
the receipt workflow, including bounded provider retries and live TOConline reply
waits. The server enforces the same rule. Human-only pending records permit a new
run, including the same source months, without rediscovering recorded file IDs.
The existing five-second active polling and scheduled retry/reply timings remain;
no new always-on polling or service is introduced.

Processing Run options show Running/Pending/Completed from actual work state.
Execution year/month selectors sit on their right, default to the current Lisbon
period, and survive status polling and pagination. They filter execution start,
not invoice issue dates or scanned folder months. Explicit historical run links
open their execution period; All years/All months includes undated legacy runs.
An empty period shows no matching run, not another run's invoices. Intake Year is
left of its input above Months to Process. PR368 compact counts and global styles
remain; non-error JPEG source wrappers are not extra invoice cards/PDF controls.

Acceptance covers atomic valid/invalid controller POSTs and delegated permissions,
PDF/JPEG confirmations, missing-page repair, repeat submissions, unchanged QR and
raw buyer evidence, authenticated duplicate lifecycle, active/manual/provider-wait
locks, Lisbon period boundaries, empty filters, pagination, and UI draft/viewer
preservation. Synthetic fixtures exercise submission and cleanup; deployment QA
must not submit or delete real invoices merely for testing.

### Receipt result presentation (post-PR369, 2026-11-14)

The run card sizes its selector to the longest option, bounded by its container;
execution year/month follow on the left with a small gap and wrap on narrow screens.
Each label aligns above its selector. Only a selected running
run shows its actual requested months/year in the green banner. The banner and
redundant intake/status explanations leave no blank placeholder when stopped.
Intake locking remains global, while report/filter visibility is selection-local.
Only the disabled selected month checkbox tick is dark gray; labels retain their
existing typography. The report title adds a black Status/Estado label, then shows green Processing with five animated
dots (reduced-motion respected), Completed!, or Pending for stopped unresolved
work. Retry countdowns and failed-status-read-only Refresh remain available.

The history status selector follows the counts on the left with a small gap, with Processed in TOConline and
Problems or pending. Running overrides filters to all documents on the server;
once stopped the automatic filter shows problems if any, otherwise processed.
Explicit historical all links remain readable. Open previews and correction drafts
survive polling; selected filter visibility still updates. Duplicate paper-copy
instruction is red, with only paper copies (or its localized equivalent) bold.
Report-only roles gain no document access.

PDF and JPEG use the same invoice cards and verified final Drive preview; only
PDFs offer source-PDF context. A removed duplicate can preview only its recorded
accepted, archived, equivalent keeper. Missing, mismatching, removed or unconfirmed
keepers produce no preview. No guessed Drive paths, sharing changes, copy recovery,
provider sends or stored invoice mutations are performed by this presentation.

### Mobile PDF presentation (2026-11-15)

Mobile PDF disclosures use a lazy canvas viewer from the existing PDF.js 6.4.299
package. The authenticated byte endpoint and permission checks are unchanged;
bytes go directly to the same-origin worker. No external viewer/CDN or document
upload. Serve matched module/worker/cmaps/fonts/wasm with the library license.
One page at a time, previous/next, fit-width and bounded zoom; at most 50 pages
and 8 million canvas pixels. Closing, aborts, replaced history and page exit
release the worker and canvas; loading/render failures remain retryable.
Desktop PDFs keep the native iframe regardless of viewport width. JPEGs retain
the existing image preview on all devices, including archived/retained copies.
Verify current Android Chrome with a real authorized invoice and source PDF,
then desktop PDF/JPEG; automated routing alone is not visual acceptance.


## Folder category verification and explicit correction (2026-11-15)

This supersedes format-only PDF routing and automatic missing-page candidate search.
Use temp_scan/<month>/A4 or temp_scan/<month>/Talões (taloes alias accepted).
The existing bounded descendant listing carries category and relative path. Unknown
subfolders inherit their category; nested A4/Talões conflicts are invalid. Files
straight under the old month folder default to A4. JPEG still routes to taloes and
uses its existing interpretation; no JPEG category call is added.

Only newly discovered PDFs in the production analysis workflow receive the category
check marker. Recorded history is not migrated/reprocessed by deployment. Extract
one actual PDF page into a private temporary directory; Gemini returns a4, taloes
or uncertain without seeing the expected folder. Reuse the established pacing,
quotas, retry windows and separate content cache. Classification explicitly uses
minimal thinking and a 1,024-token combined
thinking/output cap; the former 128-token cap risked truncation before the JSON.
This follows the [provider token-limit contract](https://ai.google.dev/gemini-api/docs/generate-content/thinking)
and does not change the one-page/request scope or full fiscal-analysis configuration.
The cap is a bound, not a guarantee of visual accuracy. A receipt scanned on A4 remains
taloes. The folder, not the model, determines destination. Hold mismatches/uncertainty
at the source, before allocating invoice children or sending anything. Confirmed
category flows to every child and the report's A4/receipt counts. Fiscal analysis
and QR precedence are unchanged.

Corrections become available only when the global processing run settles. A review
user loads a bounded original-month list, selects exact path/name, confirms the
checkbox and submits Process again for this document only. Server POST validates
review permission, CSRF and shared worker lock. Selection tokens bind ID/checksum/
parent/category; worker validates again before claiming content. Files already
claimed by another document/run cannot be selected. A moved original may retain
its ID; a different replacement is associated with the superseded original in the
same run, before normal classification/grouping. Historical copies and receipts
are retained. No automatic move based on a Gemini suggestion.

Missing-page repair now accepts only explicitly selected files (up to 19), validates
all selected files belong to the same fiscal identity, rejects mixed/conflicting
files, then reuses complete pagination, order, QR and submission protections.
Older queued repairs without selections return for explicit selection. There is
no fallback scanning/claiming of unselected files. Stale/missing selections stay
pending. Moving or replacing bytes after selection requires selecting again.

The correction UI opens an internal modal and retrieves a fresh bounded snapshot
on every opening, including empty folders. It starts at the original month and
allows navigation only through its real descendants (no shortcuts or other Drive
folders). There is no Refresh button or external Drive link: operators add scans
through their Drive application before opening the picker. Checkboxes allow up
to 19 missing-page files across folders; source replacement uses one radio choice.
Use selection returns exact names/paths to the form, followed by the existing
confirmation and Process again. Cancel/Escape discard draft choices; reopening
revalidates committed selections and clears obsolete content/location choices.

Recorded pending copies, source PDFs and other claimed files remain visible with
localized exclusion reasons. They receive no selection token, but bounded valid
PDF/JPEG files may be previewed through the existing same-origin document endpoint
and mobile/desktop renderer. Correction previews require receipts.review, an idle
eligible document and a fresh original-month snapshot; ID/content/parent/category
must match before the existing checksum-verified download. No arbitrary Drive ID
or path, external login, credential exposure, OCR call or state mutation is added.
Closing/navigating releases preview requests and blob URLs. Loading errors keep
submission disabled and permit closing/reopening. The native dialog traps focus
and restores the opener. PT/EN labels remain within the server translation boundary.
The empty message means no **new selectable** file, not that Drive is empty.
POST and worker validation are unchanged; a pending page cannot repair itself.

Tests cover real first-page extraction, separate cache and shared quotas, folder
routing/JPEG preservation, document isolation, exact selected sources, moved and
replacement IDs, stale selection, idle/review/CSRF boundaries, UI escaping and
selection/checkbox requirements, modal navigation/cancellation/freshness, preview
authorization and stale/out-of-scope preview rejection. Live Gemini accuracy is a separate acceptance
check; tests never imply error-free visual recognition.
