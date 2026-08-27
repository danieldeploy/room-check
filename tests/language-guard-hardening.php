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

function assertHardeningRejects(string $text, string $expectedLanguage, string $message): void
{
    try {
        LanguageGuard::assertExpectedLanguage($text, $expectedLanguage);
    } catch (InvalidArgumentException $exception) {
        $expectedFragment = $expectedLanguage === 'en'
            ? 'Please write it in English only.'
            : 'Escreva-o apenas em português.';
        assertHardening(str_contains($exception->getMessage(), $expectedFragment), $message);
        return;
    }

    throw new RuntimeException('FAIL: ' . $message . ' — text was accepted');
}

function assertHardeningInvalidWord(
    string $text,
    string $expectedLanguage,
    string $invalidWord,
    string $message
): void {
    try {
        LanguageGuard::assertExpectedLanguage($text, $expectedLanguage);
    } catch (LanguageValidationException $exception) {
        assertHardening(in_array($invalidWord, $exception->invalidWords, true), $message);
        return;
    }

    throw new RuntimeException('FAIL: ' . $message . ' — text was accepted');
}

// Clean matching-language values remain valid in both directions.
LanguageGuard::assertExpectedLanguage('Check that it is clean and undamaged in the room.', 'en');
LanguageGuard::assertExpectedLanguage('Verificar se está limpo e sem danos no quarto.', 'pt');
LanguageGuard::assertExpectedLanguage('road house room stairs', 'en');
LanguageGuard::assertExpectedLanguage('casa quarto escada cama', 'pt');
assertHardening(true, 'clean EN and PT words remain accepted');

// Every complete natural-language word is checked independently.
foreach (['bloco', 'terreno', 'barco', 'nuvem', 'escada', 'quarto', 'cama', 'casa'] as $word) {
    assertHardeningInvalidWord(
        'Check that it is clean and undamaged. ' . $word,
        'en',
        $word,
        'EN input rejects complete non-EN word: ' . $word
    );
}

foreach (['block', 'land', 'boat', 'cloud', 'stairs', 'room', 'bed', 'curtain', 'house', 'road'] as $word) {
    assertHardeningInvalidWord(
        'Verificar se está limpo e sem danos. ' . $word,
        'pt',
        $word,
        'PT input rejects complete non-PT word: ' . $word
    );
}

// Nonsense/joined/typo tokens are validated as the complete word. No prefix,
// suffix or duplicated-character exceptions are required or allowed.
foreach (['ccasa', 'casaa', 'roadcasa', 'casaroad'] as $word) {
    assertHardeningInvalidWord(
        'Check the room carefully. ' . $word,
        'en',
        $word,
        'EN strict word validation rejects: ' . $word
    );
}
foreach (['hhouse', 'housee', 'casahouse', 'housecasa'] as $word) {
    assertHardeningInvalidWord(
        'Verificar o quarto cuidadosamente. ' . $word,
        'pt',
        $word,
        'PT strict word validation rejects: ' . $word
    );
}

// Whole-field opposite language remains blocked.
assertHardeningRejects('Verificar a limpeza da cozinha e das janelas.', 'en', 'whole Portuguese text is rejected in EN mode');
assertHardeningRejects('Check the kitchen, windows and curtains.', 'pt', 'whole English text is rejected in PT mode');

// Deliberately neutral technical/brand/loan terms remain usable in either mode.
foreach (['WiFi', 'My2N', 'Café', 'Hotel', 'Hostel', 'Airbnb', 'Booking', 'Netflix', 'Welcome'] as $neutral) {
    LanguageGuard::assertExpectedLanguage($neutral, 'en');
    LanguageGuard::assertExpectedLanguage($neutral, 'pt');
    assertHardening(LanguageGuard::confidentLanguage($neutral) === null, 'neutral term remains unclassified: ' . $neutral);
}
assertHardening(true, 'neutral technical, brand and loan terms remain accepted in both modes');

$guardSource = file_get_contents(dirname(__DIR__) . '/src/I18n/LanguageGuard.php');
assertHardening(is_string($guardSource), 'LanguageGuard source is readable');
assertHardening(str_contains($guardSource, "private const MODEL = 'large_2_1niz1ni';"), 'large EN/PT model is the production detector');
assertHardening(str_contains($guardSource, 'wordMatchesExpectedLanguage'), 'strict whole-word validator is present');
assertHardening(!str_contains($guardSource, 'embeddedOppositeParts'), 'prefix/suffix/duplicate-character special-case detector is removed');
assertHardening(!str_contains($guardSource, 'isConfidentOppositeComponent'), 'opposite-language subword helper is removed');
assertHardening(!str_contains($guardSource, 'isConfidentExpectedComponent'), 'expected-language subword helper is removed');
assertHardening(file_exists(dirname(__DIR__) . '/src/ThirdParty/efficient-language-detector/resources/ngrams/subset/large_2_1niz1ni.php'), 'large EN/PT detector data is vendored');
assertHardening(!file_exists(dirname(__DIR__) . '/src/ThirdParty/efficient-language-detector/resources/ngrams/subset/small_2_1niz1ni.php'), 'obsolete small detector data is removed');

echo "Language guard hardening regression passed.\n";
