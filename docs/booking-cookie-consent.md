# Booking cookie consent

- Behaviour: on Booking login, reject optional cookies using the observed
  OneTrust reject button, then wait for its banner and overlay to disappear.
  Allow a bounded initial wait for delayed loading and recheck before submission.
- Permissions: the existing paired Windows agent and invoice permissions apply.
  Do not change credentials, portal security settings, or the approved invoice map.
- Inputs/data: only visible control structure on Booking's three known login and
  extranet hosts. The diagnostic/receipt adds one boolean, never cookie values.
- Failures: an ambiguous control, a failed click, or an overlay that remains
  interrupts the attempt. Do not accept optional cookies, remove the overlay,
  repeat a credential submission, or solve a human verification automatically.
- Acceptance: cover delayed appearance, no banner, hidden/ambiguous controls,
  foreign origins, failure to dismiss, and continuation of login. Exercise the
  production handler with a synthetic page in sandboxed Windows Chrome; then
  verify a real clean-profile Booking login separately from CI.
