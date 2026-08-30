<?php
declare(strict_types=1);

final class WhatsAppReminderTemplate
{
    public const LEGACY_NAME = 'space_management_reminder';
    public const V2_NAME = 'space_management_reminder_v2';

    public static function values(
        string $templateName,
        array $reminder,
        string $preferredLanguage,
        string $portalName,
        string $legacyPortalInstruction,
        string $v2TemplateName = self::V2_NAME
    ): array {
        if ($templateName !== $v2TemplateName) {
            return [
                (string) ($reminder['display_name'] ?? ''),
                self::legacyDate((string) ($reminder['due_date'] ?? '')),
                (string) ($reminder['property_name'] ?? ''),
                (string) ($reminder['assignment_count'] ?? ''),
                $legacyPortalInstruction,
            ];
        }

        return [
            self::requiredValue($reminder, 'display_name', 'destinatário'),
            self::requiredText($portalName, 'portal'),
            self::v2Date((string) ($reminder['due_date'] ?? ''), $preferredLanguage),
            self::requiredValue($reminder, 'property_name', 'estabelecimento'),
            self::requiredValue($reminder, 'creator_display_name', 'remetente'),
            self::requiredText($portalName, 'portal'),
        ];
    }

    private static function legacyDate(string $date): string
    {
        return self::date($date)->format('d/m/Y');
    }

    private static function v2Date(string $date, string $preferredLanguage): string
    {
        $value = self::date($date);
        return strtolower(trim($preferredLanguage)) === 'en'
            ? $value->format('j F Y')
            : $value->format('d/m/Y');
    }

    private static function date(string $date): DateTimeImmutable
    {
        $value = DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));
        if (!$value instanceof DateTimeImmutable || $value->format('Y-m-d') !== trim($date)) {
            throw new InvalidArgumentException('Data do lembrete inválida.');
        }
        return $value;
    }

    private static function requiredValue(array $reminder, string $key, string $label): string
    {
        return self::requiredText((string) ($reminder[$key] ?? ''), $label);
    }

    private static function requiredText(string $text, string $label): string
    {
        $value = trim($text);
        if ($value === '') {
            throw new InvalidArgumentException('O argumento ' . $label . ' do template WhatsApp está vazio.');
        }
        return $value;
    }
}
