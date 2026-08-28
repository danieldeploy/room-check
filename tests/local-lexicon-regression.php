<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/I18n/LanguageGuard.php';

function assertLocalLexicon(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

function assertLocalLexiconRejects(
    LexicalLanguageChecker $checker,
    string $text,
    string $expectedLanguage,
    string $fragment,
    string $message
): void {
    try {
        LanguageGuard::validateSource($text, $expectedLanguage, $checker);
    } catch (LanguageValidationException $exception) {
        assertLocalLexicon(str_contains($exception->getMessage(), $fragment), $message);
        return;
    }
    throw new RuntimeException('FAIL: ' . $message . ' — text was accepted');
}

$root = dirname(__DIR__);
$lexiconDir = $root . '/src/I18n/Lexicons';
foreach (['en_GB.dic', 'en_GB.aff', 'pt_PT.dic', 'pt_PT.aff', 'LICENSE_en_GB.txt', 'LICENSES_pt_PT.txt'] as $file) {
    assertLocalLexicon(is_file($lexiconDir . '/' . $file), "vendored {$file} is present");
}
assertLocalLexicon(filesize($lexiconDir . '/en_GB.dic') > 500000, 'en-GB dictionary is the full local spell lexicon');
assertLocalLexicon(filesize($lexiconDir . '/pt_PT.dic') > 500000, 'pt-PT dictionary is the full local spell lexicon');

$checker = new LexicalLanguageChecker();
$words = $checker->classifyTokens([
    'home', 'house', 'extinguisher', 'clean', 'windows', 'damaged',
    'casa', 'limpo', 'janelas', 'danos',
    'adad', 'jkgkgjjkj', 'qwffwqf',
]);

assertLocalLexicon(($words['home'] ?? null) === 'en_only', 'HAR: home is English-only');
assertLocalLexicon(($words['house'] ?? null) === 'en_only', 'house is English-only');
assertLocalLexicon(($words['extinguisher'] ?? null) === 'en_only', 'extinguisher is English-only');
assertLocalLexicon(($words['clean'] ?? null) === 'en_only', 'clean is English-only');
assertLocalLexicon(($words['windows'] ?? null) === 'en_only', 'English plural is recognised through the local spell lexicon');
assertLocalLexicon(($words['damaged'] ?? null) === 'en_only', 'English inflected form is recognised');
assertLocalLexicon(($words['casa'] ?? null) === 'pt_only', 'casa is Portuguese-only');
assertLocalLexicon(($words['limpo'] ?? null) === 'pt_only', 'limpo is Portuguese-only');
assertLocalLexicon(($words['janelas'] ?? null) === 'pt_only', 'Portuguese plural is recognised');
assertLocalLexicon(($words['danos'] ?? null) === 'pt_only', 'Portuguese inflected/plural form is recognised');
assertLocalLexicon(($words['adad'] ?? null) === 'unknown', 'HAR: adad is unknown rather than English');
assertLocalLexicon(($words['jkgkgjjkj'] ?? null) === 'unknown', 'HAR: random text remains unknown');
assertLocalLexicon(($words['qwffwqf'] ?? null) === 'unknown', 'HAR: second random token remains unknown');

$ptHome = LanguageGuard::sourceAnalysis('Verificar se está limpo home', 'pt', $checker);
assertLocalLexicon($ptHome['conclusion'] === 'mixed' && in_array('home', $ptHome['oppositeWords'], true), 'HAR: one home token makes PT text mixed');
assertLocalLexicon(LanguageGuard::sourceAnalysis('home', 'pt', $checker)['conclusion'] === 'wrong', 'HAR: home alone is rejected in PT mode');
assertLocalLexicon(LanguageGuard::sourceAnalysis('casa home', 'pt', $checker)['conclusion'] === 'mixed', 'HAR: casa home is mixed');

$wifiLower = LanguageGuard::sourceAnalysis('wifi', 'pt', $checker);
$wifiUpper = LanguageGuard::sourceAnalysis('WiFi', 'pt', $checker);
assertLocalLexicon($wifiLower['conclusion'] === 'ambiguous' && $wifiUpper['conclusion'] === 'ambiguous', 'HAR: wifi and WiFi are both neutral technical identifiers');
assertLocalLexicon(LanguageGuard::sourceAnalysis('Verificar se está limpo wifi', 'pt', $checker)['conclusion'] === 'correct', 'HAR: lowercase wifi does not turn a PT sentence mixed');
assertLocalLexicon(LanguageGuard::sourceAnalysis('yahoo', 'pt', $checker)['conclusion'] === 'ambiguous', 'HAR: Yahoo brand is neutral rather than English evidence');
assertLocalLexicon(LanguageGuard::sourceAnalysis('detector', 'pt', $checker)['conclusion'] === 'ambiguous', 'detector remains neutral/shared for technical use');
assertLocalLexicon(LanguageGuard::sourceAnalysis('adad', 'pt', $checker)['conclusion'] === 'unknown', 'HAR: adad reports unknown rather than wrong language');
assertLocalLexicon(LanguageGuard::sourceAnalysis('extinguisher', 'en', $checker)['conclusion'] === 'correct', 'extinguisher is valid in EN mode');
assertLocalLexiconRejects($checker, 'extinguisher', 'pt', 'claramente EN', 'extinguisher is blocked in PT mode');
assertLocalLexiconRejects($checker, 'Verificar o extinguisher', 'pt', 'mistura PT e EN', 'extinguisher inside PT text is reported as mixed');

LanguageGuard::validateSource('Verificar se está limpo e sem danos.', 'pt', $checker);
LanguageGuard::validateSource('Check that it is clean and undamaged.', 'en', $checker);
assertLocalLexicon(true, 'normal PT and EN hotel instructions remain valid');

$checkerSource = file_get_contents($root . '/src/I18n/LexicalLanguageChecker.php') ?: '';
$configSource = file_get_contents($root . '/config.php') ?: '';
assertLocalLexicon(!str_contains(mb_strtolower($checkerSource), 'wiktionary'), 'runtime checker contains no Wiktionary dependency');
assertLocalLexicon(!str_contains($checkerSource, 'curl_init'), 'runtime checker performs no lexical HTTP calls');
assertLocalLexicon(!str_contains($checkerSource, 'lexical_language_cache'), 'obsolete lexical MySQL cache is no longer used');
assertLocalLexicon(!str_contains($configSource, 'ROOM_CHECK_LEXICAL_ENDPOINT'), 'obsolete lexical endpoint configuration is removed');

echo "Local PT-PT/en-GB lexicon regression passed.\n";
