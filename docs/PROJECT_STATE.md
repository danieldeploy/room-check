# Management Hub — current project state

Last reviewed: 2026-11-06 (Europe/Lisbon).

## Required continuation: existing project state

**Read [PROJECT_STATE_BASE_20261106.md](PROJECT_STATE_BASE_20261106.md) together
with this file.** It preserves the complete previous state document byte-for-byte
(Git blob `210738a9356f4c6a6f721b673bf6040809dbc98c`), including the concurrent
multi-page receipt update, log maintenance, invoices, Cloudbeds/PINs, backups,
deployment evidence and all outstanding limitations. No unrelated module state
is removed or implicitly declared complete. This index contains the later
UI-cleanup decision; it supersedes only older Chrome/Explorer paragraphs in that
required continuation. Future module changes must update this index and the
relevant flow, preserving the continuation.

## Unified task-owned UI cleanup — 2026-11-06

Owner-authorized intent: cover resources not manually opened by the owner;
close eligible temporary resources on completion and recover abandoned work
with a **30-minute idle threshold and 30-minute conditional Windows check**.
Unknown/pre-existing windows are not proof of automation ownership. Never close
manual windows, active AnyDesk connections, permanent PowerShell/Node backends,
ZKAccess, transfers, unsaved work or uncertain state. Profile/cookies remain intact.

The existing local helper is evolved in place at
`%LOCALAPPDATA%\ChatGPTAutomation\ManagedUI`. One lock-protected registry
supports normal completion and idle recovery; a hidden Windows task is disabled
when no eligible registered resources remain and re-enabled on registration.
No new resident service, global window sweep, cPanel cron or forced process kill.

Implementation coverage is **registered Chrome tabs and Explorer windows**.
Arbitrary-program closure was blocked by the tool's safety layer and is not
included or worked around. Other applications remain protected. Other plugins
or ad-hoc commands that bypass registration are not automatically intercepted.
The account's global ChatGPT Custom Instructions are not changed by this PR.

The invoice runner now awaits registration of its newly created controlled tabs
and delegates cleanup to the installed v2 helper. A marked v2 installation is
required before loading it. Older installations retain the previously tested
fallback; when v2 is available only the shared controller performs cleanup.
Human-verification pages are explicitly protected. Ad-hoc tool work uses the
same begin/open/touch/finish interface. See [the full flow](flows/managed-ui.md).

Verification before integration: 30 deterministic regression tests passed;
isolated Windows acceptance closed an artificially aged test tab and an empty
Explorer test window, preserving pre-existing tabs, a synthetic draft, AnyDesk,
the running invoice agent and ZKAccess. This is not proof of all real portals.

**Status: prepared in a feature branch.** Required GitHub CI, merge, final installed
hashes, actual scheduler activation/deactivation and invoice bridge smoke test
remain acceptance gates. Do not report the 30-minute checker as installed until
its Windows task and local version are read back. Normal cPanel guarded deployment
is unchanged and its installed commit is verified separately.
