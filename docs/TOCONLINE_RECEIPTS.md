# Conservative TOConline receipt policy

## Specification
- Behaviour: unique attachment IDs, no resubmission after an attempt, distinct accepted/existing/rejected/review/no-confirmation outcomes, monthly counts for all Booking PDFs in the selected issue month and account/property scope.
- Permissions: Gerente only for mailbox setup and verified outcome recording, HTTPS for mailbox secrets, existing CSRF and invoice advisory lock.
- Data: encrypted credentials, delivery evidence hashes, actor IDs and timestamps in the private vault. Bounded email text is processed in memory; no raw messages or credentials in logs.
- Failures: after 72 hours without a reply, mark no confirmation. Never automatically resend after submission, rejection, duplicate or ambiguity. Missing ledger fails closed.
- Acceptance: synthetic tests cover sender/identity matching, generic/ambiguous replies, final-state protection, duplicate pilot refusal, timeout while sending is disabled, and existing delivery safeguards. Required CI and guarded deployment are unchanged.

## Interpretation
Local mail acceptance means submitted, not archived. Archive acceptance is not accounting posting. Already in TOConline is separate from a Hub duplicate block. An external duplicate does not validate a successful new-document pilot. Confirmed outcomes are terminal; the UI never resets the ledger to retry.

Each new attachment is booking-<full identity hash>.pdf. Incoming mail matches a single exact identifier in the text or References/In-Reply-To. Legacy invoice.pdf responses cannot be guessed from date or sender. A Gerente must verify source and exact invoice correspondence before registering a result.

## Read-only mailbox retrieval
PHP IMAP is optional; absence is explicit in settings. The fixed hosting server uses certificate-validated TLS on 993 and the current authorised sender as username. Credentials and folder access are verified before saving. Passwords stay encrypted in the private vault. No credentials are migrated from unrelated projects.

Cron reads up to 50 relevant messages per invocation over a rolling 90-day window, with per-folder UIDVALIDITY/UID cursors. Explicit folders include those receiving replies through mailbox rules. Messages are not deleted or marked read. Messages above 256 KiB are skipped and counted; only bounded text MIME parts are read. Attachments/links are never opened. Unmatched replies are counted in settings for mailbox review.

From headers do not authenticate the sender. Even a precisely correlated reply only marks review and retains a hash and suggestion. It never automatically accepts, enables sending, or claims accounting completion. The observed duplicate phrase only suggests an existing archive document. Unknown templates remain review. Fully automatic trusted acceptance needs an authenticated provider contract or independently verified signatures and validated response templates; no undocumented API is assumed.

## Activation and verification
Save/test the mailbox through its secure password field and verify folder names. Retrieval stays disabled until credentials and server IMAP support are verified. Confirm a successful new archival pilot before separately enabling automatic outgoing delivery. Preserve the vault/ledger in operational backups; code-release backups are not full vault backups. Confirm authenticated monthly UI separately from public login health.

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
