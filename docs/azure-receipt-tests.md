# Azure receipt connection and diagnostic acceptance

Owner-authorized scope, 2026-11-12: connect existing North Europe F0 Document Intelligence resource and test one two-page invoice and one duplicated invoice pair. No TOConline submission, Drive change or background receipt pipeline migration in these tests.

Implementation: manager-only CSRF-protected settings/test page; encrypted existing InvoiceVault for API key and bounded operation/results; fixed resource endpoint; asynchronous POST once then GET polling, retaining uncertainty instead of blindly resubmitting. Tests accept real PDF/JPEG up to 4 MiB; PDF inspection bounds F0 requests to two pages. Use API 2024-11-30 prebuilt-invoice; expose extracted fields/confidence/page evidence and continuation markers, never claim this model alone certifies duplicates. Keep the owner-approved QR precedence and grouping rules for the later pipeline change.

Checks: endpoint/operation URL restrictions, no redirects, TLS, secret redaction, request uncertainty/repeat protection, bounded output, F0 page limit, safe field projection, actual manager/CSRF endpoint authorization; existing receipt/i18n checks; required CI/deploy, then configured resource and the two actual diagnostic results. No credentials or real invoice contents in git/CI.
