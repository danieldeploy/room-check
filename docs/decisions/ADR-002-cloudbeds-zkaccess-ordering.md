# ADR-002 — Cloudbeds event and ZKAccess confirmation ordering

Date: 2026-10-28  
Status: **Accepted**

## Context

The planned guest-PIN automation crosses Cloudbeds, Management Hub, a Windows
executor and ZKTeco C3-400 controllers. Webhooks may be retried or arrive after
newer reservation state.

## Decision

Use Cloudbeds webhooks as triggers, but re-read the authoritative reservation
before a physical access change.

After determining the desired state:

1. program ZKAccess;
2. read/confirm the physical controller state;
3. record the confirmed automation state;
4. write the PIN note to Cloudbeds;
5. send the guest access message.

Retries after controller confirmation must resume at the failed later stage
rather than repeating the physical write blindly.

## Consequences

Webhook idempotency and explicit state transitions are required.
A Cloudbeds note or guest message can never be treated as proof that a physical
controller write succeeded.
