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
    str_contains($maintenance, "$sourceText === '' || $currentTarget !== ''"),
    'runtime maintenance never rewrites a non-empty EN value'
);
assertRuntimeI18n(
    str_contains($maintenance, 'Historical repair of')
        && str_contains($maintenance, 'explicit migration'),
    'non-empty legacy repairs are explicitly separated from normal requests'
);

// This exact mixed phrase was captured in the production HAR. It previously
// returned HTTP 200 and was then rewritten by runtime maintenance. New/edited
// mixed text must now be rejected before translation or persistence.
assertRuntimeI18nThrows(
    static fn() => LanguageGuard::assertExpectedLanguage(
        'Check that it is clean and undamaged quarto',
        'en'
    ),
    'mixes Portuguese and English',
    'EN input containing a strong Portuguese word is rejected'
);
assertRuntimeI18nThrows(
    static fn() => LanguageGuard::assertExpectedLanguage(
        'Verificar o quarto room',
        'pt'
    ),
    'mistura português e inglês',
    'PT input containing a strong English word is rejected'
);

LanguageGuard::assertExpectedLanguage('Check that it is clean and undamaged in the room', 'en');
LanguageGuard::assertExpectedLanguage('Verificar se o quarto está limpo e sem danos', 'pt');
assertRuntimeI18n(true, 'clean PT and EN input remains accepted');

echo "Runtime bilingual preservation regression passed.\n";
