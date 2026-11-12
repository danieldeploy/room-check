# Receipt processing completion

Scope: Management Hub only. Owner authorizes implementation, merge, deploy and live recovery; mobile PDF rendering remains deferred. Production baseline e14ca03482a21f9185f2da8ed803859e732e8f1d.

## Binding behavior
- Local fiscal QR is authoritative for issue date and fiscal identity. Gemini resolves unreadable documents and PDF structure; Azure stays available for manager diagnostics, with no additional automatic paid request.
- Group related pages before splitting. Every input page belongs to exactly one invoice/copy or an explicitly unresolved group. Preserve exact original visual pages and hashes.
- Route actual issue year/month to `pdf` or `taloes`; selected folders bound intake only. Never resend an uncertain TOConline submission. Delete only a new duplicate after authenticated external confirmation.
- Keep unresolved invoice pages available. Retire a source only after all its groups have verified durable copies; retain whole source when grouping is unresolved. Reconcile legacy unsent pages before replacing them, and never modify already submitted/accepted history.
- Manager may review/history. Governanta run permission exposes totals, duplicate issuer/number/date and safe errors only. Existing optional grants remain.
- Five-second read-only status refresh, processing animation, truthful retry countdown, distinct completed/pending/error states and inline bold totals. Existing desktop previews stay intact.
- Existing queue, advisory lock, private encrypted vault, Drive client, TOConline transport/ledger and approved invoice alerts are reused. No new service or cron.

## Tasks and checks
1. [ ] Reproduce and fix unauthenticated TOConline receipt Drive reads; classify transient/auth/quota/ACL errors safely. Tests must fail without connect.
2. [ ] Add bounded local QR/page analysis and exact arbitrary-page extraction using existing PDF tooling plus a pinned QR decoder. Synthetic QR, rotated pages, multi-page preservation, limits and malformed inputs; deployment probe before copying code.
3. [ ] Add encrypted Gemini config, fixed model/host, schema validation, bounded retries/budget and cached results. Test QR precedence, complete coverage, grouping/duplicates, missing pages and invalid AI fields. Never trust model instructions from invoice content.
4. [ ] Integrate grouping and durable recovery manifest into current receipts queue. Tests cover crash/resume, unsent legacy reclassification, changed source hashes, incomplete groups, manual correction, verified per-source cleanup, duplicate preservation and at-most-once email.
5. [ ] Add read-only status/role-safe report polling and countdown, manager configuration/recovery UI and existing invoice exception alert channel. Tests cover permissions, no document leakage, no polling mutation and final states.
6. [ ] Update durable docs; run required suites, review complete diff; PR to production, required validate/windows-agent checks, merge and confirm installed SHA. Configure existing securely stored key through manager UI. Reconcile current batch and observe live results without claiming sent mail is accepted.

## Interfaces and review focus
`InvoiceReceiptPdf`: split remains compatible; inspect and extract preserve source-page mapping and hashes.
`InvoiceGemini`: settings and budget in InvoiceVault; only structured bounded result leaves transport.
`InvoiceReceiptAnalysis`: local inspect + QR parse + optional Gemini -> validated manifest, no external writes.
`InvoiceReceipts`: sole state mutation owner under room_check_invoices; stable IDs, per-source manifest and recovery. Legacy processing paused until analyzer configured.
Review: all pages accounted, contradictory QR identities cannot merge; no deletion or replay from AI confidence alone; no secrets in diagnostics, repo or browser output; preservation of unselected files and historical accepted documents.

## Implementation rulings
- Existing pdfjs-dist already includes native canvas; use a small separate receipt-runtime npm package so cPanel does not reinstall the retired Puppeteer browser. Pin lockfile; no postinstall scripts. Probe actual runtime before deployment copies.
- Retry countdown reflects persisted eligibility; cron remains the existing minute worker. Short API retries can occur within the bounded worker, later retries wait for next cron.
