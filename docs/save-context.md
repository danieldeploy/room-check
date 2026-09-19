# Saving without losing page context

`SessionBar::render()` includes `assets/save-context.js` and its stylesheet for
every authenticated module. Same-page native POST forms preserve the viewport
relative to their submit button (form or scroll coordinates as fallback) and
open/closed `<details>` sections. URL filters and tabs stay server-owned: a
redirect to another tab, record, filter or module is normal navigation.

Native validation opens collapsed ancestors of invalid controls. Server errors
marked with `aria-invalid="true"` receive focus and minimal scrolling. Generic
server errors stay visible in a dismissible notice. Native browser validation,
CSRF, confirmation dialogs and fetch-based saves are not replaced.

## New forms

- Native same-page POST forms participate automatically, including dynamically
  inserted forms and `requestSubmit()` calls. Give repeated forms stable IDs or
  `data-save-context-key` values when they lack a hidden action/record ID.
- Add `data-save-feedback="success"` or `data-save-feedback="error"` to the real
  server result message. Its exact text appears in a visible status notice after
  restoration. Do not mark unrelated service/readiness warnings as save results.
- For a save redirect adding a result-only query parameter, declare the exact
  return URL with `data-save-context-return`. Only same-path/origin URLs qualify.
- Use `data-save-context="off"` when a submission intentionally starts a new
  workflow. Login, logout, GET filters, new tabs and cross-page submissions are
  already excluded. Do not override native `form.submit()`; use `requestSubmit()`
  when a programmatic save should participate in validation and this behavior.
- Async saves keep their existing inline feedback; cancelled events do not leave
  pending snapshots. Do not add a global fetch interceptor.

## Lifetime and privacy

The single-use snapshot lives in `sessionStorage` for that browser tab, expires
after five minutes, is scoped to the signed-in user and is consumed by the next
shared page. It contains only URLs, numeric record IDs, action/DOM identifiers,
section openness and viewport offsets. Field values, credentials and CSRF tokens
are never copied. Storage errors degrade to normal submission.

Restoration waits briefly for layout/fonts but stops as soon as the user starts
interacting. It does not override back/forward navigation or ordinary reloads.
Success notices dismiss after six seconds; errors remain until dismissed. The
original server message remains available in the document.

Run the event/DOM regression tests with `npm ci --prefix tests/ui` followed by
`node --test tests/ui/*.test.cjs`. Layout coordinates are simulated there; verify
the real save position and section expansion in the deployed browser as well.
