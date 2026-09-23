#!/bin/bash
# A single task ensures a failed backup cannot fall through to later copies.
set -euo pipefail
umask 077
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."
: "${HOME:?account home is required}"
backup_root="$HOME/room-check-backups"
test ! -L "$backup_root"
mkdir -p -- "$backup_root"
test ! -L "$backup_root/deploy.lock"
exec 9>>"$backup_root/deploy.lock"
/usr/bin/flock -n 9

/usr/local/bin/php deploy/sync_cron.php --check
/usr/local/bin/php deploy/prepare_release_backup.php
export DEPLOYPATH="$HOME/public_html/check/"
export PRIVATEPATH="$HOME/room-check-private/"
# Restrict private files while keeping newly deployed web assets readable.
(umask 022; /bin/mkdir -p "$DEPLOYPATH")
/bin/mkdir -p "$PRIVATEPATH"
/usr/local/bin/php deploy/project_migrations.php "$DEPLOYPATH"
(
    umask 022
    /bin/cp -R assets "$DEPLOYPATH"
    /bin/cp -R admin src "$DEPLOYPATH"
)
/bin/cp -R cron "$PRIVATEPATH"
/bin/cp -R invoice-runner "$PRIVATEPATH"
(
    umask 022
    /bin/cp invoice-agent.php invoice-auth.php api.php config.php index.php rooms.php item-lists.php verification-categories.php tasks.php lib.php login.php logout.php setup.php database.sql config.local.example.php .htaccess "$DEPLOYPATH"
)
/bin/rm -f "$DEPLOYPATH/translation-validate.php" "$DEPLOYPATH/src/I18n/BilingualContentMaintenance.php" "$DEPLOYPATH/src/I18n/LanguageGuard.php" "$DEPLOYPATH/src/I18n/LexicalLanguageChecker.php"
/bin/rm -rf "$DEPLOYPATH/resources/lexicon/full" "$DEPLOYPATH/src/ThirdParty/efficient-language-detector"
/bin/cp -R migrations "$PRIVATEPATH"
/usr/local/bin/php deploy/sync_cron.php --apply
