<?php
declare(strict_types=1);

require_once __DIR__ . '/SiteTranslations.php';

final class TranslationFeedback
{
    public static function messages(): array
    {
        return [
            // Successful translated saves normally receive a precise message
            // from ContentTranslator. This is only the browser fallback.
            'saved' => SiteTranslations::text(
                'Guardado.',
                'Saved.'
            ),
            'timeout' => SiteTranslations::text(
                'Erro: a gravação/tradução excedeu o tempo limite.',
                'Error: save/translation timed out.'
            ),
            'saveError' => SiteTranslations::text(
                'Erro ao guardar.',
                'Error: could not save.'
            ),
        ];
    }
}
