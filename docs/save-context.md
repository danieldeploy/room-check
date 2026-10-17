# Shared page context

`SessionBar::render()` loads `assets/save-context.js` and its stylesheet in every
authenticated module. New modules using this shared layout inherit same-page
native POST restoration and native `<details>` heading anchoring automatically.
Filters, selectors and pagination use the same component with the attributes below.

## Behavior

| Action | Result |
| --- | --- |
| Native same-page POST | Keep the submit button at its previous viewport position, with form/coordinates as fallback; preserve open sections. |
| Filter or sort | Keep the result area's heading in place if visible; otherwise reveal its start. |
| Pagination or refresh | Show the start of the relevant results, including empty results. |
| Calendar arrows, date or record selector | Keep the calendar or selector at its previous viewport position. |
| Open/close a section | Anchor the activated summary, including when an accordion collapses an earlier section. |
| Navigate to another module, tab or workflow | Normal browser navigation. |
| Field error | Open collapsed ancestors, focus the invalid field and scroll only enough to reveal it. |

Success uses the real server result, displayed in a fixed `role="status"` notice
for **two seconds**, with no close button or focus change. Generic errors remain
visible in a dismissible `role="alert"` notice. The original server message stays
available in the document. Existing validation dialogs retain control of focus.

## Forms and links in existing or future modules

- Native same-page POST forms participate automatically, including dynamically
  inserted forms and `requestSubmit()` calls. Give repeated forms stable IDs or
  `data-save-context-key` values when they lack a hidden action/record ID.
- Add `data-save-feedback="success"` or `data-save-feedback="error"` to the real
  server result message. Do not mark unrelated service/readiness warnings.
- For a save redirect adding a result-only query parameter, declare the exact
  return URL with `data-save-context-return`. Only same-path/origin URLs qualify.
- Mark GET filter/sort forms with `data-save-context="filter"` and
  `data-save-context-target="#results"`. Give the result container a stable ID
  that also exists for empty results. GET forms use native successful controls,
  including the submit button, to match the actual destination URL.
- Mark pagination/refresh links with `data-save-context="page"` and the relevant
  `data-save-context-target`. Independent result lists need independent IDs.
- Mark calendar links or GET selectors with `data-save-context="keep"`.
  `data-save-context-target` can identify a stable calendar/section; without it,
  a GET selector anchors its form. Open details containing the selector persist.
- Automatic selectors must call `this.form.requestSubmit()`, not `form.submit()`:
  the latter bypasses submit events and native validation. Do not monkey-patch it.
- Use `data-save-context="off"` to opt out. Unmarked GET forms/links, downloads,
  modified/new-tab clicks, cross-page actions and credential GET forms remain
  native. Each GET filter/link must declare its intent so future navigation is
  never mistaken for a save. Do not use these attributes on credential forms.
- Async saves keep their existing inline feedback; cancelled events do not leave
  pending snapshots. This component does not replace fetch or confirmation guards.

Example for a future module:

```html
<form method="get" data-save-context="filter" data-save-context-target="#results">
  <label>Status <select name="state"><option value="all">All</option></select></label>
  <button>Filter</button>
</form>
<section id="results">
  <!-- Keep this container when the list is empty. -->
  <a href="?state=all&amp;page=2" data-save-context="page"
     data-save-context-target="#results">Next</a>
</section>
```

## Scope, lifetime and privacy

The single-use snapshot lives in `sessionStorage` for that browser tab, expires
after five minutes and is scoped to the signed-in user. It is saved only when
leaving for an uncancelled native action and consumed by the next shared page.
The exact destination must match. POST validation can also return to the source.

Snapshots contain URLs (including public GET filters), numeric record IDs,
action/DOM identifiers, section openness and viewport offsets. POST field values,
passwords and CSRF tokens are never copied. Do not put secrets in URLs. Storage
errors degrade to normal navigation. GET changes do not transfer old record
disclosures to replacement rows.

Restoration waits briefly for layout/fonts and stops when the user interacts.
It does not override back/forward navigation or ordinary reloads; an explicitly
marked same-URL filter/refresh can still restore its result area. If content is
shorter than the viewport, the browser clamps scrolling to the available height.

Run `npm ci --prefix tests/ui` and `node --test tests/ui/*.test.cjs` for event/DOM
regressions. Geometry is simulated in these tests; also verify a real save,
filter and accordion in the deployed browser.
