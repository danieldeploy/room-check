<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Notifications/WhatsAppReminderTemplate.php';

function assertReminderTemplate(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$reminder = [
    'display_name' => 'Ranjana Kumari',
    'due_date' => '2026-08-30',
    'property_name' => 'City Center Guest House',
    'assignment_count' => 3,
    'creator_display_name' => 'Kasia',
];

$v2Values = WhatsAppReminderTemplate::values(
    WhatsAppReminderTemplate::V2_NAME,
    $reminder,
    'en',
    'Management Hub',
    'unused legacy instruction'
);
assertReminderTemplate(
    $v2Values === ['Ranjana Kumari', 'Management Hub', '30 August 2026', 'City Center Guest House', 'Kasia', 'Management Hub'],
    'V2 sends recipient, centralized portal name, English date, property, scheduling user and repeated portal signature in Meta placeholder order'
);

$legacyValues = WhatsAppReminderTemplate::values(
    WhatsAppReminderTemplate::LEGACY_NAME,
    $reminder,
    'en',
    'Management Hub',
    'List: General Room Check.'
);
assertReminderTemplate(
    $legacyValues === ['Ranjana Kumari', '30/08/2026', 'City Center Guest House', '3', 'List: General Room Check.'],
    'the approved V1 parameter contract remains unchanged until V2 is activated'
);

$missingSenderRejected = false;
try {
    WhatsAppReminderTemplate::values(
        WhatsAppReminderTemplate::V2_NAME,
        array_merge($reminder, ['creator_display_name' => '']),
        'en',
        'Management Hub',
        ''
    );
} catch (InvalidArgumentException) {
    $missingSenderRejected = true;
}
assertReminderTemplate($missingSenderRejected, 'V2 refuses to send an empty sender argument');

echo "WhatsApp reminder template audit passed.\n";
