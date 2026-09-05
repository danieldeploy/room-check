<?php
declare(strict_types=1);

$appRoot = getenv('ROOM_CHECK_APP_ROOT') ?: rtrim((string) getenv('HOME'), '/') . '/public_html/check';
if (!is_file($appRoot . '/config.php')) { fwrite(STDERR, "Portal config not found.\n"); exit(1); }
require $appRoot . '/lib.php';
require $appRoot . '/src/Notifications/WhatsAppCloudClient.php';
require $appRoot . '/src/Notifications/WhatsAppReminderTemplate.php';
require $appRoot . '/src/Notifications/WhatsAppTranslationQuotaTemplate.php';
require $appRoot . '/src/I18n/TranslationQuotaManager.php';
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
    $translationConfig = $config['translation'] ?? [];
    $quotaAlertConfig = is_array($translationConfig['quota_alert'] ?? null)
        ? $translationConfig['quota_alert']
        : [];
    if ((bool) ($quotaAlertConfig['enabled'] ?? false)) {
        try {
            $quotaManager = new TranslationQuotaManager($pdo, $translationConfig);
            $quotaAlert = $quotaManager->pendingAlert();
            $quotaAlertMobile = trim((string) ($quotaAlertConfig['recipient_mobile'] ?? ''));
            if (is_array($quotaAlert) && $quotaAlertMobile === '') {
                throw new RuntimeException('Translation quota alert recipient is not configured.');
            }
            if (is_array($quotaAlert)) {
                $quotaTemplateName = trim((string) ($quotaAlertConfig['template_name']
                    ?? WhatsAppTranslationQuotaTemplate::NAME));
                $quotaLanguage = trim((string) ($quotaAlertConfig['language']
                    ?? WhatsAppTranslationQuotaTemplate::LANGUAGE));
                $messageId = $client->sendTemplate(
                    $quotaAlertMobile,
                    WhatsAppTranslationQuotaTemplate::values(
                        (int) $quotaAlert['characters_used'],
                        (int) $quotaAlert['character_limit'],
                        (string) $quotaAlert['reset_display']
                    ),
                    $quotaLanguage !== '' ? $quotaLanguage : WhatsAppTranslationQuotaTemplate::LANGUAGE,
                    $quotaTemplateName !== '' ? $quotaTemplateName : WhatsAppTranslationQuotaTemplate::NAME
                );
                $quotaManager->markAlertSent((string) $quotaAlert['quota_date'], $messageId);
            }
        } catch (Throwable $quotaAlertError) {
            if (isset($quotaManager, $quotaAlert) && is_array($quotaAlert)) {
                try {
                    $quotaManager->markAlertFailed(
                        (string) $quotaAlert['quota_date'],
                        $quotaAlertError->getMessage()
                    );
                } catch (Throwable) {
                    // Existing assignment reminders must still run if alert state cannot be updated.
                }
            }
            fwrite(STDERR, "Translation quota alert: {$quotaAlertError->getMessage()}\n");
        }
    }

    $query = $pdo->query(
        "SELECT r.id, r.attempt_count, r.due_date, r.property_name, r.list_id,
                l.name AS list_name, l.name_en AS list_name_en,
                COALESCE(NULLIF(TRIM(u.preferred_name), ''), u.display_name) AS display_name,
                u.mobile, u.preferred_language,
                COALESCE(NULLIF(TRIM(creator.preferred_name), ''), creator.display_name) AS creator_display_name,
                COUNT(a.id) AS assignment_count
         FROM whatsapp_assignment_reminders r
         JOIN users u ON u.id = r.assigned_to_user_id
         JOIN users creator ON creator.id = r.created_by_user_id
         JOIN item_lists l ON l.id = r.list_id
         JOIN room_item_assignments a ON a.assigned_to_user_id = r.assigned_to_user_id
              AND a.due_date = r.due_date AND a.property_name = r.property_name AND a.list_id = r.list_id AND a.completed_at IS NULL
         WHERE r.status IN ('pending','failed') AND r.scheduled_at <= NOW()
           AND (r.next_attempt_at IS NULL OR r.next_attempt_at <= NOW())
         GROUP BY r.id, r.attempt_count, r.due_date, r.property_name, r.list_id, l.name, l.name_en,
                  u.display_name, u.preferred_name, u.mobile, u.preferred_language,
                  creator.display_name, creator.preferred_name, r.scheduled_at
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
            $listNamePt = trim((string) ($row['list_name'] ?? ''));
            $listNameEn = trim((string) ($row['list_name_en'] ?? ''));
            $listName = $preferredLanguage === 'en'
                ? ($listNameEn !== '' ? $listNameEn : $listNamePt)
                : ($listNamePt !== '' ? $listNamePt : $listNameEn);
            $portalName = PortalBrand::name($preferredLanguage);
            $portalInstruction = $preferredLanguage === 'en'
                ? 'List: ' . $listName . '. Open ' . $portalName . ' to view the assigned items and instructions.'
                : 'Lista: ' . $listName . '. Consulte no ' . $portalName . ' os itens e respetivas instruções.';
            $templateName = (string) $config['whatsapp']['template_name'];
            $templateValues = WhatsAppReminderTemplate::values(
                $templateName,
                $row,
                $languageCode,
                $portalName,
                $portalInstruction,
                (string) ($config['whatsapp']['template_v2_name'] ?? WhatsAppReminderTemplate::V2_NAME)
            );
            $messageId = $client->sendTemplate((string) $row['mobile'], $templateValues, $languageCode);
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
