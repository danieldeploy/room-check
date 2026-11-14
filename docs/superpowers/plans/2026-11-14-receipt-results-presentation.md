# Receipt result presentation — post PR369

Owner authorized the latest chat variants and mockups. Scope: receipt UI only,
plus safe access to a recorded retained duplicate. No reprocessing, new provider,
permissions changes, invoice mutations or source deletion.

- Compact processing-run card: content-sized selector bounded by viewport, year
  and month on right when space allows. Banner inside card, only for selected
  running execution, with its actual requested months/year. Remove intake
  last-selection/help and standalone status text. Preserve failed-read Refresh
  and scheduled retry countdown without the redundant Status label.
- Report heading shows green Processing with five animated dots, Completed!, or
  Pending for unresolved stopped work. Never confuse other runs with selection.
- History filter to right of counts; only processed/problems choices when stopped.
  Running forces all records (also on the server); transitions update without
  losing open previews/drafts. Keep existing permission and totals contracts.
- Darken only disabled selected checkbox tick, preserve month-label typography.
- Duplicate instruction red normal weight. PDF/JPEG share existing invoice cards
  and authenticated final-archive preview. JPEG has no full-source-PDF action.
  Removed duplicates may preview an explicitly recorded, equivalent accepted
  keeper; never the removed copy, never guessed files or public sharing.

Validation: controller/DOM and JS regressions for selected vs other active run,
filter transitions, banner months, counts, permissions, preserved drafts,
preview safety. Existing receipt/provider tests, required validate/windows-agent,
guarded merge/deploy, installed version and authenticated UI check.
