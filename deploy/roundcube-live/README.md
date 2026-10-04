# Welcome Roundcube production transport

Only `info@welcomehostel.pt` in the separately installed Roundcube 1.6.19 uses
the SMTP2GO MIME endpoint. Normal To/Cc/Bcc, HTML, text and attachments retain
Roundcube's generated MIME and native Sent-folder save. There is no pilot
deadline, fixed subject or fixed recipient list. Other mailboxes keep their
existing transport. The cPanel-owned Roundcube is outside this deployment.

The key remains in its existing private file; its legacy `sandbox-key.txt`
filename does not select provider Sandbox mode. Both controlled live messages
were accepted by the provider; the owner confirmed Gmail, Outlook and Hotmail
Inbox delivery. Production activation still needs a fresh ordinary Roundcube
send and Sent-folder check to complete authenticated acceptance.

Messages are limited to 10 MiB of complete encoded MIME and 100 addresses per
To/Cc/Bcc field. Unsupported DSN and Resent-header sending fail with a clear
message; use ordinary Forward instead of Resent. Retired PILOT/SANDBOX subjects
are refused. Use the verified Welcome sender identity.

Private per-Message-ID journals prevent a second API call after any attempted
send, including ambiguous network outcomes. There is no automatic SMTP fallback
or retry. Check Sent and provider delivery status before composing a replacement
after an uncertain result. Journals contain outcome metadata, no message body,
recipient list or API response. Preserve them across releases and rollback.

## Installation and retirement

The protected `Roundcube production` workflow verifies the exact core, original
configuration, previous packages, private backup permissions and two accepted
pilot journals. It backs up the current configuration, creates the private live
journal directory and installs the three PHP files. Registration is last.

After activation, both known test plugin directories move out of the public
Roundcube directory to `roundcube-smtp2go-private/retired-tests-20261004/`.
Their exact contents and permissions are verified again there. There is no
deletion. Keys, historical journals and private configuration backups remain.
Historical Roundcube test workflows are archived outside `.github/workflows`;
their source helpers remain for forensic verification and reversibility.
No Management Hub TOConline test controls are removed.

## Verification and rollback

Run the workflow on `agent/room-item-assignments` with operation `verify` for a
read-only package/backup/retirement check and public login health check. Neither
proves authenticated sending. Operation `rollback` restores the old plugin
directories before restoring the byte-exact pre-production main configuration;
the exhausted pilot remains exhausted and ordinary messages use the former
SMTP route. It does not erase successful sends or production journals.

On failure, inspect read-only before any retry. Unknown content, permissions,
files, moved-folder duplication or changed core/configuration block mutations.
Only reviewed fixed paths can be written or renamed. Never rerun retired
activation jobs or reset message journals to force another delivery attempt.
