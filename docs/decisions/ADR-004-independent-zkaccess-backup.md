# ADR-004 — Independent ZKTeco backup archival

Status: **Accepted**  
Date: **2026-10-29**

## Decision

The MySQL backup of the ZKAccess 5.3 database is independent of invoice
collection. The AccesControl Windows runner creates at most one scheduled
`zkeco_db` dump per Europe/Lisbon calendar day, initially at 04:00.
The Hub receives and encrypts that dump through the existing paired agent.

**Archival and Drive retention are performed by a dedicated five-minute
cPanel cron worker**, never from `cron/invoices.php`, and use only
`room_check_zk_backups` as their coordination lock. The invoice module keeps
its own lock, portal tasks, existing jobs and alert handling unchanged.

For each ZKTeco backup, the private encrypted manifest preserves
the complete receipt timestamp, the most recent archive attempt and count,
a sanitized failure code when relevant, and the verified Drive read-back time.
The `uploaded` state requires re-reading the encrypted object and verifying
integrity; simply starting upload does not count.

## Rationale

On 2026-10-29 the dump was created around 04:01 in Windows but did not appear
on Drive until 17:47. At the time of investigation, the archive code was
coupled to the invoice cron, which may be busy or delayed, and the cPanel
quota was under pressure. The exact historical blockage could not be proved
because the three transition timestamps were missing.

## Boundaries

No new credentials, no change to the ZKTeco controller SDK or PIN automation,
no change to the original invoice cron schedule, no automatic deletion of
invoice data and no silent bypass of protected deployment/WHM checks.
Drive outages or full cPanel storage may still delay delivery; the archive
remains recoverable and retriable when safe private storage is available.

Supersede this ADR if the owner later approves a different archival
architecture or an explicit cross-module scheduler.
