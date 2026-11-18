# Management Hub project state

Read the [full project state through 17 November](PROJECT_STATE_through_20261117.md) together with the updates below. It includes outstanding work, operational decisions and the previous release history.

## Paper invoices — reconciling a TOConline batch (2026-11-18)

A single reply can report purchases by issuer, files archived by attachment name
and refusals in separate sections. The Hub reconciles signed evidence against the
actual email and keeps a per-document outcome. A complete, uniquely attributable
supplier group can be confirmed together. Missing or conflicting results leave
the affected documents available for review. See [the invoice flow](invoices-multiplatform.md#reviewing-a-toconline-batch).
The manager can compare all PDFs in a submitted group and explicitly resolve a
missing result. Historical reconciliation reads retained replies; it does not
submit another copy. The exact run diagnostic includes the basis for a recorded
confirmation and the number of recorded attempts per document.

Release status: prepared. Verify the integrated commit, deployed version and verified functional result separately.
