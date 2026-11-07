# ADR-005 — WhatsApp alerts for automation exceptions

Status: **Accepted** (requirement; universal implementation is **Planned**)

Date: **2026-11-07**

## Decision

Every existing and future project automation must surface detected unexpected
exceptions, execution errors and blocked or unverified completion as a durable
incident and a WhatsApp alert to the already configured manager. Scope includes
invoices/scans, archival, external confirmations, backups, maintenance, Windows
tasks and other project automations. This change records the rule only.

## Incident handling

- Identify the automation and execution/incident, relevant account/property,
  Europe/Lisbon time, sanitized problem summary and next action.
- Deduplicate repeated observations and attempts for the same incident. Preserve
  explicitly approved retry budgets, timing and escalation thresholds, including
  Drive retries and WHM quota levels; do not send one message per retry.
  Required human intervention and final failures must not disappear silently.
- Expected no-work, unchanged-state and successful outcomes are not errors.
  Preserve incident history when recovery occurs; distinguish recovered/failed.
- Reuse the existing transport and recipient. Notification failure must not
  erase the original incident or turn failed work into success. Record uncertain
  sends separately and reconcile before any resend that could duplicate them.
  Provider acceptance is not confirmed handset delivery.
- Keep credentials, PINs/OTPs, raw logs, guest details and private document
  contents out of alerts; use existing protected records for diagnostics.

## Template suitability

The latest confirmed WHM template, `whm_storage_alert_v1` (`pt_PT`, three
parameters), includes fixed labels `Estado do servidor`, `Problema identificado`
and `Ação recomendada`, and states that a detailed report is stored privately
on the server. Approval/activation evidence is recorded in
[the WHM flow](../CPANEL_DISK_MAINTENANCE.md) and
[PR #332](https://github.com/danieldeploy/room-check/pull/332).
`WhatsAppDiskHealthNotifier` currently accepts only WHM incidents.

That wording fits server incidents; it must not be presented as a universal
template or used to claim a report exists when it does not. Invoice/translation
templates also retain their specific purposes.

A separate general automation-error template is **recommended**, using fields
for automation, context/time, problem and required action. One suitable approved
general template can serve multiple automations; one per automation is not
required. Exact text/name, submission, approval and live integration are pending.
This ADR does not create, submit, enable or test any Meta template.

## Acceptance and limits

Before claiming universal coverage, inventory each automation and its exception,
blocked, timeout and incomplete-result paths. Verify normal success/no-work,
unexpected errors, final failures, human intervention, retry deduplication,
recovery, notification failure and delivery to the existing recipient.

A process cannot reliably report its own complete host/network outage. Missed
executions need an independent observer, reusing existing monitoring where
available; keep uncovered failures explicit. This rule does not guarantee
detection of every possible future failure or introduce a new daemon/service.
