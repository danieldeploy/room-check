# Deployment

## Current target
- Hosting: cPanel
- Repository: `danieldeploy/room-check`
- Production path: `/home/welcome/public_html/check`
- Production URL: `https://check.welcomehostel.pt`
- Manifest: `.cpanel.yml`
- Timezone: `Europe/Lisbon`

The manifest currently deploys public application files to `$HOME/public_html/check/` and private cron content to `$HOME/room-check-private/`. Inspect the live manifest before changing this behavior.

## Safety rules
- Development, merge and deployment are separate actions.
- Never deploy without explicit user authorization naming the intended branch or commit.
- Confirm the exact commit before deployment.
- Never commit or overwrite production secrets or local configuration.
- Do not delete server files unless the deployment plan explicitly identifies and backs them up.
- Prepare rollback before changing code or schema.
- Back up the database before a production migration.

## Existing cPanel flow
1. Open cPanel Git Version Control.
2. Select the repository associated with `/home/welcome/public_html/check`.
3. Confirm the intended branch and commit.
4. Run **Update from Remote**.
5. Confirm HEAD.
6. Run **Deploy HEAD Commit**.
7. Test `https://check.welcomehostel.pt` and relevant authenticated flows.
8. Record the deployed commit and outcome.

## Recommended automation
Prefer a manually triggered GitHub Actions workflow over automatic deployment on every push.

A future workflow should:
- require encrypted GitHub Actions Secrets;
- use a dedicated, least-privilege SSH deployment key;
- accept or pin an intended commit;
- verify the commit before release;
- run syntax/tests before connecting to production;
- invoke an allow-listed deployment script or the established cPanel deployment mechanism;
- retain logs without printing secrets;
- support a documented rollback.

Do not add production credentials to workflow YAML. Initial SSH/cPanel configuration requires a separate, explicitly approved setup.
