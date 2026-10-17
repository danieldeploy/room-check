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

Printed property identity: strip only leading zeroes from the labelled numeric
Hostel Number before comparing it with the already bound property, in both Node
and PHP. Different or conflicting numbers fail; issuer, company and date checks
are unchanged. Existing manager approval remains mandatory. Private receipts may
retain four identity booleans and a bounded scanned-PDF count to locate failures,
never the extracted text or number. Synthetic padded, wrong and conflicting IDs
are tested before production verification; padding is not assumed to explain a
production rejection until that verification establishes the result.

Source identity and display numbers: collection uses the exact numeric identifier
shared by the row ID and same-origin PDF path. The first-column display number is
not an additional identity requirement and is not used to name or correlate the
download. PDF property, issuer, company and issue-date checks remain mandatory.
This changes no permissions or approval gates. A fixed table-failure reason and
bounded row position distinguish malformed rows from changed headings without
recording invoice values. Tests reject mismatched row/PDF IDs before downloading
and confirm that presentation differences do not change the source identity.

Legacy issue dates: Node and PHP accept labelled English month names, Sept,
ordinal days, separators, month-first English dates and year-first numeric dates.
Day/month numeric forms are accepted only when their order is unambiguous.
Original/previous/related invoice dates and due/payment dates are not the current
issue date. Missing, conflicting, invalid or ambiguous dates still reject the
document; no service-month inference is made. Private receipts retain only a
fixed date-check reason and bounded counts. Synthetic equivalent date formats,
invalid calendars, references and ambiguity cases precede production verification.
