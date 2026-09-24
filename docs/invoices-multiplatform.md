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
Provision client_id and client_secret via InvoiceVault::save('drive-oauth.enc', ...)
from a PRIVATE CLI setup, preserving refresh_token on subsequent updates. Never commit
credentials or expose them through public_html or JavaScript. No tokens in URL query logs.
The manager uses Connect Google Drive and signs in as daniel.ciorcas@welcomehostel.pt.
The app requests drive.file, verifies the authenticated account, and creates a private
Management Hub - Faturas destination. An arbitrary existing folder may be unavailable
with this limited scope; use the folder created by the app.

Archive hierarchy: platform / account with stable account ID / property or account-wide
export / year / month. Server-side database stores pre-generated remote file IDs before
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
