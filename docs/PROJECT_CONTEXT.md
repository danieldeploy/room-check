# Project context

## Purpose
Room Check is an existing PHP/MySQL application used by Welcome Hostel for accommodation and room operations. New work must extend the application without rebuilding or disrupting the established room-management workflow.

## Repository and hosting
- Repository: `danieldeploy/room-check`
- Hosting: cPanel
- Production path: `/home/welcome/public_html/check`
- Production URL: `https://check.welcomehostel.pt`
- Timezone: `Europe/Lisbon`

## Current development
The My2N control-panel implementation was developed on branch `agent/my2n-control-panel`.
Known handoff commit: `ec9d4b48ce6f88e7fa594b9d4a05c6cc00b8a612`.

Verify the live branch and commit before continuing; do not assume that production already contains this commit.

## Product rules
- Preserve current room-management features.
- Model bell/intercom, apartment and mobile devices dynamically.
- One apartment may have multiple bells.
- A mobile may answer one bell, several bells, or none.
- Display the bell in relevant assignment/status tables.
- Roles and permissions must remain explicit and auditable.
- Prefer incremental database migrations with rollback instructions.

## Roles
- Gerente
- Governanta
- Técnico de Manutenção
- Empregada de Andares

Exact permissions must be confirmed from the current implementation and the user's instructions rather than inferred from role names.

## Working agreement
- Development through branches and Pull Requests.
- No production release without explicit authorization.
- No secrets in GitHub, client-side code, documentation, or `public_html`.
- Validate syntax and relevant behavior before presenting work as complete.
