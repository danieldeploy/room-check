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
