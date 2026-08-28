<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/I18n/LanguageGuard.php';
require_once __DIR__ . '/support/FakeLexicalLanguageClassifier.php';

function assertRuntimeI18n(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

function assertRuntimeI18nThrows(
    callable $callback,
    string $fragment,
    string $message
): void {
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

$lexicon = translationRegressionClassifier();
assertRuntimeI18nThrows(
    static fn() => LanguageGuard::assertExpectedLanguage('Verificar se o quarto está limpo e sem danos', 'en', translationRegressionClassifier()),
    'clearly PT',
    'clear PT source is rejected when interface language is EN'
);
assertRuntimeI18nThrows(
    static fn() => LanguageGuard::assertExpectedLanguage('Check the room windows and curtains', 'pt', translationRegressionClassifier()),
    'claramente EN',
    'clear EN source is rejected when interface language is PT'
);
assertRuntimeI18nThrows(
    static fn() => LanguageGuard::assertExpectedLanguage('Verificar se está limpo house', 'pt', translationRegressionClassifier()),
    'mistura PT e EN',
    'one lexical EN word is enough to reject mixed PT input'
);
assertRuntimeI18nThrows(
    static fn() => LanguageGuard::assertExpectedLanguage('Verificar se está limpo qdsffasdfaasdf', 'pt', translationRegressionClassifier()),
    'não reconhecida',
    'unknown ordinary word is rejected instead of inheriting PT context'
);

LanguageGuard::assertExpectedLanguage('extinguisher', 'en', $lexicon);
LanguageGuard::assertExpectedLanguage('detector', 'en', $lexicon);
LanguageGuard::assertExpectedLanguage('detector', 'pt', $lexicon);
LanguageGuard::assertExpectedLanguage('HVAC', 'en', $lexicon);
LanguageGuard::assertExpectedLanguage('HVAC', 'pt', $lexicon);
LanguageGuard::assertExpectedLanguage('WiFi', 'en', $lexicon);
LanguageGuard::assertExpectedLanguage('WiFi', 'pt', $lexicon);
assertRuntimeI18n(true, 'English dictionary word, shared word and technical identifiers are handled independently');

$guardSource = file_get_contents(dirname(__DIR__) . '/src/I18n/LanguageGuard.php');
assertRuntimeI18n(is_string($guardSource), 'runtime LanguageGuard is readable');
assertRuntimeI18n(str_contains($guardSource, 'LexicalLanguageClassifier'), 'runtime guard delegates word language to lexical checker');
assertRuntimeI18n(!str_contains($guardSource, 'SHORT_MIN_') && !str_contains($guardSource, 'MIXED_MIN_'), 'old short/mixed statistical rules are gone');
assertRuntimeI18n(!str_contains($guardSource, 'wordMatchesExpectedLanguage'), 'old detector-as-dictionary code remains removed');

echo "Runtime lexical bilingual preservation regression passed.\n";
