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

// HAR regression: clear two-word phrases must no longer be forced ambiguous.
assertHardening(LanguageGuard::sourceConclusion('casa grande', 'pt') === 'correct', 'short Portuguese phrase is recognised in PT mode');
assertHardening(LanguageGuard::sourceConclusion('new house', 'pt') === 'wrong', 'short English phrase is rejected in PT mode');
assertHardening(LanguageGuard::sourceConclusion('new house', 'en') === 'correct', 'short English phrase is recognised in EN mode');
assertHardeningRejects('new house', 'pt', 'claramente EN', 'new house cannot be saved in PT mode');

// A single token is deliberately ambiguous so technical vocabulary cannot be
// rejected merely because the statistical model dislikes that isolated word.
assertHardening(LanguageGuard::sourceConclusion('casa', 'pt') === 'ambiguous', 'single ordinary token remains conservative/ambiguous');
assertHardening(LanguageGuard::sourceConclusion('detector', 'en') === 'ambiguous', 'single technical detector token remains ambiguous');
assertHardening(LanguageGuard::sourceConclusion('extinguisher', 'en') === 'ambiguous', 'single technical extinguisher token remains ambiguous');
LanguageGuard::assertExpectedLanguage('detector', 'en');
LanguageGuard::assertExpectedLanguage('extinguisher', 'en');
assertHardening(true, 'isolated technical English cannot create a false error');

// Technical vocabulary is judged from context, not word by word.
foreach ([
    'Check the fire extinguisher detector and HVAC thermostat.',
    'Inspect the smoke detector and extinguisher pressure gauge.',
    'Check the WiFi detector status and My2N intercom.',
] as $technicalEnglish) {
    LanguageGuard::assertExpectedLanguage($technicalEnglish, 'en');
}
assertHardening(true, 'technical English vocabulary is accepted from sentence context');

// HAR regression: a provider may normalize mixed input, so mixed source text
// must be rejected before translation quality is considered.
assertHardening(LanguageGuard::sourceConclusion('new house na nossa rua', 'pt') === 'mixed', 'separate EN/PT source segments are detected as mixed');
assertHardeningRejects('new house na nossa rua', 'pt', 'mistura PT e EN', 'mixed PT/EN source cannot be normalized into a valid save');

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
assertHardening(str_contains($guardSource, 'sourceConclusion'), 'source validation exposes an explicit conclusion');
assertHardening(str_contains($guardSource, 'hasMixedLanguageEvidence'), 'multi-word mixed-language detector is present');
assertHardening(!str_contains($guardSource, 'wordMatchesExpectedLanguage'), 'word-by-word language gate remains removed');
assertHardening(!str_contains($guardSource, 'private const NEUTRAL'), 'manual technical vocabulary whitelist remains removed');
assertHardening(file_exists(dirname(__DIR__) . '/src/ThirdParty/efficient-language-detector/resources/ngrams/subset/large_2_1niz1ni.php'), 'large EN/PT detector data is vendored');

echo "Short/mixed phrase language guard regression passed.\n";
