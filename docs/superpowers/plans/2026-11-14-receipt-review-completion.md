# Receipt review completion implementation plan

**Goal:** Implement the owner's agreed compact receipts UI, truthful completion counts and targeted missing-page recovery.
**Architecture:** Keep the encrypted workflow, existing invoice worker/lock, fiscal QR authority and authenticated TOConline ledger. Add bounded cross-source PDF assembly using the installed pdf-lib runtime; no new service or dependency.
**Spec:** Owner-approved list, recorded in the scope below.

## Scope and acceptance
- Run/report permission remains separate from history and review. Supplier NIF is displayed; recipient validation remains mandatory.
- Compact global-style controls, twelve month checkboxes on desktop with unchanged label typography, collapsible instructions, selected-run status and Refresh beside selectors. Preserve edits/open previews during polling.
- Format dates only (dd-mm-yyyy/month, mm-yyyy/month); source folder never becomes invoice issue date. JPEG has no source-PDF control.
- Report originals by format, invoices once per group, resolved invoice tasks as processed, other invoices pending; errors are a subset, not every pending delivery. Remove duplicate count and redundant error list; retain safe duplicate details and operator error details.
- Manager may queue a targeted search of the pending invoice's original month. Only new, matching complementary pages are claimed. Validate complete grouping and fiscal identity before automatic archive/send. Ambiguity stays pending with a reason.
- Preserve old derivative/history and all original sources until replacement confirmed; protect already submitted and other pending invoices; one stable replacement ID across retries.

## Tasks
- [x] Add regression tests for counts/date/issuer/role projection and immediate read-only refresh; implement compact UI/copy.
- [x] Add bounded PDF assembly and tests for order, limits and source preservation.
- [x] Add targeted repair request/worker with durable shared-source lineage, conservative identity/completeness checks and lifecycle tests including mismatch, duplicate pages, retries and confirmation gates.
- [x] Update operational docs; run receipt/PDF/UI/security/regression checks and review diff.
- [ ] Publish branch/PR; require validate + windows-agent; merge and verify guarded deployment SHA and authenticated UI.

## Review focus
No recipient-to-issuer fallback; no false success before TOConline response; no duplicate send on recovery; no unclaimed/mixed source retired; no other pending invoice reprocessed. Synthetic fixtures only in repository. Live financial test requires a genuine missing scan; no fabricated production invoices.
