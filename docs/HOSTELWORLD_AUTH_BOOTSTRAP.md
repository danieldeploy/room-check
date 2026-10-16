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
submitting credentials, consumes the link once in a new tab of the same browser
context and verifies server-rendered property identity before navigating to VAT
invoices. The original waiting tab remains open. The new tab has the same
destination and secret guards before navigation and is closed after inspection.
A returned login form stops the task without another credential submission.
Failure returns a bounded
stage/code without credential values, response bodies or token URLs.

After a release, verify that the Windows executor has the approved runner files;
publishing the hosting copy does not update the Windows installation. Follow the
pause/idle/update/probe/resume procedure in `WINDOWS_INVOICE_AGENT.md` and compare
source checksums before the production acceptance test.

## Windows browser-mode comparison

For a manager-requested inspection of the two bound accounts, the Windows runner
opens ordinary windowed Chrome in its new, private disposable profile. The Windows
task must run in an interactive desktop session. Other actions, portals and
non-Windows runners retain their existing browser mode. This is an automated
comparison with headless execution, not evidence of a successful manual login.

The inputs remain the encrypted account credentials and existing single-use email
challenge. There are no personal-profile imports, new credential stores, browser
fingerprint overrides or network changes. Destination guards, challenge/error
stops and profile cleanup remain active. The private result records only the
fixed browser mode (`window` or `headless`) alongside the existing diagnostics.
If Chrome cannot launch, the task fails with the existing browser error instead
of silently retrying in another mode. A returned login form still stops after
one email-link navigation.

Acceptance: verify the approved Windows source checksums, run one inspection,
confirm its reported browser mode and authenticated property/invoice-page state,
and verify that the task ends without leaving the temporary browser running.
Windowed Chrome does not itself validate or enable invoice collection.

Inspection remains an unvalidated structural diagnostic. It does not enable
scheduling, approve a collection map, download invoices, or infer issue dates
from the service-period columns. Acceptance requires live email delivery,
account identity and invoice-page checks; PDF/date/pagination validation follows
separately before activating collection.
