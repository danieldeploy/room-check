# Management Hub — instructions for future development

This repository powers `check.welcomehostel.pt`. The production branch is
`agent/room-item-assignments`; `main` is not the deployment branch. Read
`docs/AUTOMATED_DEPLOYMENT.md` before changing release behavior.

## Scope and decisions

- For each requested change, establish five points in a short specification:
  expected behavior; users and permissions; inputs and affected data; failure
  handling; acceptance checks. Infer answers from the request and existing
  project decisions. Ask the owner only when a genuinely missing decision
  changes the outcome; do not request routine technical approvals.
- Preserve existing modules and the application's transversal conventions for
  roles, bilingual content, shared form behavior, and sensitive data.
- For invoice collection, group by invoice **issue date** in the selected
  calendar month, irrespective of service period. For an enabled monthly schedule,
  run on the account's chosen day and time in `Europe/Lisbon` during the following
  month, collecting invoices **issued in the previous calendar month**. Preserve
  one scheduled request per account, property, and run month, including across
  daylight-saving and year changes. Do not substitute the service month for the
  issue month: an August service invoice issued in September belongs to the
  September issue-month collection, normally scheduled in October. Preserve
  the existing Airbnb export-month exception unless the owner requests a change.
- Reuse credentials configured in the encrypted Management Hub vault and the
  established 2FA channel. Do not ask the owner to enter portal passwords in
  chat, add credentials to URLs, or log in through a browser for each run.
- Use one transversal portal-map diagnostic for Booking and future portals. Record
  only structural selector hints and allowlisted URL components in private storage;
  never capture field values, OTPs, cookies, response bodies, invoice contents or
  raw URL query tokens. Diagnostic output is always an unvalidated draft. A map
  becomes valid only after authenticated login, property/account checks, date
  extraction, pagination and document retrieval have been tested on that portal.
  Keep each portal's selectors and exceptions separate within the common workflow.

- Owner instruction, 2026-10-04: retain the Management Hub TOConline test button
  throughout diagnosis. Do not remove or automatically expire it before the real
  end-to-end automation has been verified and the owner explicitly asks for its
  withdrawal. Keep manual confirmation, repeat protection and private test history.

## Management Hub session recovery

- Owner instruction, 2026-10-05: an expired Management Hub session during an authorized task is recoverable. Retry sign-in through the project's existing secure access method and continue the task; do not stop or ask the owner to repeat routine login while approved reusable access is available.
- Never request, expose, or commit passwords, one-time codes, or tokens. If the secure method is unavailable or invalid, try the other already-authorized project control path for the task, then report the specific blocker. For a visual UI acceptance check, restore the signed-in page and observe the live update without refreshing; an API task result alone is not proof of visual behavior.

## Changes and review

- Work on a separate branch and open a PR targeting
  `agent/room-item-assignments`. Avoid direct pushes and force pushes to that
  branch. Keep changes scoped and add tests for meaningful behavior or risks.
- Required PR checks are `validate` and `windows-agent`, provided by GitHub
  Actions. Merge only after both pass against the current branch. The `deploy`
  job runs after merge and must not be required on PRs.
- Never put passwords, 2FA codes, tokens, or production invoice contents in
  source, fixtures, logs, or PR descriptions. Keep deployment secrets in the
  protected `management-hub-production` environment.

## Release and verification

- Standing owner authorization, reaffirmed on 2026-10-01: for requested work
  in this Management Hub project, merge reviewed PRs after `validate` and
  `windows-agent` pass, publish through the guarded automatic deployment, and
  perform the necessary production acceptance checks without asking again for
  routine merge or deployment permission. The owner also authorizes controlled
  WhatsApp alert tests to their existing configured recipient when necessary
  to verify the requested workflow. This authorization remains in effect until
  revoked; it does not authorize unrelated work, new recipients, or bypassing
  platform approval review, required checks, or deployment safeguards.
- Source publication authorization, reaffirmed on 2026-10-02: the owner authorizes
  publishing scoped source, test, and documentation changes for requested
  Management Hub work to `danieldeploy/room-check`, currently a public repository,
  without asking for routine publication approval for each change. This remains
  valid until revoked. It does not authorize publishing credentials, private
  invoices, unrelated data, or bypassing platform approval review.
- When the request authorizes a change, complete implementation, CI, PR merge,
  and the automatic production deployment without asking for repeated cPanel
  login or permission at each routine step. `HUB_DEPLOY_ENABLED=true` may stay
  enabled. The deployment environment must allow only the production branch.
- Respect the guarded WHM deployment, backup gate, exact commit checks, and
  serialized deployment in `docs/AUTOMATED_DEPLOYMENT.md`. Do not bypass them
  with an arbitrary upload or an unreviewed server edit.
- After deployment, verify the installed commit and the affected behavior in
  production. For automated portal workflows, verify the relevant complete
  cycle when access is available. Report separately: integrated, deployed,
  and functionally verified. Never claim an authenticated workflow was tested
  based only on the public login health check.
- If a deployment fails or its outcome is uncertain, inspect server state
  read-only before any retry. Do not repeat a mutation blindly. Diagnose,
  repair, rerun appropriate checks, then publish and verify. Prefer a reviewed
  revert for compatible code; data restoration needs separate verification.
- Report what changed, tests run, production commit, functional result, and
  concrete unresolved limitations. Ask the owner only for a genuinely new
  product decision, access that cannot be recovered from existing secure
  configuration, or an unapproved external action.

