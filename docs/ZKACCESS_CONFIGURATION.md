# ZKAccess 5.3 — configuration lifecycle and event-name consistency

Verified incident: 2026-11-11, Europe/Lisbon. This is an operational record and
an acceptance contract for future Management Hub access-control work. It does
not enable the dormant Cloudbeds PIN automation.

## What was verified

The controller alias and four door names were correct in the ZKAccess database,
but newly collected events still stored the previous door labels in
`acc_monitor_log.event_point_name`. For the same physical door, its stable
`event_point_id` matched the correctly renamed `acc_door.id`. This was not just
a stale browser display or a different controller.

The installed ZKAccess code establishes this lifecycle:

1. The normal door form has a post-save callback,
   `mysite.iaccess.models.accdoor.DataPostCheck`.
2. That callback notifies the running application through both
   `UPDATE_DEVICE_DOOR` and `UPDATE_DEVICE_DOOR_MONITOR`.
3. The collector's `dev_comm_center.appendrtlog` reloads its
   `g_dev_door_dict` through `process_comm_data.get_dev_door` when
   `UPDATE_DEVICE_DOOR` is set. That mapping includes stable door IDs,
   door numbers and names. The collector then clears the notification.
4. `parse_event_to_sql` copies the supplied door name into the event's
   `event_point_name` when inserting the history row. Historical labels are
   stored values, not a live join to today's door name.

The earlier copy script explicitly excluded door names. The four later rename
operations appear in the application's edit audit, under its administrator
account. That does not establish which human/tool initiated them, nor prove
whether a notification was omitted, lost or consumed incorrectly. Do not claim
that direct SQL, SDK use or a specific person caused this incident without
additional evidence. The observed failure was stale event naming after the
configuration itself had changed.

## Recovery that was actually performed

At 18:13:59 on 2026-11-11 the already-correct door configuration was checked
against the intended controller and its four stable door identities. The native
`DataPostCheck` callback was invoked once for each existing door, passing the
same door object as `oldObj` and `newObj` and an empty Django `QueryDict` as
`request.POST`. This executed the post-save notification phase only: **no door
save, direct SQL update, PIN programming or physical-open command was issued**.

In this inspected version, empty form selections skip reader renaming, video
changes and apply-to-current/apply-to-all configuration propagation; the native
callback still emits both refresh notifications and closes its connection.
This is a verified repair for already-correct metadata, not a generic substitute
for validating and saving a new configuration. Reinspect the installed callback
before reusing this procedure on another software version.

An in-memory fingerprint of all database fields on all four doors matched before
and after. No credential values, SQL dumps or snapshots were published. No
service restart was needed for the verified door, and historical events were
not rewritten.

The owner then physically opened door 2 and confirmed the displayed name. A
read-only database check independently found the new event at **18:23:46** with
controller `WLC Main doors` and event point `WLC Main 1st Floor`.

| Door | Current label | Post-repair acceptance |
|---|---|---|
| 1 | WLC Main Street | Refresh requested; no new event verified in this intervention |
| 2 | WLC Main 1st Floor | New event verified at 18:23:46 |
| 3 | WLC Main 2nd Floor | Refresh requested; no new event verified in this intervention |
| 4 | WLC Main Free | Refresh requested; no new event verified in this intervention |

## Rules for future configuration work

- Treat configuration as validation, persistence, dependent-service notification,
  required controller synchronization, and end-to-end readback. Database success
  or SDK parameter readback alone does not prove the event pipeline is updated.
- Use stable controller/door identities and door numbers for mappings; names
  are editable labels and must not become identity or permission keys.
- Prefer the native validated application form/service lifecycle. Raw SQL,
  queryset `update`, or a model `save` in isolation may omit form callbacks and
  dependency updates. Check the actual version rather than assuming they are
  equivalent. An SDK operation on the physical board does not by itself refresh
  ZKAccess's host-side event-label mapping.
- Preserve permissions, door behavior, PINs/passwords/cards and unrelated fields.
  For a metadata-only recovery, avoid rewriting secret fields merely to provoke
  an update. Verify all-field preservation privately; never log their values.
- Inspect form options before invoking a native callback: apply-to-all or
  apply-to-current choices can propagate settings beyond the intended door.
- If native notifications fail, read current service/queue/configuration state
  before any retry or restart. Do not add an always-on watcher, flush all caches,
  patch the vendor binaries or restart services speculatively.
- Record an event baseline before testing and check a **new real event after the
  update** for each affected door. Confirm controller identity, door identity,
  label and time. A pre-existing history row cannot validate the repair.
- Coordinate physical testing with the owner. Do not remotely open a door solely
  to obtain a test event without authorization. If only one door is tested,
  report that scope and leave other doors pending acceptance.
- Preserve historical event rows and their original names. If a future report
  needs the current label, show it separately from the stored historical label;
  do not silently rewrite history to make an unsuccessful update appear correct.
- Future software tests should cover stale collector mappings, refresh after a
  rename, unchanged access/credentials, repeated notifications, multiple doors
  and preservation of history. These are implementation requirements, not a
  claim that the future software/tests already exist.

## Older Django compatibility

The installed Django ORM rejects `AccDoor.objects.filter(device_id=...)` in
this environment. The first diagnostic attempt failed at that query before any
callback was invoked. Use the inspected relationship manager, such as
`device.accdoor_set.order_by('door_no')`, or a verified relation filter. Diagnose
such failures before retrying; do not assume an operation already ran.
