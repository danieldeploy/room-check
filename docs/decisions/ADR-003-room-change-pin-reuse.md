# ADR-003 — Room-change PIN reuse and old-room rotation

Date: 2026-10-28  
Status: **Accepted**

## Context

A guest may be moved from one physical room to another after a PIN has already
been issued.

## Decision

On a room change:

1. replace/rotate the PIN on the old room so the previous access stops working;
2. keep the guest's existing PIN;
3. program that same guest PIN on the new room;
4. confirm the new-room access;
5. update Cloudbeds information and guest instructions for the new room.

For this workflow, access revocation is achieved by replacing the old room PIN,
not by relying on a separate revoke operation.

## Consequences

The guest does not need to learn a new PIN solely because of a room move.
The automation must track both old and new room state and must not consider the
move complete until the old-room rotation and new-room programming are reconciled.
