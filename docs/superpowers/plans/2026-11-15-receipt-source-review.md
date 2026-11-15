# Receipt folder classification and explicit correction

Owner-approved scope: PDF scans are homogeneous (A4 or receipts); keep JPEG behavior.
New intake under temp_scan/<month>/A4 or Talões determines archive pdf/taloes.
Legacy PDFs directly under month retain pdf routing. Every new PDF gets one first-page
Gemini classification; disagreement/uncertainty holds only that source before split/send.
Existing recorded runs are not automatically reinterpreted. Existing QR priority stays.
Review permissions, CSRF, worker lock, idle-only corrections and history must remain.

1. Add first-page classification using existing PDF extraction, Gemini request budget,
cache/retry pipeline, no second API integration. Structured a4/taloes/uncertain result.
2. Carry source category/path during bounded Drive discovery and into child documents.
Hold incompatible PDF source in review; keep JPEG route and legacy history unchanged.
3. Explicitly select Drive path/name/ID for replacement and additional missing pages.
Validate membership and ownership afresh under worker lock. Claim only selected files;
changed/reused/unknown selections reject without writes or sends. Corrections only idle.
4. Retain old history and associate replacements, verify category before new processing;
missing-page repair reuses fiscal identity/completeness/order verification.
5. UI offers preview, explicit file selection, confirmation checkbox and per-document
Process again after work finishes. Others continue. PT/EN and existing roles preserved.
6. Regression tests cover source scope, one-page request/cache, JPEG, mismatch isolation,
explicit repair, replacement identity/idempotency, stale selections and busy gates.
Run required CI, reviewed PR, standing-authorized guarded merge/deployment and acceptance.

State: prepared plan; baseline production b4bcfe3. No real documents sent for testing.
