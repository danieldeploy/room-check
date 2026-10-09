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
