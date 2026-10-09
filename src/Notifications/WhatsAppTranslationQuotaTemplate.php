<?php
declare(strict_types=1);

final class WhatsAppTranslationQuotaTemplate
{
    public const NAME = 'translation_quota_alert_v1';
    public const LANGUAGE = 'pt_PT';

    /** @return string[] */
    public static function values(int $used, int $limit, string $resetDisplay): array
    {
        $resetDisplay = trim($resetDisplay);
        if ($used < 0 || $limit < 1 || $resetDisplay === '') {
            throw new InvalidArgumentException('Invalid WhatsApp translation quota template values.');
        }

        return [
            number_format($used, 0, ',', '.'),
            number_format($limit, 0, ',', '.'),
            $resetDisplay,
        ];
    }
}
