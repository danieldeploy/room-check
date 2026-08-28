<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/I18n/LanguageGuard.php';

function assertHardening(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

function assertHardeningRejects(string $text, string $expectedLanguage, string $fragment, string $message): void
{
    try {
        LanguageGuard::assertExpectedLanguage($text, $expectedLanguage);
    } catch (LanguageValidationException $exception) {
        assertHardening(str_contains($exception->getMessage(), $fragment), $message);
        return;
    }
    throw new RuntimeException('FAIL: ' . $message . ' — text was accepted');
}

LanguageGuard::assertExpectedLanguage('Check that it is clean and undamaged in the room.', 'en');
LanguageGuard::assertExpectedLanguage('Verificar se está limpo e sem danos no quarto.', 'pt');
assertHardening(true, 'clear EN and PT sentences remain accepted');

// Technical vocabulary is no longer validated word by word.
foreach ([
    'Check the fire extinguisher detector and HVAC thermostat.',
    'Inspect the smoke detector and extinguisher pressure gauge.',
    'Check the WiFi detector status and My2N intercom.',
] as $technicalEnglish) {
    LanguageGuard::assertExpectedLanguage($technicalEnglish, 'en');
}
assertHardening(true, 'technical English vocabulary is accepted from sentence context');

// Short/technical text is intentionally ambiguous instead of being rejected.
assertHardening(LanguageGuard::confidentSentenceLanguage('fire extinguisher') === null, 'two-token technical text is ambiguous');
assertHardening(LanguageGuard::confidentSentenceLanguage('HVAC') === null, 'single technical token is ambiguous');
LanguageGuard::assertExpectedLanguage('fire extinguisher', 'en');
LanguageGuard::assertExpectedLanguage('fire extinguisher', 'pt');
assertHardening(true, 'ambiguous technical text does not cause a false language error');

assertHardeningRejects(
    'Verificar a limpeza da cozinha e das janelas.',
    'en',
    'clearly PT',
    'whole Portuguese sentence is rejected in EN mode'
);
assertHardeningRejects(
    'Check the kitchen, windows and curtains.',
    'pt',
    'claramente EN',
    'whole English sentence is rejected in PT mode'
);

$guardSource = file_get_contents(dirname(__DIR__) . '/src/I18n/LanguageGuard.php');
assertHardening(is_string($guardSource), 'LanguageGuard source is readable');
assertHardening(str_contains($guardSource, "private const MODEL = 'large_2_1niz1ni';"), 'large EN/PT model remains the production detector');
assertHardening(str_contains($guardSource, 'confidentSentenceLanguage'), 'phrase-level confidence helper is present');
assertHardening(!str_contains($guardSource, 'wordMatchesExpectedLanguage'), 'word-by-word language gate is removed');
assertHardening(!str_contains($guardSource, 'private const NEUTRAL'), 'manual technical vocabulary whitelist is removed');
assertHardening(file_exists(dirname(__DIR__) . '/src/ThirdParty/efficient-language-detector/resources/ngrams/subset/large_2_1niz1ni.php'), 'large EN/PT detector data is vendored');

echo "Phrase-level language guard regression passed.\n";
