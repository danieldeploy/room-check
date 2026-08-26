# Room Check — Codex instructions

## Project
This repository contains the PHP/MySQL Room Check application for Welcome Hostel. Preserve the existing room-management behavior unless the user explicitly requests a change.

Read these files before substantial work:
- `docs/PROJECT_CONTEXT.md`
- `docs/MY2N_CONTROL_PANEL.md` when working on intercom features
- `docs/DEPLOYMENT.md` before any release or production-related work

## Mandatory workflow
- Work on an `agent/<description>` branch and use a Pull Request.
- Inspect existing behavior before modifying it.
- Keep changes scoped and preserve unrelated functionality.
- Validate PHP and JavaScript syntax and run relevant tests before handoff.
- Describe database migrations and rollback steps explicitly.
- Use timezone `Europe/Lisbon`.
- Never deploy or publish to production without explicit user authorization.

## Security
- Never commit credentials, passwords, tokens, API keys, SIP passwords, private keys, session data, or production configuration.
- Never place secrets in JavaScript, public web assets, `public_html`, documentation examples, commits, or logs.
- Keep secrets in server-side environment/configuration outside the repository or in encrypted GitHub Actions Secrets.
- Do not expose My2N or ZKTeco credentials to the browser.
- Preserve authentication, authorization, CSRF protection, audit logging, and least-privilege access.

## Roles
The application uses these profiles:
- Gerente
- Governanta
- Técnico de Manutenção
- Empregada de Andares

Do not assume permissions. Verify the current permission model and implement only the permissions explicitly approved by the user.

## Production
Production application path: `/home/welcome/public_html/check`.
Production URL: `https://check.welcomehostel.pt`.
The cPanel deployment manifest is `.cpanel.yml`.
Treat deployment as a separate, explicitly authorized step from development or merging.
