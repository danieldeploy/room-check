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

- Owner instruction, 2026-10-20: use the direct Hub OAuth/MCP connection for its
  supported operations once the owner has activated it in ChatGPT; do not require
  installing ChatGPT on the Windows PC. See `docs/HUB_CONNECTOR.md`. Verify the
  connected identity with `hub_get_profile` and respect its granted scopes. An
  installed endpoint is not proof of an activated connection. The connector's
  renewal/revocation belongs to the platform OAuth flow; never print, export or
  manually copy its tokens. It does not create a browser session or replace a
  visual UI acceptance check. Keep using the existing secure browser recovery
  order below for browser-only tasks.

- Owner instruction, 2026-10-05: an expired Management Hub session during an authorized task is recoverable. Retry sign-in through the project's existing secure access method and continue the task; do not stop or ask the owner to repeat routine login while approved reusable access is available.
- Never request, expose, or commit passwords, one-time codes, or tokens. If the secure method is unavailable or invalid, try the other already-authorized project control path for the task, then report the specific blocker. For a visual UI acceptance check, restore the signed-in page and observe the live update without refreshing; an API task result alone is not proof of visual behavior.

- Owner instruction, reaffirmed 2026-10-15: opening and operating the Management Hub for the authorized project task is already authorized. Do not ask again merely because a new chat or browser session has started.
- Recovery order: reuse the existing authenticated administration tab/profile first; use the supported secure saved-credential mechanism if available; then use an existing authorized API or paired-agent control only for operations within its actual scope. Do not log out, clear cookies, replace profiles, or inspect/export saved passwords or session tokens to recover access.
- Historical access used an existing signed-in Hub session or the secure login flow. An administration Chrome profile was reported as persistent and separate from the Booking automation profile; verify its availability in the current environment rather than assuming profiles or saved credentials are shared with Cloud Browser. Owner permission to retain access is not proof that reusable credentials were configured.
- The paired Windows agent's delegated test control and the protected WHM deployment API are not administrative browser sessions. Never expand their scope or use them to evade a browser protection or approval denial.
- If user input is genuinely necessary, identify the exact service/account and present its secure login in the same turn; do not end with an offer that requires another request. Respect a declined login and platform recovery limits. Continue independent authorized work, and state the exact remaining blocker. After successful authentication, continue the original task immediately rather than ending at login confirmation.

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

