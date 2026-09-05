<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/I18n/TranslationQuotaManager.php';
require_once dirname(__DIR__) . '/src/I18n/PendingTranslationQueue.php';

function assertPendingTranslation(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$root = dirname(__DIR__);
$queue = file_get_contents($root . '/src/I18n/PendingTranslationQueue.php');
$processor = file_get_contents($root . '/src/I18n/PendingTranslationProcessor.php');
$api = file_get_contents($root . '/api.php');
$itemLists = file_get_contents($root . '/item-lists.php');
$categories = file_get_contents($root . '/verification-categories.php');
$categoryRepository = file_get_contents($root . '/src/Checklists/VerificationCategoryRepository.php');
$client = file_get_contents($root . '/assets/app.js');
$cron = file_get_contents($root . '/cron/translation-pending.php');
$migration = file_get_contents($root . '/migrations/025_translation_pending_jobs.sql');

assertPendingTranslation(
    is_string($queue) && str_contains($queue, 'ON DUPLICATE KEY UPDATE')
        && str_contains($queue, 'generation = generation + 1')
        && str_contains($queue, 'supersedeForAcceptedSave'),
    'a newer queued or immediately accepted edit supersedes an older pending generation'
);
assertPendingTranslation(
    is_string($queue) && str_contains($queue, "'_expected'")
        && str_contains($queue, 'FOR UPDATE'),
    'queue creation records a locked authoritative-state fingerprint'
);
assertPendingTranslation(
    is_string($processor) && str_contains($processor, 'assertExpectedState')
        && str_contains($processor, 'PendingTranslationConflictException')
        && str_contains($processor, 'assertActiveClaim')
        && str_contains($processor, 'requireActorPermission'),
    'worker refuses stale state, superseded generations, and revoked actor permissions'
);
assertPendingTranslation(
    is_string($processor) && str_contains($processor, 'completeActiveClaim')
        && str_contains($processor, 'TranslationQuotaExceededException')
        && str_contains($processor, 'resetUtc()')
        && str_contains($processor, 'TranslationServiceException')
        && str_contains($processor, 'retryable()'),
    'data changes and successful job completion commit atomically, while quota deferrals are rescheduled'
);
assertPendingTranslation(
    is_string($api) && str_contains($api, "'pending' => true")
        && str_contains($api, "'resetAt'")
        && str_contains($api, 'PendingTranslationQueue'),
    'API returns an explicit accepted-pending response instead of a false completed save'
);
assertPendingTranslation(
    is_string($api) && substr_count($api, 'supersedeForAcceptedSave(') === 6
        && is_string($itemLists) && substr_count($itemLists, "supersedeForAcceptedSave('item_lists'") === 4
        && is_string($categories) && substr_count($categories, 'supersedeForAcceptedSave(') === 2
        && is_string($categoryRepository) && substr_count($categoryRepository, '$beforeWrite();') === 2,
    'every translation-backed live save invalidates an older pending edit in the same transaction'
);
assertPendingTranslation(
    is_string($client) && substr_count($client, 'result.pending') >= 4,
    'browser handles pending assignments, checklists, and interval changes without reading missing result fields'
);
assertPendingTranslation(
    is_string($api) && str_contains($api, '$currentUser = Auth::requirePermission($pdo, $config, Auth::PERMISSION_ROOM_CHECK_EDIT)')
        && str_contains($client, 'csrfToken: config.csrfToken, items: snapshot.items'),
    'queued checklist writes retain their authenticated actor and require CSRF protection'
);
assertPendingTranslation(
    is_string($cron) && str_contains($cron, "GET_LOCK('room_check_pending_translations'")
        && str_contains($cron, 'PendingTranslationProcessor'),
    'pending translations run under a dedicated single-worker database lock'
);
assertPendingTranslation(
    is_string($migration) && str_contains($migration, 'translation_pending_jobs')
        && str_contains($migration, 'payload_json JSON')
        && str_contains($migration, 'generation INT UNSIGNED'),
    'pending source text and concurrency generation have an explicit migration'
);

$period = TranslationQuotaManager::periodAt(new DateTimeImmutable('2026-09-06T06:59:59Z'));
$exception = new TranslationQuotaExceededException(
    'quota',
    $period['quota_date'],
    $period['reset_display'],
    $period['reset_utc'],
    'pt'
);
assertPendingTranslation(
    $exception->resetUtc() === '2026-09-06 07:00:00'
        && str_contains(PendingTranslationQueue::pendingMessage('pt', $exception->resetDisplay()), 'hora de Portugal'),
    'queued work uses the exact Google reset instant and displays it in Portugal time'
);

echo "Pending translation queue audit passed.\n";
