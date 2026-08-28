<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/I18n/LanguageGuard.php';
require_once __DIR__ . '/support/FakeLexicalLanguageClassifier.php';

function assertHardening(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

function assertHardeningRejects(
    string $text,
    string $expectedLanguage,
    LexicalLanguageClassifier $lexicon,
    string $fragment,
    string $message
): void {
    try {
        LanguageGuard::assertExpectedLanguage($text, $expectedLanguage, $lexicon);
    } catch (LanguageValidationException $exception) {
        assertHardening(str_contains($exception->getMessage(), $fragment), $message);
        return;
    }
    throw new RuntimeException('FAIL: ' . $message . ' — text was accepted');
}

$lexicon = translationRegressionClassifier();

assertHardening(LanguageGuard::sourceAnalysis('casa grande', 'pt', $lexicon)['conclusion'] === 'correct', 'casa grande is lexically Portuguese');
assertHardening(LanguageGuard::sourceAnalysis('new house', 'pt', $lexicon)['conclusion'] === 'wrong', 'new house is lexically English in PT mode');
assertHardening(LanguageGuard::sourceAnalysis('new house', 'en', $lexicon)['conclusion'] === 'correct', 'new house is lexically English in EN mode');
assertHardeningRejects('new house', 'pt', $lexicon, 'claramente EN', 'new house cannot be saved in PT mode');

assertHardening(LanguageGuard::sourceAnalysis('extinguisher', 'en', $lexicon)['conclusion'] === 'correct', 'extinguisher is recognised as English');
LanguageGuard::assertExpectedLanguage('extinguisher', 'en', $lexicon);
assertHardeningRejects('extinguisher', 'pt', $lexicon, 'claramente EN', 'extinguisher is rejected in PT mode');

assertHardening(LanguageGuard::sourceAnalysis('detector', 'pt', $lexicon)['conclusion'] === 'ambiguous', 'detector is shared PT/EN');
assertHardening(LanguageGuard::sourceAnalysis('HVAC', 'pt', $lexicon)['conclusion'] === 'ambiguous', 'HVAC remains a neutral technical identifier');
assertHardening(LanguageGuard::sourceAnalysis('WiFi', 'en', $lexicon)['conclusion'] === 'ambiguous', 'WiFi remains a neutral technical identifier');
assertHardening(LanguageGuard::sourceAnalysis('ZKTeco', 'pt', $lexicon)['conclusion'] === 'ambiguous', 'ZKTeco remains a neutral technical identifier');

foreach (['house', 'news', 'common'] as $englishWord) {
    $text = 'Verificar se está limpo e sem danos ' . $englishWord;
    $analysis = LanguageGuard::sourceAnalysis($text, 'pt', $lexicon);
    assertHardening($analysis['conclusion'] === 'mixed', "{$englishWord} makes the PT sentence mixed");
    assertHardening(in_array($englishWord, array_map('mb_strtolower', $analysis['oppositeWords']), true), "{$englishWord} is reported as the EN word");
}
assertHardeningRejects('Verificar se está limpo e sem danos house', 'pt', $lexicon, 'mistura PT e EN', 'single EN word in PT sentence is blocked before translation');
assertHardeningRejects('new house na nossa rua', 'pt', $lexicon, 'mistura PT e EN', 'mixed PT/EN phrase cannot be normalized by translation');

assertHardening(LanguageGuard::sourceAnalysis('Verificar se está limpo qdsffasdfaasdf', 'pt', $lexicon)['conclusion'] === 'unknown', 'unknown ordinary word remains unknown inside a PT sentence');
assertHardeningRejects('Verificar se está limpo qdsffasdfaasdf', 'pt', $lexicon, 'palavra não reconhecida', 'unknown ordinary word is not saved');

// Latest HAR: sentence context must never promote unresolved lexical fragments
// or arbitrary text to technical vocabulary. This rule is symmetric for PT/EN.
foreach (['s', 'sr', 'sdsdadcsda', 'yahoosads', 'drtiopyewrwerertwedf'] as $unknownWord) {
    $ptText = 'Verificar se está limpo e sem danos ' . $unknownWord;
    assertHardening(
        LanguageGuard::sourceAnalysis($ptText, 'pt', $lexicon)['conclusion'] === 'unknown',
        "{$unknownWord} remains unknown inside valid PT context"
    );
    assertHardeningRejects($ptText, 'pt', $lexicon, 'não reconhecida', "{$unknownWord} cannot inherit PT context");

    $enText = 'Check that the room is clean ' . $unknownWord;
    assertHardening(
        LanguageGuard::sourceAnalysis($enText, 'en', $lexicon)['conclusion'] === 'unknown',
        "{$unknownWord} remains unknown inside valid EN context"
    );
    assertHardeningRejects($enText, 'en', $lexicon, 'unrecognized', "{$unknownWord} cannot inherit EN context");
}

// The HAR also exposed a legitimate English word missing from the compact core.
assertHardening(LanguageGuard::sourceAnalysis('well', 'en', $lexicon)['conclusion'] === 'correct', 'well is recognised as English');
assertHardeningRejects('well', 'pt', $lexicon, 'claramente EN', 'well is rejected in PT mode');

// Final HAR hardening: exact known terms stay accepted, but one/two appended
// letters are treated as likely typing corruption before sentence context can
// hide the unknown token as a technical word.
assertHardening(LanguageGuard::sourceAnalysis('YAHOO', 'pt', $lexicon)['conclusion'] === 'ambiguous', 'exact Yahoo brand token remains neutral');
foreach (['YAHOOX', 'YAHOOAB', 'YahooX', 'YahooAB'] as $corruptedYahoo) {
    $analysis = LanguageGuard::sourceAnalysis('Verificar se está limpo ' . $corruptedYahoo, 'pt', $lexicon);
    assertHardening($analysis['conclusion'] === 'unknown', "{$corruptedYahoo} is rejected as a near-known typo");
    assertHardening(in_array($corruptedYahoo, $analysis['likelyMisspellings'], true), "{$corruptedYahoo} is marked as a likely misspelling");
    assertHardeningRejects('Verificar se está limpo ' . $corruptedYahoo, 'pt', $lexicon, 'palavra não reconhecida', "{$corruptedYahoo} cannot be saved");
}

assertHardening(LanguageGuard::sourceAnalysis('danos', 'pt', $lexicon)['conclusion'] === 'correct', 'exact danos remains valid Portuguese');
foreach (['danosx', 'danosht', 'danosab', 'DANOSHT'] as $corruptedDanos) {
    $analysis = LanguageGuard::sourceAnalysis('Verificar se está limpo e sem ' . $corruptedDanos, 'pt', $lexicon);
    assertHardening($analysis['conclusion'] === 'unknown', "{$corruptedDanos} is rejected as a near-known typo");
    assertHardening(in_array($corruptedDanos, $analysis['likelyMisspellings'], true), "{$corruptedDanos} is marked as a likely misspelling");
    assertHardeningRejects('Verificar se está limpo e sem ' . $corruptedDanos, 'pt', $lexicon, 'palavra não reconhecida', "{$corruptedDanos} cannot be saved");
}

assertHardeningRejects('Verificar a limpeza da cozinha e das janelas', 'en', $lexicon, 'clearly PT', 'whole Portuguese text is rejected in EN mode');
assertHardeningRejects('Check the kitchen windows and curtains', 'pt', $lexicon, 'claramente EN', 'whole English text is rejected in PT mode');

$guardSource = file_get_contents(dirname(__DIR__) . '/src/I18n/LanguageGuard.php');
$lexicalSource = file_get_contents(dirname(__DIR__) . '/src/I18n/LexicalLanguageChecker.php');
$ptLexicon = file_get_contents(dirname(__DIR__) . '/resources/lexicon/pt_PT_core.txt');
$enLexicon = file_get_contents(dirname(__DIR__) . '/resources/lexicon/en_GB_core.txt');
$technicalLexicon = file_get_contents(dirname(__DIR__) . '/resources/lexicon/technical_neutral.txt');
assertHardening(is_string($guardSource) && is_string($lexicalSource), 'language verifier sources are readable');
assertHardening(str_contains($guardSource, "private const MODEL = 'large_2_1niz1ni';"), 'sentence context detector remains available');
assertHardening(str_contains($guardSource, 'LexicalLanguageClassifier'), 'source validation is driven by lexical evidence');
assertHardening(str_contains($guardSource, 'LexicalNearMatchClassifier'), 'near-known typo evidence is retained');
assertHardening(!str_contains($guardSource, '/^[\\p{Lu}]{2,}$/u'), 'all-uppercase words are not automatically accepted as technical identifiers');
assertHardening(!str_contains($guardSource, '$length <= 2'), 'one/two-letter fragments are not automatically accepted as technical identifiers');
assertHardening(!str_contains($guardSource, 'looksGibberishToken'), 'obsolete gibberish heuristic is removed now that ordinary unknown words are rejected deterministically');
assertHardening(!str_contains($guardSource, 'SHORT_MIN_'), 'obsolete short-phrase statistical thresholds are removed');
assertHardening(!str_contains($guardSource, 'MIXED_MIN_'), 'obsolete mixed-segment statistical thresholds are removed');
assertHardening(!str_contains($guardSource, 'hasMixedLanguageEvidence'), 'obsolete multi-word segment scanner is removed');
assertHardening(!str_contains($lexicalSource, 'curl_init') && !str_contains(mb_strtolower($lexicalSource), 'wiktionary'), 'local lexical checker makes no runtime network request');
assertHardening(!str_contains($lexicalSource, 'lexical_language_cache'), 'obsolete lexical database cache is removed');
assertHardening(str_contains($lexicalSource, 'likelyMisspelling') && str_contains($lexicalSource, 'unicodeEditDistance'), 'local lexical checker retains bounded UTF-8 near-match checks');
assertHardening(is_string($ptLexicon) && is_string($enLexicon) && is_string($technicalLexicon), 'bundled PT/EN/technical lexicons are readable');
assertHardening(str_contains($enLexicon, "\nextinguisher\n") && str_contains($enLexicon, "\nhome\n") && str_contains($enLexicon, "\nhouse\n") && str_contains($enLexicon, "\nwell\n"), 'observed English words are bundled locally');
assertHardening(str_contains($ptLexicon, "\ndanos\n") && str_contains($ptLexicon, "\ncasa\n") && str_contains($ptLexicon, "\nverificar\n"), 'Portuguese core vocabulary includes danos and remains bundled locally');
assertHardening(str_contains($technicalLexicon, 'wifi') && str_contains($technicalLexicon, 'hvac') && str_contains($technicalLexicon, 'yahoo'), 'WiFi, HVAC and Yahoo are exact neutral terms regardless of case');

echo "Strict local lexical PT/EN language guard regression passed.\n";
