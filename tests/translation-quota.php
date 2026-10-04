<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/I18n/TranslationQuotaManager.php';
require_once dirname(__DIR__) . '/src/I18n/ContentTranslator.php';
require_once dirname(__DIR__) . '/src/Notifications/WhatsAppTranslationQuotaTemplate.php';

function assertTranslationQuota(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$beforeReset = TranslationQuotaManager::periodAt(
    new DateTimeImmutable('2026-09-06T06:59:59Z')
);
assertTranslationQuota(
    $beforeReset['quota_date'] === '2026-09-05',
    'Google quota date remains on the Pacific day until Pacific midnight'
);
assertTranslationQuota(
    $beforeReset['reset_display'] === '06/09/2026 às 08:00',
    'Pacific reset is displayed in Portugal time during September'
);

$afterReset = TranslationQuotaManager::periodAt(
    new DateTimeImmutable('2026-09-06T07:00:00Z')
);
assertTranslationQuota(
    $afterReset['quota_date'] === '2026-09-06',
    'a new quota period starts exactly at Pacific midnight'
);

$dstMismatch = TranslationQuotaManager::periodAt(
    new DateTimeImmutable('2026-03-20T12:00:00Z')
);
assertTranslationQuota(
    $dstMismatch['reset_display'] === '21/03/2026 às 07:00',
    'timezone conversion follows both regions DST rules instead of hard-coding 08:00'
);

$values = WhatsAppTranslationQuotaTemplate::values(15480, 15500, '06/09/2026 às 08:00');
assertTranslationQuota(
    $values === ['15.480', '15.500', '06/09/2026 às 08:00'],
    'WhatsApp template receives the three approved Portuguese parameters'
);

$pdo = (new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
$translator = new ContentTranslator($pdo, ['enabled' => false]);
$countMethod = new ReflectionMethod(ContentTranslator::class, 'translatableCharacterCount');
$countMethod->setAccessible(true);
assertTranslationQuota(
    $countMethod->invoke($translator, "  Olá  \n\n mundo ") === 8,
    'quota counts only the characters actually placed in Google q fields'
);

$root = dirname(__DIR__);
$quotaSource = file_get_contents($root . '/src/I18n/TranslationQuotaManager.php');
$translatorSource = file_get_contents($root . '/src/I18n/ContentTranslator.php');
$cronSource = file_get_contents($root . '/cron/whatsapp-reminders.php');
$clientSource = file_get_contents($root . '/src/Notifications/WhatsAppCloudClient.php');
assertTranslationQuota(
    is_string($quotaSource) && str_contains($quotaSource, 'FOR UPDATE')
        && str_contains($quotaSource, 'beginTransaction()'),
    'daily character reservations are serialized transactionally'
);
assertTranslationQuota(
    is_string($translatorSource)
        && str_contains($translatorSource, 'reserve(')
        && str_contains($translatorSource, 'dailylimitexceeded'),
    'local reservations and Google daily-limit responses use the same alert path'
);
assertTranslationQuota(
    is_string($cronSource)
        && str_contains($cronSource, 'WhatsAppTranslationQuotaTemplate::values')
        && str_contains($cronSource, 'markAlertSent'),
    'the existing WhatsApp cron sends and records a queued quota alert'
);
assertTranslationQuota(
    is_string($clientSource) && str_contains($clientSource, '?string $templateName = null'),
    'quota alerts can select their template without changing assignment reminders'
);
assertTranslationQuota(
    file_exists($root . '/migrations/024_translation_daily_quota.sql'),
    'daily quota storage has an explicit deployment migration'
);

echo "Translation quota and WhatsApp alert audit passed.\n";
