# ADR-001 — Repository-backed project memory

Date: 2026-10-28  
Status: **Accepted**

## Context

Management Hub development spans multiple chats, automated agents, GitHub,
Windows automation and external services. Chat memory alone is not reliable
enough to act as the technical source of truth.

## Decision

Use the repository as durable project memory:

- `AGENTS.md` for transversal development rules;
- `docs/PROJECT_STATE.md` for current state;
- `docs/flows/` for end-to-end workflows;
- `docs/decisions/` for durable decisions;
- tests for executable invariants.

Relevant PRs update the corresponding memory documents in the same change.
A CI guard enforces mapped critical domains.

## Consequences

Future agents should be able to recover the current implementation context
without depending on a specific previous conversation.

Documentation becomes part of the change contract and must not contain secrets
or private guest/invoice data.
