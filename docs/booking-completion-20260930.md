# Booking completion — 30 September 2026

## Specification

- Behavior: each new collection starts at https://admin.booking.com/ in the fixed
  persistent automation profile. Open Chrome when closed; reuse an existing Booking
  tab or create one. Preserve cookies and follow login, then both properties in the
  same session. Never reset the entry during password, CAPTCHA, SMS or navigation.
- Users/permissions: the owner authorized limited test control delegated to the
  already paired Windows computer and tied to the active `gerente` user. This is
  an explicit encrypted grant, not an administrative browser login. Rotation,
  revocation or deactivation of the manager removes access. No credential export,
  arbitrary command, portal/account selection or user management is exposed.
- Inputs/data: existing encrypted Booking and CAPTCHA credentials, existing map,
  Welcome 1140306 and City Center 539828. Next real collection is July 2026 by
  invoice issue date. Monthly scheduling retains Europe/Lisbon and prior issue month.
- Failures: retain bounded task retries and one CAPTCHA provider attempt per task.
  Unknown challenges remain needs_auth. Do not resubmit after uncertain navigation.
  Enable existing WhatsApp failure alerts only after verifying the stored recipient
  and configuration; delivery must be reported separately from activation.
- Acceptance: mandatory validate/windows-agent checks; inspect published commit;
  verify installed Windows files; complete both July tasks with Chrome initially
  closed, then repeat without duplicates. Test entry selection and timed-out entry
  without retry. Existing SMS bridge remains; method selection has not been observed
  and is not a confirmed blocker. Never claim SMS tested if Booking did not request it.

## Operations still requiring production verification

The control grant is provisioned once by a private, reviewed CLI release step.
It never renews an existing revoked grant. Control requests do not renew the worker
heartbeat, bypass the lease fence, or obtain browser/admin sessions. Test request
IDs are stable and repeated requests return the same result. Status excludes
credentials, OTPs, cookies, invoice contents and complete telephone numbers.

July collection and WhatsApp delivery have not yet been tested with this revision.
Deletion of the existing August server copies is explicitly authorized, but must
be restricted to Booking account 1 and the two properties after inspecting the
actual document list. No Booking source document is to be deleted.

The owner confirms Windows signs in automatically; no Windows login change is
needed. Do not restart the PC during unrelated ZKTeco work.
