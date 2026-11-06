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

## Ciclo de vida das abas de automação no AccesControl (2026-11-06)

A regra de 04/11 evolui para um único controlador: registo de propriedade,
fecho imediato quando a tarefa termina em segurança e recuperação de tarefas
abandonadas após pelo menos 30 minutos de inatividade. Um agendamento Windows
oculto verifica apenas recursos registados, de meia em meia hora; fica desativado
quando não restam recursos elegíveis e é reativado pela próxima abertura.
Não existe serviço residente novo nem varrimento de todas as janelas.

O agente de faturas aguarda o registo das abas que cria antes de navegar e usa
`shared-ui-bridge.mjs` para delegar no controlador local v2. O marcador de versão
é verificado antes de importar o auxiliar. Instalações antigas conservam a
limpeza anterior como fallback, não como segunda rotina periódica. Abas
reutilizadas ou manuais continuam excluídas, incluindo num perfil persistente.

No `finally`, após libertar guardas/interceções, apenas páginas próprias e seguras
são fechadas. Campos preenchidos, carregamento, multimédia ativa, verificação
humana e estado incerto são preservados. O controlador mantém perfil/cookies e
apenas desliga a ligação CDP. Os processos essenciais e a ligação AnyDesk ativa
nunca são encerrados. Navegadores isolados descartáveis mantêm a limpeza existente.

A cobertura confirmada é Chrome e Explorer registados. A intenção do proprietário
é tudo o que não abriu manualmente, mas ausência de prova de origem nunca autoriza
fecho. Outros plugins/programas que não registem recursos ainda não estão
abrangidos automaticamente; a tentativa de acrescentar fecho genérico foi bloqueada
e não foi contornada. Ver `docs/flows/managed-ui.md` para protocolo, instalação,
limites e testes de abandono/reativação. Uma instrução de conta ChatGPT não é
considerada configurada por esta alteração.

## Manual scan intake (2026-11-06)

The separate `admin/receipts.php` module uses `receipts.view` / `receipts.run`.
Both are mandatory for Gerente; Governanta receives neither until the manager
assigns them through the existing permissions matrix. Run implies view only,
not access to portal settings or accounts. Only Gerente can confirm unreadable
metadata and company ownership. Every mutation uses CSRF and the existing
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