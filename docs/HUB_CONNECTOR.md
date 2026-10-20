# Direct Management Hub connection

## Specification

- Behavior: connect ChatGPT directly to the hosted PHP application through
  authenticated, stateless MCP HTTP. No ChatGPT installation, persistent Chrome,
  Windows service, new monthly subscription, or manager password copy is needed.
- Users/permissions: an active `gerente` explicitly consents in the existing Hub
  login. Each connection has its own revocable grant and scopes: `hub:read`,
  `invoices:read`, `cloudbeds:import`. No other role, browser session, user creation,
  arbitrary SQL, shell command, credential export or impersonation is exposed.
- Inputs/data: exact ChatGPT callback, S256 PKCE and exact resource binding;
  optional invoice account/month pagination; original owner-supplied Cloudbeds PDF
  up to 2 MiB. PDF import reuses company/property/issue-date validation, encrypted
  inbox, worker lock, deduplication and existing Drive/TOConline queue.
- Failures: fail closed on bad redirect, resource, verifier, scope, inactive user,
  changed password, expiry or revocation. Single-use codes and refresh tokens are
  exchanged transactionally. Reuse revokes the entire connection. Responses and
  audit logs never contain passwords, tokens, raw errors or document bytes.
- Acceptance: synthetic authorization, token rotation/replay, MySQL concurrency,
  permissions, expiry, revocation, protocol, redaction and invoice import tests;
  required `validate` and `windows-agent`; exact deployment; actual ChatGPT OAuth
  activation followed by `hub_get_profile` and a real token renewal. Deployment
  alone is not authenticated acceptance, nor does queued mean delivered.

## Connect once

On ChatGPT web, open Plugins, select **+ → Add custom MCP server** and use:

- Name: **Management Hub**
- Server URL: `https://check.welcomehostel.pt/mcp.php`
- Authentication: **OAuth**, dynamic client registration (DCR).
- If static client details are requested: client ID `management-hub-chatgpt`,
  no client secret. This public client uses the stable ChatGPT callback and PKCE.

Review the platform connection prompt. Follow the Hub link, sign in as the
existing manager through the private sign-in flow, choose scopes and authorize.
Add/install the resulting plugin to the conversation and call `hub_get_profile`.
Never paste a password, authorization code, access token or refresh token into
chat. ChatGPT's connection manager keeps the OAuth credentials; the Hub stores
only SHA-256 token hashes. No manager password is added to the invoice vault.

Availability of custom MCP plugins depends on the workspace's settings. Creating
the Hub endpoint cannot register or authorize a plugin in the owner's ChatGPT
account automatically. That final connection is an owner-controlled step.

The connector is an API integration, not an administrative browser session. UI
acceptance still needs a supported signed-in browser. The initial tools are:

| Tool | Scope | Effect |
| --- | --- | --- |
| `hub_get_profile` | `hub:read` | Verify account, connection and granted scopes |
| `hub_list_invoice_accounts` | `invoices:read` | Read bounded account/property metadata |
| `hub_list_invoice_documents` | `invoices:read` | Read issue-month documents and real delivery states, 100 per page |
| `hub_import_cloudbeds_pdf` | `cloudbeds:import` | Validate supplied PDF and queue existing collection/delivery |

Only use import when the owner has authorized that document's submission. Pass
original bytes as base64 without printing them. Follow with document queries;
`toc_submitted`, `toc_uncertain`, `toc_duplicate`, missing states or a queued batch
are not proof of TOConline acceptance. Airbnb is still excluded and the TOConline
test button remains. No business documents are included in source or CI.

## Lifetime and recovery

Access tokens last 15 minutes. Each successful renewal rotates the refresh token;
the new token allows another 90 days without renewal, bounded by a one-year
authorization lifetime. Expiry, password changes or revocation may require a new
authorization. Signing out of the browser does not revoke the separate grant.
Use **Ligações autorizadas / Authorized connections** in the Hub to revoke it.
Revocation takes effect on subsequent requests; already-running work may finish.
Token retry after an uncertain exchange can trigger reuse detection and require
reauthorization. This intentionally favors revocation over replay acceptance.

OAuth metadata is available at `/.well-known/oauth-authorization-server` and
`/.well-known/oauth-protected-resource/mcp.php`. Only HTTPS is accepted. The
resource and issuer are canonical constants, never derived from a Host header.
The allowlist accepts only the stable ChatGPT callback or an exact callback-ID
path on `https://chatgpt.com`. Public registration never grants access or stores
unbounded client records. Authorization uses the existing rate-limited login,
CSRF-protected explicit consent and no automatic consent from prior grants.
The same-origin landing step preserves the existing SameSite=Strict session.

The additive migration creates empty delegation tables after the release backup
and before exposing endpoints. It never enrolls an identity or grants access.
Production deployment and Windows agent scopes remain unchanged.

References:
- https://developers.openai.com/plugins/build/auth
- https://developers.openai.com/api/docs/guides/custom-mcp-server
- https://modelcontextprotocol.io/specification/2025-06-18/basic/transports
