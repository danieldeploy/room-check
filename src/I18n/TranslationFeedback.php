<?php
declare(strict_types=1);

require_once __DIR__ . '/SiteTranslations.php';

final class TranslationFeedback
{
    public static function messages(): array
    {
        return [
            'saved' => SiteTranslations::text(
                'Guardado: tradução correta ou ambígua',
                'Saved: translation correct or ambiguous'
            ),
            'timeout' => SiteTranslations::text(
                'Erro: a validação da tradução excedeu o tempo limite.',
                'Error: translation validation timed out.'
            ),
            'saveError' => SiteTranslations::text(
                'Erro ao guardar.',
                'Error: could not save.'
            ),
            'validationError' => SiteTranslations::text(
                'Erro: não foi possível validar a tradução.',
                'Error: could not validate the translation.'
            ),
        ];
    }
}
