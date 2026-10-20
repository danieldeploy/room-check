# Booking map validation

## Scope

- Behaviour: reuse one dedicated persistent Chrome profile for login, portal
  inspection and collection. Preserve the existing `controlled-booking-login-chrome`
  directory; its historical name does not mean it must be cleared on each run.
- Permissions: retain Management Hub invoice permissions and the paired Windows
  agent. No additional endpoint, remote-control port or account access is exposed.
- Inputs/data: account 1, its two configured property IDs, encrypted credentials,
  existing SMS channel and the requested invoice issue month. Do not import cookies
  from the older profile or store session values in the map or diagnostics.
- Failures: preserve the persistent Chrome session. Expired authentication uses
  the established credential/2FA flow; human verification requires the owner.
  Missing/unvalidated maps continue to block collection and schedules.
- Acceptance: verify profile routing, credential guards, property identity,
  invoice issue dates, pagination and PDF retrieval before validating the map.

## Current evidence (28 September 2026)

The Hub login test completed after the owner resolved Booking's human challenge.
The diagnostic reported both `login_attempted=true` and
`authenticated_session=true`; the Hub recorded 18:07 Europe/Lisbon. This proves
that login run, not unattended future CAPTCHA handling or invoice collection.

Inspection previously selected a separate primary profile, and collection
launched an ephemeral headless browser. Both must reuse the verified profile.
The portal map remains unvalidated until the document checks above are complete.

Monthly collection uses **issue date in the previous calendar month**, running
on the configured day/time in Europe/Lisbon during the following month. A service
period printed on an invoice must not replace its issue month.
