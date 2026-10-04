# Temporary TOConline diagnostic resend

## Scope

- Expected behavior: one explicit resend of the original Drive-verified PDF for the single owner-authorized internal document, to obtain a fresh receipt through the existing mail filter. The option expires at 2026-10-06 00:00 UTC (01:00 Europe/Lisbon).
- Users and permissions: Gerente only, existing authenticated invoice page and CSRF validation, explicit confirmation, existing shared invoice-worker lock and remote-agent idle gate.
- Inputs and affected data: server-bound document scope, unchanged configured sender/NIF and original ledger aliases, original PDF hash/size validation. A separate encrypted diagnostic attempt records actor, time, attempt identifier and mail submission result. Normal ledger, receipt outcome, pilot verification and automatic sending remain unchanged. A new email Message-ID retains the original correlation filename.
- Failures: preflight errors send nothing. Persisted intent consumes the option before transport; false/exception/crash must never cause an automatic or manual repeat. Submission is not receipt acceptance. No automatic interpretation of untrusted replies is added.
- Acceptance: synthetic transport tests cover scope, permissions, confirmation, expiry, modified PDF, changed sender, missing ledger alias, prior intent, false/exception, preserved ledger/settings and original deduplication. Required validate/windows-agent checks, guarded deployment, then one authorized UI action. Real receipt and diagnostic JSON remain separate acceptance checks.

## Removal

After the receipt-pipe investigation no longer needs this option, remove the diagnostic form/status, its controller branch, translations and InvoiceToconline diagnostic methods/constants through a reviewed PR and normal CI/deployment. Remove the corresponding temporary feature tests. Preserve the encrypted attempt and normal audit/ledger as history; never delete the attempt to re-arm the action. Expiry and consumption disable execution even before code removal. Do not re-enable automatic sending as part of this diagnostic.
