# TOConline direct mail pipe

## Specification
- Behaviour: receive relevant mail from cPanel STDIN, extract bounded correlation evidence into an encrypted private queue, process with the existing cron and conservative receipt rules. No mailbox password or IMAP connection.
- Permissions: private CLI executable 0700 outside public_html; Gerente sees reception counters. No web ingestion endpoint, commands from mail, changes to sender or automatic outgoing activation.
- Data: maximum 256 KiB message, bounded MIME depth/parts; attachments ignored. Only full correlation hashes, duplicate hint and timestamps are retained, encrypted in the existing vault. The original message stays in the mailbox, not in Hub storage/logs.
- Failures: silent exit 75 on queue/storage/parser failure requests transport retry; durable queue survives worker/DB failure. Queue limit 200; worker processes 50 per run. Sender headers are untrusted, so no incoming message automatically confirms acceptance. Duplicate processing is idempotent and terminal outcomes are preserved.
- Acceptance: synthetic plain/base64/quoted-printable/multipart messages, no attachment ingestion, unknown/duplicate sender, legacy filename, malformed/oversized inputs, encrypted queue, replay, worker failure/recovery and the real silent CLI entry point. Required CI, guarded deployment and a live delivery test remain separate gates.

## Installed program
The reviewed release copies cron/toconline-reply.php to:

`/home/welcome/room-check-private/cron/toconline-reply.php`

It sets 0700 and lints with the hosting PHP interpreter. The script has the executable shebang `#!/usr/local/bin/php -q`. It derives the known sibling `public_html/check` application path from its installed location and uses the existing vault configuration. Do not move it into public_html. No cron entry is added: existing invoice cron drains the queue.

## Filter (only after successful deployment)
In the existing Daniel mailbox, create a uniquely named filter `Management Hub - TOConline replies`:

- Rule: From / equals / `no_reply@toconline.pt` (verify the filter tester matches the actual From field; display names may require the narrowly scoped contains equivalent).
- Action: Pipe to a Program, relative path `room-check-private/cron/toconline-reply.php`.
- Additional action: Deliver to Folder, use the folder picker to retain a mailbox copy (INBOX is acceptable).
- Inspect the order of existing rules: a prior Stop Processing Rules can prevent this filter running. Do not overwrite or remove existing rules.
- Do not choose Discard/Reject, Fail With Message, or Stop Processing Rules for this new filter. Avoid multiple rules piping the same message.

The exact envelope/rendered From handling and retained-copy behaviour must be tested with cPanel's filter test before activation. Do not claim that visible Pipe support alone proves successful execution. A synthetic direct STDIN test proves code execution, not actual Exim delivery. Observe a real TOConline reply, verify mailbox copy and Hub counter/association. Do not send another production invoice merely to test a pipe. A legacy invoice.pdf receipt remains unmatched unless References identify the original Hub message.

## Operational boundaries
The pipe is independent of the mailbox password, but depends on the filter, hosting mail transport, executable permissions, PHP, vault and cron. Keep mailbox originals for investigation. No inbound third-party service or subscription is added. Existing IMAP remains optional and should stay disabled when this route is used. Preserve vault and queue with operational backups; deployment code backups are not complete vault backups.

Matching a claimed From address is not authentication. The processor reuses the exact-identifier review policy, never promotes a receipt to accepted/existing by itself, never enables outgoing mail, and never resends an invoice. Future automatic acceptance requires independently validated sender authentication and response templates.
