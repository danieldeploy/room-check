# TOConline email archive

## Scope
- Behaviour: send validated Booking PDFs only after verified Drive archival, using the hosting mail transport. A pilot invoice must be acknowledged before automatic sending is enabled.
- Permissions: only Gerente configures the destination, requests a pilot or records a TOConline acknowledgement; CSRF and the invoice worker lock protect changes.
- Data: company NIF, authorised sender, document IDs and encrypted delivery ledger in the existing private vault. No credentials or documents in logs. Existing Drive retention stays unchanged; PDFs are fetched privately and checked against their stored SHA-256 before sending.
- Failures: no automatic resend after mail submission starts. Unknown outcomes require reconciliation. Pre-send download failures remain retryable on the next cron. Local mail acceptance is not TOConline acceptance.
- Acceptance: duplicate content and Booking invoice identities cannot be submitted twice, including across accounts; crash recovery does not resend; altered PDFs are rejected; sender/destination changes invalidate the pilot; all UI copy is PT/EN.

## Delivery contract
The hosting PHP mail transport queues a MIME PDF attachment to NIF@my.toconline.pt. Its successful return only means that the local mail service accepted it. Delivery, sender authentication and TOConline archival must be verified with an actual pilot and the returned TOConline receipt before enabling automation. No SMTP credentials are copied from another project.

The encrypted ledger is authoritative for attempted submissions. It is written before calling the transport, then mirrored to invoice_document_delivery.toconline_state. The cron and manager actions share room_check_invoices. Backups already include the private vault. Do not remove the ledger to retry: it prevents duplicates. Records are scoped to the recipient company; changing the sender does not erase them.

The first version does not read the mailbox or reconcile invoices submitted outside this Hub. A manager can record acceptance from the TOConline receipt; this is labelled as manual confirmation, never automatic verification. Uncertain submissions require investigation, not a blind resend. Airbnb CSV conversion remains outside this delivery route.
