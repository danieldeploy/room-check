<?php
declare(strict_types=1);

$appRoot = getenv('ROOM_CHECK_APP_ROOT') ?: rtrim((string) getenv('HOME'), '/') . '/public_html/check';
if (!is_file($appRoot . '/config.php')) { fwrite(STDERR, "Portal config not found.\n"); exit(1); }
require $appRoot . '/lib.php';
require $appRoot . '/src/Notifications/WhatsAppCloudClient.php';
$config = require $appRoot . '/config.php';
$configuredTemplate = (string) ($config['whatsapp']['template_name'] ?? '');
if ($configuredTemplate === '' || $configuredTemplate === 'room_assignment_reminder') {
    $config['whatsapp']['template_name'] = 'space_management_reminder';
}
date_default_timezone_set($config['whatsapp']['timezone'] ?? 'Europe/Lisbon');
$pdo = database();
$lock = (int) $pdo->query("SELECT GET_LOCK('room_check_whatsapp_reminders', 0)")->fetchColumn();
if ($lock !== 1) exit(0);
$client = new WhatsAppCloudClient($config['whatsapp']);
try {
    $query = $pdo->query(
        "SELECT r.id, r.attempt_count, r.due_date, r.property_name, r.list_id, l.name AS list_name, u.display_name, u.mobile,
                u.preferred_language,
                COUNT(a.id) AS assignment_count
         FROM whatsapp_assignment_reminders r
         JOIN users u ON u.id = r.assigned_to_user_id
         JOIN item_lists l ON l.id = r.list_id
         JOIN room_item_assignments a ON a.assigned_to_user_id = r.assigned_to_user_id
              AND a.due_date = r.due_date AND a.property_name = r.property_name AND a.list_id = r.list_id AND a.completed_at IS NULL
         WHERE r.status IN ('pending','failed') AND r.scheduled_at <= NOW()
           AND (r.next_attempt_at IS NULL OR r.next_attempt_at <= NOW())
         GROUP BY r.id, r.attempt_count, r.due_date, r.property_name, r.list_id, l.name, u.display_name, u.mobile,
                  u.preferred_language, r.scheduled_at
         ORDER BY r.scheduled_at LIMIT 50"
    );
    foreach ($query->fetchAll() as $row) {
        $pdo->prepare("UPDATE whatsapp_assignment_reminders SET status = 'processing', attempt_count = attempt_count + 1 WHERE id = :id")
            ->execute(['id' => (int) $row['id']]);
        try {
            $preferredLanguage = (string) ($row['preferred_language'] ?? 'pt');
            $templateLanguages = $config['whatsapp']['template_languages'] ?? [
                'pt' => 'pt_PT',
                'en' => 'en',
            ];
            $languageCode = (string) ($templateLanguages[$preferredLanguage]
                ?? $templateLanguages['pt']
                ?? 'pt_PT');
            $portalInstruction = $preferredLanguage === 'en'
                ? 'List: ' . (string) $row['list_name'] . '. Open the Management Portal to view the assigned items and instructions.'
                : 'Lista: ' . (string) $row['list_name'] . '. Consulte no Portal de Gestão os itens e respetivas instruções.';
            $messageId = $client->sendTemplate((string) $row['mobile'], [
                (string) $row['display_name'],
                (new DateTimeImmutable((string) $row['due_date']))->format('d/m/Y'),
                (string) $row['property_name'],
                (string) $row['assignment_count'],
                $portalInstruction,
            ], $languageCode);
            $pdo->prepare("UPDATE whatsapp_assignment_reminders SET status = 'sent', meta_message_id = :message_id, sent_at = NOW(), last_error = NULL WHERE id = :id")
                ->execute(['message_id' => $messageId, 'id' => (int) $row['id']]);
        } catch (Throwable $error) {
            $minutes = min(60, 5 * (2 ** min(4, (int) $row['attempt_count'])));
            $pdo->prepare("UPDATE whatsapp_assignment_reminders SET status = 'failed', last_error = :error, next_attempt_at = DATE_ADD(NOW(), INTERVAL {$minutes} MINUTE) WHERE id = :id")
                ->execute(['error' => mb_substr($error->getMessage(), 0, 1000), 'id' => (int) $row['id']]);
            fwrite(STDERR, "WhatsApp reminder {$row['id']}: {$error->getMessage()}\n");
        }
    }
} finally {
    $pdo->query("SELECT RELEASE_LOCK('room_check_whatsapp_reminders')");
}
