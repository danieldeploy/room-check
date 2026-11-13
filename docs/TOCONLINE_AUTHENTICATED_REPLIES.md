# Authenticated TOConline replies

Expected behavior: update the monthly issue-date report for a uniquely correlated, cryptographically verified archive-success or already-existing receipt. Archive state is not an accounting posting.

Permissions: existing private CLI pipe and encrypted queue; Settings evidence remains manager-only. No extra account, public endpoint or mailbox password. Test button stays until end-to-end validation and explicit owner withdrawal.

Data: transient bounded raw mail is used for RSA-SHA256 DKIM verification, then discarded. Only the receipt hash, correlation tokens, allowlisted outcome and authentication evidence enter the encrypted queue. The existing diagnostic file contains no raw messages or credentials. The normal send ledger, configured sender and recipient are checked before automatic outcome updates. Automatic outgoing remains disabled unless separately enabled through existing controls.

Failure handling: unsigned, unsupported, altered, expired, DNS-failed, ambiguous or unknown-template replies remain reviewable. No claimed Authentication-Results header is trusted. Legacy queued replies have no proof and remain manual. Final recorded outcomes are immutable.

Supported verification is deliberately narrower than general DKIM: up to five signatures checked independently against the unchanged message, exact toconline.pt signing domain, full body, RSA-SHA256, minimum 1024-bit key, simple/relaxed canonicalization (chained DKIM-header signatures remain unsupported), signed From/To/Subject/Date and every present interpretation header (Content-Type and Content-Transfer-Encoding); unused Message-ID and MIME-Version are not required to be signed, one exact recipient, signed date within 30 days and no earlier than the original send (5-minute clock tolerance). Testing/revoked keys and l= partial-body signatures are rejected. DNS TXT queries are bounded to selector._domainkey.toconline.pt. Unknown formats must be investigated, never relaxed merely to pass a test.

Accepted templates: exact previously observed success subject with one Booking PDF identifier in the body; observed duplicate phrase with one identifier and no conflicting success subject. Other PDF names and extra/unknown tokens prevent automatic confirmation. Duplicate confirmation never validates a new-archive pilot. Verified new-archive acceptance can validate the pilot but never switches automatic sending on.

Checks: PHP 8.1/8.2 signed synthetic fixtures, body/header tampering, duplicate headers, forged authentication headers, expired/partial signatures, key failures, recipient/date/identity gates, final-outcome immutability, private CLI and queue recovery. Required validate and windows-agent checks precede guarded deployment. Production acceptance requires a real new reply; unit tests and a deploy alone do not prove provider compatibility.

References: RFC 6376 (DKIM canonicalization, header selection, verification) and RFC 8301 (SHA-256, RSA key strength). Cryptography is provided by OpenSSL, not a custom RSA primitive.

The private diagnostic lists missing signature coverage only as names from a fixed interpretation-header allowlist. It never includes header values.

Multipart handling: the signed outer Content-Type fixes boundaries; child interpretation headers are covered by the full signed body. The parser does not consume an outer identity Content-Transfer-Encoding (7bit, 8bit, binary), so its signature is not required. Encoded outer multipart and unsigned leaf decoding headers remain manual. Diagnostic MIME facts are only a boolean and an allowlisted encoding name.

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

Purchase association can refer to a newly created or already existing purchase;
the optional creation footnote is not an archive-success requirement. A signed
acknowledgement matching multiple recorded scans sets only the nonterminal
copies to reply-received/review, retaining ambiguity evidence. It does not
select a copy, declare a duplicate or alter any final outcome. Existing reports
and exception alerts expose this review requirement.
