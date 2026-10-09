<?php
declare(strict_types=1);
require dirname(__DIR__) . '/deploy/prepare_release_backup.php';
umask(0077);
$fixture = sys_get_temp_dir() . '/hub-backup-test-' . bin2hex(random_bytes(6));
mkdir($fixture, 0700);
function backupAssert(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function backupRemoveFixture(string $path): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path) as $entry) { backupRemoveFixture($entry->getPathname()); }
        rmdir($path);
    } else { unlink($path); }
}
try {
    mkdir($fixture . '/app');
    mkdir($fixture . '/private');
    mkdir($fixture . '/private/cron');
    file_put_contents($fixture . '/app/config.local.php', 'private test fixture');
    file_put_contents($fixture . '/private/cron/worker.php', '<?php // previous worker');
    $db = ['host' => 'localhost', 'port' => 3306, 'name' => 'fixture_db',
        'user' => 'fixture_user', 'pass' => 'test-password-never-logged'];
    $defaultsObserved = [];
    $run = static function(array $args) use (&$defaultsObserved, $db): string {
        if ($args[0] !== 'mysqldump') { return hubBackupRun($args); }
        backupAssert(!str_contains(implode(' ', $args), $db['pass']), 'password in arguments');
        $defaults = substr($args[1], strlen('--defaults-extra-file='));
        $defaultsObserved[] = $defaults;
        backupAssert((fileperms($defaults) & 0777) === 0600, 'defaults permissions');
        $output = array_values(array_filter($args, static fn($arg) => str_starts_with($arg, '--result-file=')))[0];
        file_put_contents(substr($output, strlen('--result-file=')), str_repeat('-- fixture SQL dump only\n', 20));
        return '';
    };
    $arguments = [$fixture . '/app', $fixture . '/private', $fixture . '/backups',
        str_repeat('a', 40), $db, 0];
    $first = hubBackupSnapshot(...[...$arguments, $run]);
    $second = hubBackupSnapshot(...[...$arguments, $run]);
    backupAssert($first !== $second, 'must never reuse a previous backup');
    $manifest = json_decode(file_get_contents($first . '/complete.json'), true, 512, JSON_THROW_ON_ERROR);
    backupAssert(isset($manifest['sha256']['application.tar.gz'],
        $manifest['sha256']['private-cron.tar.gz'], $manifest['sha256']['database.sql']), 'missing artifact');
    foreach ($manifest['sha256'] as $file => $hash) {
        backupAssert(hash_file('sha256', $first . '/' . $file) === $hash, 'checksum mismatch');
        backupAssert((fileperms($first . '/' . $file) & 0777) === 0600, 'artifact permissions');
    }
    backupAssert((fileperms($first) & 0777) === 0700, 'directory permissions');
    foreach ($defaultsObserved as $defaults) { backupAssert(!file_exists($defaults), 'defaults not removed'); }
    $fail = static function(array $args) use ($run): string {
        if ($args[0] === 'mysqldump') { throw new RuntimeException('fixture command failed'); }
        return $run($args);
    };
    try { hubBackupSnapshot(...[...$arguments, $fail]); throw new LogicException('failure was ignored'); }
    catch (RuntimeException $expected) { backupAssert($expected->getMessage() === 'fixture command failed', 'wrong failure'); }
    foreach (glob($fixture . '/backups/release-*') as $directory) {
        backupAssert(!file_exists($directory . '/mysql-client.cnf'), 'failed defaults not removed');
    }
    backupAssert(count(glob($fixture . '/backups/release-*/complete.json')) === 2, 'failed backup marked complete');
    backupAssert(file_get_contents($fixture . '/app/config.local.php') === 'private test fixture', 'application modified');
    symlink($fixture . '/app', $fixture . '/symlink-root');
    $arguments[2] = $fixture . '/symlink-root';
    try { hubBackupSnapshot(...[...$arguments, $run]); throw new LogicException('symlink accepted'); }
    catch (RuntimeException $expected) { backupAssert($expected->getMessage() === 'backup_symlink', 'wrong symlink failure'); }
    echo "Release backup tests passed (private files, checksums, uniqueness, failure cleanup, no app writes).\n";
} finally { backupRemoveFixture($fixture); }
