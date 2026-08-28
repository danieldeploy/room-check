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

assertHardening(
    LanguageGuard::sourceAnalysis('casa grande', 'pt', $lexicon)['conclusion'] === 'correct',
    'casa grande is lexically Portuguese'
);
assertHardening(
    LanguageGuard::sourceAnalysis('new house', 'pt', $lexicon)['conclusion'] === 'wrong',
    'new house is lexically English in PT mode'
);
assertHardening(
    LanguageGuard::sourceAnalysis('new house', 'en', $lexicon)['conclusion'] === 'correct',
    'new house is lexically English in EN mode'
);
assertHardeningRejects('new house', 'pt', $lexicon, 'claramente EN', 'new house cannot be saved in PT mode');

// The original failure that started this work: extinguisher is no longer judged
// by a sentence n-gram model. The lexical checker explicitly identifies it EN.
assertHardening(
    LanguageGuard::sourceAnalysis('extinguisher', 'en', $lexicon)['conclusion'] === 'correct',
    'extinguisher is recognised as English'
);
LanguageGuard::assertExpectedLanguage('extinguisher', 'en', $lexicon);
assertHardeningRejects('extinguisher', 'pt', $lexicon, 'claramente EN', 'extinguisher is rejected in PT mode');

// Shared words and explicit technical identifiers remain neutral rather than
// becoming false language errors.
assertHardening(
    LanguageGuard::sourceAnalysis('detector', 'pt', $lexicon)['conclusion'] === 'ambiguous',
    'detector is shared PT/EN'
);
assertHardening(
    LanguageGuard::sourceAnalysis('HVAC', 'pt', $lexicon)['conclusion'] === 'ambiguous',
    'HVAC remains a neutral technical identifier'
);
assertHardening(
    LanguageGuard::sourceAnalysis('WiFi', 'en', $lexicon)['conclusion'] === 'ambiguous',
    'WiFi remains a neutral technical identifier'
);

// HAR #7: one English word inside a Portuguese phrase is enough lexical evidence
// for mixed text. No two-word opposite-language segment is required anymore.
foreach (['house', 'news', 'common'] as $englishWord) {
    $text = 'Verificar se está limpo e sem danos ' . $englishWord;
    $analysis = LanguageGuard::sourceAnalysis($text, 'pt', $lexicon);
    assertHardening($analysis['conclusion'] === 'mixed', "{$englishWord} makes the PT sentence mixed");
    assertHardening(in_array($englishWord, array_map('mb_strtolower', $analysis['oppositeWords']), true), "{$englishWord} is reported as the EN word");
}
assertHardeningRejects(
    'Verificar se está limpo e sem danos house',
    'pt',
    $lexicon,
    'mistura PT e EN',
    'single EN word in PT sentence is blocked before translation'
);
assertHardeningRejects(
    'new house na nossa rua',
    'pt',
    $lexicon,
    'mistura PT e EN',
    'mixed PT/EN phrase cannot be normalized by translation'
);

// Unknown lowercase ordinary text is a separate spelling/lexical failure and is
// not silently assigned to the surrounding sentence language.
assertHardening(
    LanguageGuard::sourceAnalysis('Verificar se está limpo qdsffasdfaasdf', 'pt', $lexicon)['conclusion'] === 'unknown',
    'unknown ordinary word remains unknown inside a PT sentence'
);
assertHardeningRejects(
    'Verificar se está limpo qdsffasdfaasdf',
    'pt',
    $lexicon,
    'palavra não reconhecida',
    'unknown ordinary word is not saved'
);

assertHardeningRejects(
    'Verificar a limpeza da cozinha e das janelas',
    'en',
    $lexicon,
    'clearly PT',
    'whole Portuguese text is rejected in EN mode'
);
assertHardeningRejects(
    'Check the kitchen windows and curtains',
    'pt',
    $lexicon,
    'claramente EN',
    'whole English text is rejected in PT mode'
);

$guardSource = file_get_contents(dirname(__DIR__) . '/src/I18n/LanguageGuard.php');
$lexicalSource = file_get_contents(dirname(__DIR__) . '/src/I18n/LexicalLanguageChecker.php');
assertHardening(is_string($guardSource) && is_string($lexicalSource), 'language verifier sources are readable');
assertHardening(str_contains($guardSource, "private const MODEL = 'large_2_1niz1ni';"), 'sentence context detector remains available');
assertHardening(str_contains($guardSource, 'LexicalLanguageClassifier'), 'source validation is driven by lexical evidence');
assertHardening(!str_contains($guardSource, 'SHORT_MIN_'), 'obsolete short-phrase statistical thresholds are removed');
assertHardening(!str_contains($guardSource, 'MIXED_MIN_'), 'obsolete mixed-segment statistical thresholds are removed');
assertHardening(!str_contains($guardSource, 'hasMixedLanguageEvidence'), 'obsolete multi-word segment scanner is removed');
assertHardening(!str_contains($guardSource, 'private const NEUTRAL'), 'manual technical vocabulary whitelist remains removed');
assertHardening(str_contains($lexicalSource, '==\\s*English') && str_contains($lexicalSource, '==\\s*Portuguese'), 'lexical checker uses explicit EN/PT dictionary sections');
assertHardening(str_contains($lexicalSource, 'lexical_language_cache'), 'lexical results are cached persistently');

assertHardening(
    LexicalLanguageChecker::classifyWikitext("==English==\n===Noun===\n") === 'en_only',
    'English dictionary section is parsed deterministically'
);
assertHardening(
    LexicalLanguageChecker::classifyWikitext("==Portuguese==\n===Noun===\n") === 'pt_only',
    'Portuguese dictionary section is parsed deterministically'
);
assertHardening(
    LexicalLanguageChecker::classifyWikitext("==English==\n===Noun===\n==Portuguese==\n===Noun===\n") === 'shared',
    'shared PT/EN dictionary entry is detected'
);
assertHardening(
    LexicalLanguageChecker::classifyWikitext("==French==\n===Noun===\n") === 'unknown',
    'non-PT/EN dictionary entry stays unknown'
);

echo "Lexical PT/EN language guard regression passed.\n";
