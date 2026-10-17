# Hostelworld invoice collection

Expected behavior: accounts 2 and 3 sign in with their configured credentials and
separate email-link channels, in a fresh Windows Chrome window. Retrieve the VAT
invoice PDFs and select by their labelled issue date, never the table's service
period. Inspect every page, including cumulative pages and duplicate navigation
links. Bound the scan to 20 pages, 400 distinct PDFs and 100 MB; exceeding limits
fails the task without returning a partial collection.

Users and permissions: existing manager actions and paired Windows agent only.
Verification does not import or send invoices. Manager approval requires a passed,
completed verification for the selected issue month, at most 24 hours old, with
unchanged credentials and recipient NIF. Collection uses that version-4 map.

Inputs and data: existing encrypted credentials, signed email-link bridge,
account/property bindings, selected issue month and configured TOConline NIF.
PDF.js is pinned to 6.4.299; runtime Node is at least 22.13. The independent server
parser checks issuer, exact property, exact recipient NIF and issue date. The
portal row/PDF ID is a source identifier; it is not claimed to appear in the PDF.
Private reports contain checks and structural hashes, not document text or URLs.

Failure handling: missing or conflicting dates, changed table structure, foreign
links, pagination loops, wrong property or NIF and invalid PDFs stop acceptance.
Unknown delivery outcomes keep the existing no-resend rule. Booking's identity
namespace and retained diagnostic button are unchanged. Hostelworld attachments
use a separate correlation prefix; authenticated replies use the existing pipe.
The downloaded original PDF is rechecked against the current NIF before sending.

Acceptance: synthetic PDF tests on Node and PHP, cumulative pagination and
out-of-month cases, stale/configuration-changed approval rejection, exact-head
validate/windows-agent CI, then independent verify/approve/collect runs for both
accounts. Confirm verified Drive storage and the real TOConline reply separately.
No production PDF or authentication material belongs in source or test fixtures.

Account layout compatibility: accept a unique Invoice # heading table within the
VAT region, whether its rows share that table or appear in a separate table.
Show More and Show All may offer different cumulative page counts. Validate both
links and advance exactly one page; never skip directly to the larger count.
Existing manager/agent permissions and invoice selection are unchanged. Invalid
headings, foreign links, skipped pages and ambiguous tables still fail closed.
Local verification receipts may retain bounded table/header/row/navigation counts,
never URLs, heading text or invoice values. Synthetic combined/split-layout and
dual-navigation tests precede a real City verification and approval.
