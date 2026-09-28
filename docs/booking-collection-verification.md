# Booking collection verification

- Behaviour: a separate manager-requested verification downloads complete PDFs
  for the chosen issue-month candidate, follows pagination to its end and checks
  the PDF's own issue date, property and invoice number. It does not import files.
  Structural discovery remains an unvalidated diagnostic.
- Permissions: only Gerente may request verification and approve the resulting
  map, using the existing paired Windows agent and encrypted credentials. The
  Booking strategy initially applies to account 1 and its associated properties.
- Inputs and data: selected month, property IDs and the current persistent
  session. Temporary PDF uploads are removed after verification. Private reports
  contain bounded checks and structural hashes, never PDF text or URL tokens.
  Account ownership is checked against the configured account/property and the
  PDF recipient Active Lines; the invoice property must also match independently.
- Failures: no approval without all associated properties passing, an existing
  verified login and complete PDF/date/pagination checks. Ambiguous dates, changed
  table structure, unexpected pagination, foreign links and resource limits stop
  the operation. Verification never enables the schedule automatically.
- Acceptance: real verification for both properties; issue-month filtering and
  PDF comparisons; complete August 2026 collection; repeat with no new documents;
  monthly configuration uses the previous issue month in Europe/Lisbon. The
  collection reuses the verified strategy and repeats PDF identity/date checks.

Verification failure receipts on Windows distinguish the fixed runner outcome
from the Hub outcome, with an allowlisted stage, document count and HTTP status,
PDF signature, stream completion and byte count. They contain no invoice values,
response bodies, raw errors or links. They are private, atomic and manager/support
only; they never approve a map or change the diagnostic signature probe.

Production verification exposed two runtime gaps: forced Task Scheduler stops
could leave old Node agents alive, and the hosting PDF executable did not return
text. The launcher now owns a Windows kill-on-close job for its Node tree;
persistent Chrome remains outside that job. A forced-stop test checks this.
PDF extraction also has a pinned, unmodified Smalot PdfParser v2.12.5 fallback
(upstream commit and file hashes in src/ThirdParty/PdfParser/UPSTREAM.json).
It uses native mbstring, keeps encryption checks, discards image contents,
bounds decode memory and accepts only files up to 2 MiB and ten pages. Both
parsers are tested against the same synthetic invoice; no document is approved
because text extraction failed.
