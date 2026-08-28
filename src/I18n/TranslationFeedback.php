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
                'Erro: o processo de validação/tradução excedeu o tempo limite.',
                'Error: validation/translation timed out.'
            ),
            'saveError' => SiteTranslations::text(
                'Erro ao guardar.',
                'Error: could not save.'
            ),
            'validationError' => SiteTranslations::text(
                'Não guardado: não foi possível verificar o texto.',
                'Not saved: could not verify the text.'
            ),
        ];
    }
}
