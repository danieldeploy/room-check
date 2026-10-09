# Hostelworld authentication bootstrap

The manager's existing portal inspection uses the encrypted account credentials
and the private filtered-email challenge before inspecting the invoice page.
No new mailbox access, permissions or forwarding are introduced.

Account 2 is bound to property 305209; account 3 to property 77759. Both the
stored hostel number and associated property must match before the fixed
`inbox.hostelworld.com/login/` token destination is provisioned. The existing
signed City bridge, message freshness, active challenge and single-use checks
remain in force. No unvalidated map or incoming email chooses a destination.

The worker checks the login form action, prepares the email challenge before
submitting credentials, consumes the link once and verifies server-rendered
property identity before navigating to VAT invoices. Failure returns a bounded
stage/code without credential values, response bodies or token URLs.

Inspection remains an unvalidated structural diagnostic. It does not enable
scheduling, approve a collection map, download invoices, or infer issue dates
from the service-period columns. Acceptance requires live email delivery,
account identity and invoice-page checks; PDF/date/pagination validation follows
separately before activating collection.
