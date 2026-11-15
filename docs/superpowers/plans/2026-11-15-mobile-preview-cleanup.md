# Mobile PDF preview and retired invoice diagnostics

Owner approved the four-point analysis on 2026-11-15. Execute inline under
AGENTS.md; existing authorization covers PR, guarded deployment and acceptance.

## Specification

- Behavior: mobile PDFs render below Show document/Show complete source PDF,
  one page at a time with page navigation and zoom. Desktop PDF and all JPEG
  rendering remain as implemented (iframe and image respectively).
- Users/permissions: retain the authenticated receipt endpoint and role checks.
- Data: original PDF/JPEG bytes, Drive files, invoice history, processing and
  TOConline records remain unchanged. No compression or filename migration.
- Failure: retain localized retryable messages; cancel and release renderer,
  worker, canvas and requests on close, navigation and removed history nodes.
- Acceptance: real Android Chrome plus desktop checks; automated lifecycle,
  page/zoom, cancellation, error and cleanup regression tests; required CI.

## Implementation and progress

- [x] Add mobile detection and lazy PDF.js rendering using the existing pinned
  6.4.299 dependency. Serve matched module/worker/fonts/cmaps/wasm on this host;
  no external viewer, CDN, account or document upload. Bound canvas pixels.
- [x] Exercise mobile PDF, desktop PDF and JPEG routing, retry, close while
  loading/rendering, page navigation and repeated cleanup in UI tests.
- [x] Remove Azure diagnostic page/class/test/menu and 41 translations; remove
  exclusively obsolete UI strings and deployment-400 diagnostic workflow/tests.
  Preserve dynamically used translations and legacy receipt recovery.
- [x] Add a fixed-list release cleanup after backup, protecting against links
  and path escapes. Private Azure files, if present, get a verified private
  recovery copy before removal from the active vault. Never remove shared keys,
  invoices or other providers. Refuse pending Azure operations.
- [ ] Test asset packaging and cleanup with synthetic directories, including
  symlink refusal, unrelated-file preservation, idempotence and pending tests.
- [x] Update project state, invoice flow, translations and deployment docs.
- [ ] Review diff, run available local checks, publish PR, require exact-head
  validate/windows-agent success, merge, verify deployed SHA and cleanup counts.
- [ ] Verify live phone/desktop viewing. External Azure resource deletion remains
  conditional on inventory and dedicated access; server cleanup is distinct.

## Review focus

Mobile detection must not select a narrow desktop window. Close during import,
fetch or page rendering must not resurrect a preview. Long source PDFs must not
render all pages at once. Rotation/zoom must preserve legibility within a bounded
canvas. Cleanup must not delete symlink targets, active operations or shared data.

## Reuse review

Keep the existing PDF endpoint, disclosure, history lifecycle, pinned PDF.js,
deployment backup and private vault. Add only a small canvas viewer and bounded
release preparation/retirement helpers; no new daemon, service or framework.

Local verification: 57 UI tests pass. PHP asset/retirement tests await the existing Linux CI because this Windows workspace has no PHP runtime.
