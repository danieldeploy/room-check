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

### Azure diagnostics (2026-11-12)

Manager-only `admin/invoice-azure.php` configures the existing North Europe F0 resource in InvoiceVault and analyzes owner-selected 1–2-page PDF/JPEG tests (4 MiB). Async operations and allowlisted extracted fields/page evidence are encrypted privately. No Drive mutations, email sending or automatic grouping/deletion. InvoiceReceiptPdf supports nonpublishing inspection for this test path. Authentication, CSRF, upload validation, operation URL allowlist, no redirects, TLS and uncertain-POST replay prevention are mandatory. Future QR-first rules: use readable fiscal QR data; send unreadable and ambiguous duplicate/continuation groups together to Azure; group true continuations and preserve source files until all related invoices finish. Current production pipeline is not switched by this diagnostic addition. See `azure-receipt-tests.md`.

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
