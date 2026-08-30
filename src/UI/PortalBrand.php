<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/I18n/Translator.php';

/**
 * Single source of truth for the portal identity.
 */
final class PortalBrand
{
    public const NAME_PT = 'Centro de Gestão';
    public const NAME_EN = 'Management Hub';
    public const LEGAL_COMPANY_NAME = 'Active Lines Unip. Lda.';

    public static function name(?string $locale = null): string
    {
        Translator::registerDynamic(self::NAME_PT, self::NAME_EN);
        $locale = strtolower(trim((string) ($locale ?? Translator::locale())));

        return $locale === 'en' ? self::NAME_EN : self::NAME_PT;
    }

    public static function legalCompanyName(): string
    {
        return self::LEGAL_COMPANY_NAME;
    }
}
