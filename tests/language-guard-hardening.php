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
assertHardening(true, 'clean EN and PT text remains accepted');

// Exact false-negative family captured in the HAR: a mostly-English sentence
// must reject a Portuguese word even when the whole sentence is still EN.
foreach (['bloco', 'terreno', 'barco', 'nuvem', 'escada', 'quarto', 'cama'] as $word) {
    assertHardeningRejects(
        'Check that it is clean and undamaged. ' . $word,
        'en',
        'EN input rejects Portuguese component: ' . $word
    );
}

// Symmetric PT -> EN protection.
foreach (['block', 'land', 'boat', 'cloud', 'stairs', 'room', 'bed', 'curtain'] as $word) {
    assertHardeningRejects(
        'Verificar se está limpo e sem danos. ' . $word,
        'pt',
        'PT input rejects English component: ' . $word
    );
}

// Joined mixed-language tokens: prefix and suffix are protected in both directions.
assertHardeningInvalidWord(
    'Check the roadcasa carefully.',
    'en',
    'casa',
    'EN input detects PT suffix inside joined token: roadcasa'
);
assertHardeningInvalidWord(
    'Check the casaroad carefully.',
    'en',
    'casa',
    'EN input detects PT prefix inside joined token: casaroad'
);
assertHardeningInvalidWord(
    'Verificar a casaroad cuidadosamente.',
    'pt',
    'road',
    'PT input detects EN suffix inside joined token: casaroad'
);
assertHardeningInvalidWord(
    'Verificar a roadcasa cuidadosamente.',
    'pt',
    'road',
    'PT input detects EN prefix inside joined token: roadcasa'
);

// Duplicated edge characters must not hide a confident opposite-language word.
assertHardeningInvalidWord(
    'Check the ccasa carefully.',
    'en',
    'casa',
    'EN input detects PT word after duplicated leading character: ccasa'
);
assertHardeningInvalidWord(
    'Check the casaa carefully.',
    'en',
    'casa',
    'EN input detects PT word before duplicated trailing character: casaa'
);
assertHardeningInvalidWord(
    'Verificar a rroad cuidadosamente.',
    'pt',
    'road',
    'PT input detects EN word after duplicated leading character: rroad'
);
assertHardeningInvalidWord(
    'Verificar a roadd cuidadosamente.',
    'pt',
    'road',
    'PT input detects EN word before duplicated trailing character: roadd'
);

// Whole-field opposite language remains blocked.
assertHardeningRejects('Verificar a limpeza da cozinha e das janelas.', 'en', 'whole Portuguese text is rejected in EN mode');
assertHardeningRejects('Check the kitchen, windows and curtains.', 'pt', 'whole English text is rejected in PT mode');

// Deliberately neutral technical/brand/loan terms must remain usable.
foreach (['WiFi', 'My2N', 'Café', 'Hotel', 'Hostel', 'Airbnb', 'Booking', 'Netflix', 'Welcome'] as $neutral) {
    LanguageGuard::assertExpectedLanguage($neutral, 'en');
    LanguageGuard::assertExpectedLanguage($neutral, 'pt');
    assertHardening(LanguageGuard::confidentLanguage($neutral) === null, 'neutral term remains unclassified: ' . $neutral);
}
assertHardening(true, 'neutral technical, brand and loan terms remain accepted in both modes');

$guardSource = file_get_contents(dirname(__DIR__) . '/src/I18n/LanguageGuard.php');
assertHardening(is_string($guardSource), 'LanguageGuard source is readable');
assertHardening(str_contains($guardSource, "private const MODEL = 'large_2_1niz1ni';"), 'large EN/PT model is the production detector');
assertHardening(!str_contains($guardSource, "private const MODEL = 'small_2_1niz1ni';"), 'small detector cannot silently return');
assertHardening(file_exists(dirname(__DIR__) . '/src/ThirdParty/efficient-language-detector/resources/ngrams/subset/large_2_1niz1ni.php'), 'large EN/PT detector data is vendored');
assertHardening(!file_exists(dirname(__DIR__) . '/src/ThirdParty/efficient-language-detector/resources/ngrams/subset/small_2_1niz1ni.php'), 'obsolete small detector data is removed');

echo "Language guard hardening regression passed.\n";
