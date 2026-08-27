<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/I18n/LanguageGuard.php';

function assertRuntimeI18n(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

function assertRuntimeI18nThrows(callable $callback, string $fragment, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        assertRuntimeI18n(str_contains($exception->getMessage(), $fragment), $message);
        return;
    }
    throw new RuntimeException('FAIL: ' . $message);
}

$maintenance = file_get_contents(dirname(__DIR__) . '/src/I18n/BilingualContentMaintenance.php');
assertRuntimeI18n(is_string($maintenance), 'runtime bilingual maintenance source is readable');
assertRuntimeI18n(
    str_contains($maintenance, '$sourceText === \'\' || $currentTarget !== \'\''),
    'runtime maintenance never rewrites a non-empty EN value'
);
assertRuntimeI18n(
    str_contains($maintenance, 'Historical repair of')
        && str_contains($maintenance, 'explicit migration'),
    'non-empty legacy repairs are explicitly separated from normal requests'
);

// Complete-text validation in both directions.
assertRuntimeI18nThrows(
    static fn() => LanguageGuard::assertExpectedLanguage(
        'Verificar se o quarto está limpo e sem danos',
        'en'
    ),
    'This text contains errors. Please write it in English only.',
    'PT text is rejected when the selected interface language is EN'
);
assertRuntimeI18nThrows(
    static fn() => LanguageGuard::assertExpectedLanguage(
        'Check that the room is clean and undamaged',
        'pt'
    ),
    'Este texto contém erros. Escreva-o apenas em português.',
    'EN text is rejected when the selected interface language is PT'
);

// Component validation in both directions. These cases remain globally dominated
// by the selected interface language, so only word/short-segment analysis catches
// the foreign insertion.
assertRuntimeI18nThrows(
    static fn() => LanguageGuard::assertExpectedLanguage(
        'Check that it is clean and undamaged. quarto',
        'en'
    ),
    'This text contains errors. Please write it in English only.',
    'EN input containing Portuguese component quarto is rejected'
);
assertRuntimeI18nThrows(
    static fn() => LanguageGuard::assertExpectedLanguage(
        'Check that it is clean and undamaged. escada',
        'en'
    ),
    'This text contains errors. Please write it in English only.',
    'EN input containing Portuguese component escada is rejected'
);
assertRuntimeI18nThrows(
    static fn() => LanguageGuard::assertExpectedLanguage(
        'Verificar se está limpo e sem danos. room',
        'pt'
    ),
    'Este texto contém erros. Escreva-o apenas em português.',
    'PT input containing English component room is rejected'
);
assertRuntimeI18nThrows(
    static fn() => LanguageGuard::assertExpectedLanguage(
        'Verificar se está limpo e sem danos. stairs',
        'pt'
    ),
    'Este texto contém erros. Escreva-o apenas em português.',
    'PT input containing English component stairs is rejected'
);

// Matching language and deliberately neutral/ambiguous values remain accepted.
LanguageGuard::assertExpectedLanguage('Check that it is clean and undamaged in the room', 'en');
LanguageGuard::assertExpectedLanguage('Verificar se o quarto está limpo e sem danos', 'pt');
LanguageGuard::assertExpectedLanguage('WiFi Café', 'en');
LanguageGuard::assertExpectedLanguage('WiFi Café', 'pt');
LanguageGuard::assertExpectedLanguage('Café Central', 'en');
LanguageGuard::assertExpectedLanguage('Café Central', 'pt');
assertRuntimeI18n(true, 'clean PT/EN and neutral text remain accepted');

echo "Runtime bilingual preservation regression passed.\n";
