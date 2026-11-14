# Receipt review and run state implementation plan

**Goal:** Implement the owner-approved post-PR368 corrections without replaying existing invoices.
**Architecture:** Reuse the encrypted receipt workflow, review permission, worker lock, authenticated TOConline outcome and shared UI. One validated review submits all mandatory details and explicit buyer confirmation together. No new dependency, service or credential.
**Spec:** The owner's agreed list following the 14 November PDF, including the final button/message and filter placement decisions.

## Scope and acceptance
- Users: existing `receipts.run`, history and review permissions remain separate; delegated review has the same action as manager review.
- Inputs: issue date, supplier name, invoice number and supplier NIF. Empty fields show red DD/MM/AAAA, Input company name, Input invoice number and Input NIF placeholders. Buyer approval classifies against the configured company NIF, preserving original evidence. Fiscal QR fields remain locked.
- Transition: editing never submits. Confirm and continue enables only after valid mandatory fields and confirmations. The locked POST validates again and queues one document through the existing pipeline. No partial buyer approval bypass; no deletion action after buyer approval. Known missing pages/grouping retain their dedicated recovery and cannot be dismissed by ordinary review.
- Feedback: one compact list of actual unresolved problems; processing banner exists only while automatic work remains, disappears on stop, and updates with status. Provider waits count as automatic work; original retry schedules remain unchanged.
- Intake: disable year/months/start during automatic work globally, with the same server gate. Manual-only pending work allows another run, skipping already recorded scans.
- Run selector: Running/Pending/Completed from real execution state; execution year/month filters on its right, current Lisbon period by default, preserved on polling/pagination. Explicit run links retain that run's execution period. Intake Year caption sits left of its field. Global styles and month-label typography remain.
- Report: preserve compact PR368 invoice counts and format breakdown; no extra invoice count/source-PDF label for JPEG parents.
- Preserve portal workflows, source movement/Trash proof, permissions, translations, date routing and deferred mobile PDF behavior. Deploy does not submit or delete real documents as test fixtures.

## Review focus
- Missing date and supplier NIF after buyer confirmation must not produce a false processing flash.
- Forged POST, changed QR fields, stale content/destination or repeated click must not queue unsafe work or erase audit evidence.
- Active TOConline wait, another selected run, provider retry and worker delay must not enable a conflicting intake.
- Historical/filter-empty runs and Lisbon month boundaries must preserve correct selection and not leak other runs.
- Polling must preserve drafts/previews while updating counters, activity, run labels and intake controls.

## Tasks
- [x] 1. Add failing lifecycle/endpoint tests for combined correction and complete field diagnostics. Implement in InvoiceReceiptAnalysis, InvoiceReceipts, receipt-history and receipts POST handling; preserve missing-page recovery. Run buyer/analysis/repair/report suites.
- [x] 2. Add failing progress tests for TOConline blocking and run state/period filtering. Implement in InvoiceReceiptReport and selectors/JSON; verify active/manual/completed and same-month restart behavior.
- [x] 3. Add failing browser tests for all-field/button validation, no auto-submit, field locking, state labels/banner removal and filter retention. Implement receipt-review.js/status.js and scoped global-style layout. Run UI and endpoint suites.
- [ ] 4. Update workflow/state documentation, review entire diff, run PHP/JS regression checks. Commit/PR to agent/room-item-assignments; require validate and windows-agent, guarded merge/deploy, installed SHA and authenticated desktop acceptance.

## Execution record
- Baseline: production 71f88b25, isolated existing worktree, new branch agent/receipt-review-run-state. Root causes traced in code and owner PDF. No existing document state is modified by this plan.

- Local acceptance: 24 targeted PHP suites (including native PDF bridge with the configured local Node), 50 browser tests. One initial PDF test invocation used a nonexistent `/usr/bin/node`; rerunning with the actual runtime passed. Synthetic controller POSTs verify atomic corrections, delegated review, duplicate click rejection and Running/intake locks. Full required CI and deployment remain pending.
