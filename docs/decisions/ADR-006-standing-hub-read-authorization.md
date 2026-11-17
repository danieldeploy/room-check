# ADR-006 — Standing Management Hub read authorization

Date: 2026-11-17  
Status: **Accepted**

## Context

The owner explicitly authorized consultation of everything done in Management
Hub in the past and from now onward, until the owner cancels that permission,
and requested that this decision be kept in project memory.

## Decision

- Read-only consultation covers all Management Hub modules and historical and
  future records/activity, including executions, errors, attempts and actual
  archive, delivery and confirmation states.
- This standing authorization persists across chats and sessions until explicit
  revocation. Do not ask the owner to reapprove routine consultation. Reuse the
  authenticated connector and other existing authorized access for supported
  operations; follow the recovery rules in `AGENTS.md` when needed.
- Distinguish owner consent from technical capability. Check the identity,
  granted scopes, available tools and session validity. A missing tool or expired
  session does not cancel consent, and consent does not create that capability.
  Report a concrete access blocker only after reasonable authorized alternatives.
- This read authorization does not itself authorize writes, retries, sending,
  deletion or scope expansion. It does not revoke earlier explicit action
  authorizations. Preserve secure credential handling and existing safeguards;
  do not publish passwords, tokens, 2FA codes or private records in repository
  documentation or logs.

## Consequences

Future work can investigate the Hub without repeated routine permission requests.
Only claim to have checked a record when it was actually retrieved. Recording
this decision does not change the application's roles, OAuth grants, connector
tools or processing behavior. An explicit revocation supersedes this decision
and must be reflected in the project instructions and memory.
