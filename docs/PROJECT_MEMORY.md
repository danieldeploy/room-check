# Project memory protocol

This repository is the durable technical memory for Management Hub / Room Check.
Chat history can provide context, but it is not the source of truth for current
behavior, architecture, deployment state, or persistent product decisions.

## Read order before changing a module

1. `AGENTS.md` — repository-wide development and deployment rules.
2. `docs/PROJECT_STATE.md` — current operational and implementation state.
3. The relevant document under `docs/flows/` — end-to-end behavior, transitions,
   failure handling and acceptance checks.
4. Relevant ADRs under `docs/decisions/` — persistent decisions and rationale.
5. The current code and tests — executable truth for implementation details.
6. Open/recent PRs for in-flight work that is not yet part of the production branch.

If documentation and code disagree, do not silently choose one. Verify the live
code/configuration and correct the documentation in the same scoped change.

## Skill and code-discovery layer

The repository remains the durable memory. Developer-side Skills and code-index
tools may improve how work is performed, but they do not override repository
decisions or become application dependencies.

For non-trivial work:

- use Superpowers selectively for root-cause debugging, meaningful test-first
  changes, multi-step planning, and fresh verification when those workflows fit
  the actual risk;
- use Ponytail in one-shot review mode when a proposed change adds new surface
  area and should first be checked for reuse/simplification;
- use Grill Me light only for up to three unresolved high-impact decisions after
  factual investigation; full grilling is explicit-owner-only;
- do not make independent second-opinion/reviewer calls a standing default;
- use a code graph/codebase-memory MCP, when already available, only as a
  structural index for symbols/callers/dependencies/impact. Confirm its findings
  in current code/tests. Fall back to repository search without blocking work.

These tools help recover and apply context; they do not replace
`AGENTS.md`, `PROJECT_STATE.md`, flows, ADRs, tests or live verification.

## What must be documented

Update `docs/PROJECT_STATE.md` when a relevant change alters any of these:

- a feature's state: planned, prepared, integrated, deployed, verified or blocked;
- an external dependency, API, credential mechanism or approval state;
- a production workflow, background worker, cron, webhook or paired agent;
- an important operational limitation or pending acceptance test.

Update a flow document when a change alters:

- inputs, outputs or actors;
- state transitions;
- permissions or affected data;
- retries, idempotency, cancellation or reconciliation;
- failure behavior;
- acceptance checks.

Create or supersede an ADR when the owner makes a durable decision that future
implementations must preserve. Do not create an ADR for routine refactors,
formatting, dependency bumps or implementation details that do not constrain
future behavior.

## Status vocabulary

Use these words consistently:

- **Planned** — decision exists; implementation has not started.
- **Prepared** — implementation exists on a branch/PR or is disabled by configuration.
- **Integrated** — merged into the production branch.
- **Deployed** — installed production version has been confirmed.
- **Verified** — the changed end-to-end behavior was tested successfully in production.
- **Blocked** — a named external dependency or missing access prevents progress.

Do not use “done” to collapse these states.

## Diagrams

Complex flows use Mermaid inside Markdown. Keep diagrams small enough to answer:
what triggers the flow, what is authoritative, what state changes, what can fail,
and what happens next.

A diagram is an orientation aid, not a replacement for explicit failure and
acceptance sections.

## CI guard

`docs/project-memory.json` declares architecture-sensitive path groups.
`tools/project_memory_guard.py` checks a PR's changed files against that map.

The guard intentionally ignores purely visual/static and test-only changes.
For mapped runtime domains, the PR must update the required state/flow documents
in the same change.

When a new architecture-sensitive module is introduced, add it to
`docs/project-memory.json` as part of the same PR.
