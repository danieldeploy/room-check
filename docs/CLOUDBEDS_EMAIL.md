# Cloudbeds email discovery

This first step is a read-only discovery, not an enabled Cloudbeds connector.

- Expected behaviour: determine whether recent real Cloudbeds mail contains PDF
  attachments, document links, or payment confirmations before writing an extractor.
- Permissions: use the existing hosting credential only in the protected
  `management-hub-production` environment, after the normal PR checks. No mailbox
  password, browser login, new token, or change to the paired Windows agent.
- Inputs: only the fixed Welcome and City `info` mailboxes and their Cloudbeds
  Maildir folders; examine up to 20 newest messages in each, bounded to 4 MiB.
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

The pending Cloudbeds connector must validate a real invoice PDF, company and issue
date, group by issue year/month, prevent repeat delivery, preserve mailbox originals
and use the existing Drive/retry/TOConline pipeline. Payment receipts are not invoices.
Airbnb delivery remains excluded and the existing TOConline test button remains.
