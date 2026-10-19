# Cloudbeds email discovery

The initial discovery is complete. The email connector is implemented; production acceptance remains a separate required check.

- Expected behaviour: determine whether recent real Cloudbeds mail contains PDF
  attachments, document links, or payment confirmations before writing an extractor.
- Permissions: use the existing hosting credential only in the protected
  `management-hub-production` environment, after the normal PR checks. No mailbox
  password, browser login, new token, or change to the paired Windows agent.
- Inputs: only the fixed Welcome and City `info` mailboxes and their Cloudbeds
  Maildir folders; examine up to 160 newest and 20 oldest messages in each, bounded to 4 MiB.
- Failures: stop on invalid paths, symlinks, bounds or API failures. Emit only fixed
  error codes. Never change mail, flags, folders, filters, cron or credentials.
- Acceptance: synthetic tests verify path confinement and that reports contain
  no email text, subjects, addresses, filenames, URLs, query parameters, document
  numbers, amounts or credentials. A real shape report is required before choosing
  the extraction route; matching wording alone does not prove an invoice.

`deploy/cloudbeds_mail_inspect.py` reads MIME messages transiently in the protected
job and outputs only fixed structural classifications and counts. It does not save
messages, publish artifacts, follow links or run content from mail. It uses the
existing TLS-verified hosting transport without broadening its deployment client.

The production Hostelworld mechanism differs per hosting account: Welcome delivers
filtered authentication messages to its private pipe; City polls its own dedicated
Hostelworld folder and delivers only the extracted authentication token and bounded
metadata to the Hub over signed HTTPS. These flows do not prove that a reusable
IMAP password for either mailbox exists in the Hub vault. They do provide the
established hosting access and mail-processing pattern to reuse here.

The Cloudbeds connector validates real PDF invoices, company NIF and issue date, groups by issue year/month, prevents repeat delivery, preserves mailbox originals and uses the existing Drive/retry/TOConline pipeline. Payment receipts are not invoices.
Airbnb delivery remains excluded and the existing TOConline test button remains.


## Connector specification

- Expected behaviour: receive PDF invoices from the existing Cloudbeds folders in
  Welcome and City; validate Cloudbeds, Active Lines, the configured company NIF,
  invoice number and issue date, then reuse Drive and TOConline delivery.
- Permissions: gerente manages collection and schedules. The existing protected
  hosting credential provisions only the reviewed filters, files and local workers.
  No Cloudbeds login or new mailbox credential is required.
- Inputs and data: the two fixed info mailboxes, MIME up to 4 MiB, PDF attachments
  up to 2 MiB, and the selected issue year/month. Mailbox originals and flags are
  untouched. City sends PDFs, a message digest and, for SPF, minimum SMTP metadata
  to the Hub, never its email body or authentication material to the Welcome mailbox.
- Failures: messages without usable DKIM or SPF, malformed PDFs, wrong companies and ambiguous
  dates remain private for review. Email delivery retries with bounded backoff;
  collection uses the existing task history and alerts. Drive and TOConline retain
  their existing retries, uncertainty handling and confirmation ledger.
- Acceptance: signed synthetic mail, PDF parsing, wrong company, receipt, wrong
  month, repeat collection and cross-account isolation tests. Production requires
  a real selected-month document from each source, verified Drive storage,
  TOConline acceptance/existing-document confirmation and a duplicate repeat.

Read-only inspection on 17 October 2026 found PDFs in both accounts, alongside
payment confirmations without attachments. The PDFs use Invoice Number, Invoice
Date with an English month name, VAT Number, Cloudbeds and Active Lines. No private
invoice values or samples are committed.

The source first verifies the original Cloudbeds DKIM signature. If DKIM cannot
be used, the native mail source may attest its first SMTP ingress to the Hub,
which independently requires a Cloudbeds-aligned MAIL FROM SPF pass using the
pinned RFC 7208 library. No sender-supplied Authentication-Results or Received-SPF
claim grants trust. Known DKIM body/signature mismatches remain blocked.
Historical mail is bounded to two years. Each source has a separate HMAC bridge
key, with TLS and a five-minute timestamp window. Subjects and receipt wording
cannot establish invoice identity. No download links are followed.

Mail older than the two-year verification window is preserved and counted as
historical review, not retried indefinitely or sent to the Hub. Collection requests
for months outside that window fail explicitly. Current messages still require
the DKIM-or-SPF, recipient and PDF checks. Source status retains fixed
authentication/HTTP failure codes; no header values or message contents are logged.

CloudbedsInbox encrypts incoming PDFs and provenance, validates their content and
serves the shared collection queue without the Windows browser worker. The
selected period must match the issue date, irrespective of service/due dates.
PDF hash and invoice number prevent duplicate imports; the existing TOConline
ledger also deduplicates a Cloudbeds invoice across the two source accounts.
Drive and TOConline revalidate the PDF before delivery. Staged PDF bytes are
removed after import; the normal Drive cleanup owns local imported-file deletion.

## Provisioning and rollout

The guarded release creates two bound Cloudbeds accounts and private receiver
configuration. The post-release installer adds separate copy-preserving filters
and minute workers, verifies exact bindings, and refuses conflicts. Welcome also
uses the private pipe; City uses its local folder worker, as already established
for Hostelworld. Folder polling recovers deliveries where an earlier mailbox rule
stops later filter rules. Existing Hostelworld/Booking/TOConline filters are kept.

A one-time acceptance request collects the previous calendar month after both
source scans are current, followed by one repeat to verify deduplication. Monthly
collection is enabled at day 5, 04:00 Europe/Lisbon only after Drive and TOConline
confirmation pass for both sources. Later deployments preserve user settings.
The separate cloudbeds-acceptance CI job reads only fixed status fields and
separates deployment from verification of a complete real cycle.

Airbnb remains excluded from TOConline, the TOConline test button remains, and
Expedia and other email suppliers are outside this change.

The read-only authentication diagnostic records only fixed verification-stage names.

### SPF reception evidence inspection

- Expected behavior: report whether original invoice emails carry SPF/DMARC
  reception claims and whether the SPF envelope domain aligns with Cloudbeds.
  This observation alone never authorizes an import or changes DKIM policy.
- Permissions: reuse the protected hosting inspection job and its existing
  source-specific, read-only Cloudbeds Maildir access.
- Inputs/data: inspect existing headers in memory; emit only fixed provider,
  verdict and header-name enums, counts and booleans. Never emit addresses,
  IPs, server/header values, message IDs, document values or authentication tokens.
- Failure handling: missing or unfamiliar claims remain absent/other. A familiar
  authserv-id, SPF marker or header position is not proof of trusted provenance;
  receiver ownership and trustworthy insertion must be established separately.
- Acceptance: synthetic aligned/shared-provider/lookalike-domain and redaction
  checks, followed by protected observation of real invoices in both accounts.

When reception verdicts are absent, the inspector can probe current SPF with the
distribution-packaged pyspf implementation. It uses the observed SMTP peer IP
and envelope sender only when the envelope belongs to Cloudbeds and matches all
stored Return-Path values. Parameters stay in memory/stdin; only a fixed verdict
is reported. Each probe has DNS and process deadlines plus a shared 90-second
budget across both accounts. This is explicitly a
current-DNS diagnostic, not proof of historical authentication, trace provenance
or document integrity.

### SPF intake policy

The protected inspection also reports fixed native-trace rejection stages and
the PHP SPF verdict when the trace is usable. It never prints SMTP parameters or
raw trace text. This separates parser rejection from SPF refusal without changing
intake decisions. An unstarted SPF acceptance probe remains visible as empty
counts instead of hiding the main pipeline report. Review notices cover both
authentication methods. Synthetic tests cover report availability and redaction.

- Expected behavior: a usable native Exim SMTP trace plus an aligned SPF pass
  permits invoice intake when original DKIM cannot be revalidated. SPF uses the
  current DNS policy; it is not represented as a historical reception verdict or
  a cryptographic signature of the document. All existing PDF/company/NIF/date
  and duplicate checks still apply.
- Permissions/data: only the bound native pipe/folder worker can attest the
  client IP, envelope sender and HELO over its existing source HMAC/TLS bridge.
  The original email remains in its own mailbox. Only the PDF and these minimum
  SMTP parameters reach the Hub, never another mailbox. SMTP parameters are
  discarded after checking; encrypted provenance retains only the method.
- Trust boundary: use the first receiving hop from the hosting provider's
  serverNN.romania-webhosting.com family, optionally preceded by one local Exim
  delivery on the same server. Require a Cloudbeds envelope consistent with
  Return-Path and the correct source recipient. Never search later trace headers
  for a more favorable sender IP, and reject ambiguous hop syntax.
- Failure handling: fail/softfail/neutral/none/permerror stay in review; temporary
  DNS failures retry. Earlier DKIM-review entries are reconsidered once by source
  processor v4, while successfully delivered originals remain deduplicated.
- Acceptance: synthetic spoofed/ambiguous trace, wrong account, shared-provider
  SPF, include/IPv4/IPv6, DNS recursion, HMAC tampering and retry tests. A one-time
  real collection chooses the newest common issue month containing validated SPF
  invoices for both accounts, then repeats it. SPF validation requires verified
  Drive storage, TOConline archive confirmation and the duplicate repeat.
Messages without either a usable DKIM signature or an aligned SPF pass preserve
the original for review and do not import a PDF. These messages are counted
separately from transient failures so authenticated invoices can continue.
Collection history shows a bilingual review warning and uses the existing alert
queue, deduplicated by account and selected month. A successful functional check
proves delivery of the authenticated invoices, not completeness of unverified
archived mail. DNS lookup/network failures remain retryable.
