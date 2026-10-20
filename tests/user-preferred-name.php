<?php
declare(strict_types=1);

function assertUserPreferredName(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) {
        throw new RuntimeException('Unable to read ' . $path);
    }
    return $content;
};

$database = $read('database.sql');
$migration = $read('migrations/021_user_preferred_name.sql');
$admin = $read('admin/users.php');
$reminders = $read('cron/whatsapp-reminders.php');

assertUserPreferredName(
    str_contains($database, 'preferred_name VARCHAR(120) NULL'),
    'fresh installations include the optional preferred name'
);
assertUserPreferredName(
    str_contains($migration, 'ADD COLUMN preferred_name VARCHAR(120) NULL'),
    'existing installations receive the preferred name migration'
);
assertUserPreferredName(
    substr_count($admin, 'name="preferred_name"') === 2,
    'administrators can set the preferred name when creating or editing a user'
);
assertUserPreferredName(
    str_contains($reminders, "COALESCE(NULLIF(TRIM(u.preferred_name), ''), u.display_name) AS display_name"),
    'WhatsApp greetings prefer the recipient preferred name and fall back to the registered name'
);
assertUserPreferredName(
    str_contains($reminders, "COALESCE(NULLIF(TRIM(creator.preferred_name), ''), creator.display_name) AS creator_display_name"),
    'WhatsApp signatures prefer the scheduler preferred name and fall back to the registered name'
);

echo "User preferred-name audit passed.\n";
