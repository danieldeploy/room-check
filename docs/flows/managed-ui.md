# Unified UI cleanup on AccesControl

## Owner rule and actual coverage

The owner's definition of automation is everything not opened manually by them.
Safe enforcement still requires positive provenance at creation: unknown or
pre-existing windows must never be treated as automatic by subtraction.
This implementation covers registered Chrome tabs and Explorer windows. It is
not universal interception of ChatGPT, remote plugins, cloud browsers or Windows.
Generic other-program closure was not installed after a safety-layer refusal.
AnyDesk connections and essential PowerShell/Node/ZKAccess services stay protected.

## One lifecycle, not two competing cleaners

`begin -> open/register -> touch or busy lease -> finish`

Normal completion closes safe owned resources immediately. If work is abandoned,
a conditional Windows scheduled task checks registered resources every 30 minutes
and closes only those with at least 30 minutes of verified inactivity. A resource
may remain 30–60 minutes after last use. The registry atomically coordinates
creation/touch/cleanup and task enabled state. Empty or protected-only registry
means the checker is disabled; next eligible registration enables it. The checker
runs hidden and ends after each pass; no resident daemon is introduced.

## Safety checks

- Browser identity pins profile, browser instance and CDP target ID, not URL/title.
  Event listeners record trusted user interaction without polling or recording
  text. Loading, editable values, playing media, CAPTCHA/OTP/password pages,
  missing instrumentation and unreadable state prevent closure.
- Before closing, recheck activity/safety. A beforeunload dialog is not bypassed.
  Disconnect CDP only; do not clear cookies, log out or terminate persistent Chrome.
- Explorer identity pins HWND, process/start time and folder; navigation or
  identity changes remove ownership rather than close the window. Global session
  input and CPU/Busy checks are conservative and can delay cleanup while the
  owner is using another program. No claim of exact per-window idle sensing.
- Active jobs must use activity renewal/busy leases or an exact live owner binding.
  A completed task is not the same as an idle process. Unknown application state
  remains open. No taskkill, generic WM_CLOSE or Explorer process termination.
- Corrupt registry, failed scheduling, ambiguous ownership and failed closure
  are surfaced or preserved, never silently treated as success.

## Integration and migration

The invoice runner awaits registration before navigation. `shared-ui-bridge.mjs`
loads only the fixed local path after a `version.json` marker confirms v2.
Supported installations delegate to one cleanup engine. Missing/unavailable
helpers retain the prior local completion fallback, not a second background scan.
Installed binaries/profiles and invoice authentication are unchanged.

Install staged, reviewed source with an exact-SHA rollback copy. Legacy v1 task
records must first be checked: do not delete unresolved tasks. The existing
Explorer adapter is reused; old entrypoints are replaced only after acceptance.
Install the disabled scheduler first, then write the v2 marker last. Confirm no
manual or unknown window has been enrolled. Do not restart existing agents or
remote connections for this migration.

PowerShell usage (local path, no secrets):

```powershell
$ui = Join-Path $env:LOCALAPPDATA 'ChatGPTAutomation\ManagedUI\managed-ui.mjs'
node.exe $ui begin
node.exe $ui open-tab TASK_ID 'https://example.com/'
node.exe $ui open-explorer TASK_ID 'C:\folder'
node.exe $ui touch TASK_ID busy
node.exe $ui finish TASK_ID
node.exe $ui status
node.exe $ui scheduler
```

## Acceptance

Unit tests cover ownership, activity timestamps, active leases, protected resources,
unknown state, registration concurrency, missing browsers, ambiguous closes,
and scheduler start/stop. Windows tests use a disposable Chrome profile, synthetic
input and an empty test folder, never production portal forms. Test actual hidden
scheduler execution and enabled/disabled readback after installation. Verify the
invoice bridge and fallback separately. Report integration, installation and
functional checks as separate states; do not infer universal coverage.
