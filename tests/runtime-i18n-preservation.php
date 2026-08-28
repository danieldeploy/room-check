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
assertRuntimeI18n(str_contains($maintenance, '$sourceText === \'\' || $currentTarget !== \'\''), 'runtime maintenance never rewrites a non-empty target value');
assertRuntimeI18n(str_contains($maintenance, 'Historical repair of') && str_contains($maintenance, 'explicit migration'), 'legacy repairs remain explicitly separated from normal requests');

assertRuntimeI18nThrows(
    static fn() => LanguageGuard::assertExpectedLanguage('Verificar se o quarto está limpo e sem danos', 'en'),
    'clearly PT',
    'clear PT sentence is rejected when interface language is EN'
);
assertRuntimeI18nThrows(
    static fn() => LanguageGuard::assertExpectedLanguage('Check that the room is clean and undamaged', 'pt'),
    'claramente EN',
    'clear EN sentence is rejected when interface language is PT'
);

// Technical and short ambiguous values must not be blocked before translation.
LanguageGuard::assertExpectedLanguage('Check the fire extinguisher detector and HVAC thermostat', 'en');
LanguageGuard::assertExpectedLanguage('fire extinguisher', 'en');
LanguageGuard::assertExpectedLanguage('WiFi Café', 'en');
LanguageGuard::assertExpectedLanguage('WiFi Café', 'pt');
assertRuntimeI18n(true, 'technical and ambiguous text remains accepted for contextual translation');

assertRuntimeI18n(LanguageGuard::confidentSentenceLanguage('fire extinguisher') === null, 'short technical phrase stays ambiguous');
assertRuntimeI18n(!str_contains(file_get_contents(dirname(__DIR__) . '/src/I18n/LanguageGuard.php') ?: '', 'wordMatchesExpectedLanguage'), 'runtime guard no longer performs component-by-component rejection');

echo "Runtime contextual bilingual preservation regression passed.\n";
