# Booking identifier request comparison

The existing manual HAR can be reduced offline with:

```sh
node invoice-runner/booking-login-har-metadata.mjs /path/outside/repository/manual.har
```

Keep the original HAR outside the repository. The command prints a small,
value-free JSON report for up to three exact HTTPS
`account.booking.com/account/sign-in/login_name` responses. Invalid HARs print
only `invalid_har`. It does not contact Booking.

For a Booking **login-only** task, the Windows agent saves the same request
categories to `task-<id>-booking-login-metadata.json` in its existing private
data directory. This report includes booleans for a challenge visible before
and after submitting the identifier. `null` means the observation was not
reached. The agent removes the report from the task result before contacting
the Hub, and its local `task-<id>-receipt.json` says
`booking_login_metadata_saved: true` or `false` when a report was attempted.
The report is never placed under `public_html`, in the repository, in the Hub,
or in a PR. `*.har` and these report filenames are ignored by Git as an
additional guard.

To compare the original manual 200 with an automated 405, compare only
`method`, `resource_type`, request/response content types, `origin_host`,
`referer_host`, the presence/types of the six named body keys, `sec_fetch`,
and `x_requested_with_present`. The value of a key or header is never needed.
The current human challenge prevents a new identifier POST; this diagnostic
can only capture an automated request when the existing Booking sign-in tab
becomes usable after human verification.
