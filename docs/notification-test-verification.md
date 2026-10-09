# Owner-authorized WhatsApp verification

- The already paired computer may queue an explicit test only for the active
  gerente owning the existing delegation and configured notification recipient.
  No caller-supplied text, telephone number, template or provider key is accepted.
- Tests use the existing five-parameter invoice template and label themselves as
  tests. They do not create failed invoice tasks, modify documents or alter alerts.
- Requests and provider identifiers stay in encrypted private storage. A stable
  request ID deduplicates retries. A new ID is rate-limited and cannot replace a
  queued or sending request. The existing server cron performs delivery under
  the worker lock; the agent endpoint never sends notifications.
- Before sending, recheck enabled notifications, active gerente, permission,
  delegation and paired-token fingerprint. Revocation, rotation or deactivation
  cancels a queued test. An interrupted or uncertain send is never resent.
- Status exposes only request ID, state, timestamps, fixed result code and an
  optional HTTP status. `accepted` means the provider returned a message ID;
  it does not mean delivered or read. This deployment has no Meta delivery
  callback. Receipt on the authorized owner's phone is separate verification.
- Acceptance checks cover fixed recipient/template, duplicate requests, lost
  pointer recovery, rate limit, owner deactivation, uncertain send and crash
  recovery without retransmission. Live sending follows both required CI jobs
  and confirmation of the installed production commit.
