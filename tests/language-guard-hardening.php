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

function realLargeLexicon(): LexicalLanguageChecker
{
    $reflection = new ReflectionClass(LexicalLanguageChecker::class);
    /** @var LexicalLanguageChecker $checker */
    $checker = $reflection->newInstanceWithoutConstructor();
    $property = $reflection->getProperty('config');
    $property->setAccessible(true);
    $property->setValue($checker, []);
    return $checker;
}

// Core regression classifier keeps the historical language decisions isolated
// from the size/content of the generated dictionaries.
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

assertHardening(LanguageGuard::sourceAnalysis('Verificar se está limpo qdsffasdfaasdf', 'pt', $lexicon)['conclusion'] === 'unknown', 'obvious gibberish remains rejected even in compact fallback mode');
assertHardeningRejects('Verificar se está limpo qdsffasdfaasdf', 'pt', $lexicon, 'palavra não reconhecida', 'obvious gibberish is not saved');

// Keep the controlled short-fragment fix from the latest HAR without applying
// strict-unknown semantics to an intentionally compact test dictionary.
foreach (['s', 'sr'] as $fragment) {
    assertHardening(
        LanguageGuard::sourceAnalysis('Verificar se está limpo e sem danos ' . $fragment, 'pt', $lexicon)['conclusion'] === 'unknown',
        "{$fragment} is rejected as an unresolved short PT fragment"
    );
    assertHardening(
        LanguageGuard::sourceAnalysis('Check that the room is clean ' . $fragment, 'en', $lexicon)['conclusion'] === 'unknown',
        "{$fragment} is rejected as an unresolved short EN fragment"
    );
}

// Near-match hardening from the nearly-good version stays intact.
assertHardening(LanguageGuard::sourceAnalysis('YAHOO', 'pt', $lexicon)['conclusion'] === 'ambiguous', 'exact Yahoo brand token remains neutral');
foreach (['YAHOOX', 'YAHOOAB', 'YahooX', 'YahooAB'] as $corruptedYahoo) {
    $analysis = LanguageGuard::sourceAnalysis('Verificar se está limpo ' . $corruptedYahoo, 'pt', $lexicon);
    assertHardening($analysis['conclusion'] === 'unknown', "{$corruptedYahoo} is rejected as a near-known typo");
    assertHardening(in_array($corruptedYahoo, $analysis['likelyMisspellings'], true), "{$corruptedYahoo} is marked as a likely misspelling");
}
assertHardening(LanguageGuard::sourceAnalysis('danos', 'pt', $lexicon)['conclusion'] === 'correct', 'exact danos remains valid Portuguese');
foreach (['danosx', 'danosht', 'danosab', 'DANOSHT'] as $corruptedDanos) {
    $analysis = LanguageGuard::sourceAnalysis('Verificar se está limpo e sem ' . $corruptedDanos, 'pt', $lexicon);
    assertHardening($analysis['conclusion'] === 'unknown', "{$corruptedDanos} is rejected as a near-known typo");
    assertHardening(in_array($corruptedDanos, $analysis['likelyMisspellings'], true), "{$corruptedDanos} is marked as a likely misspelling");
}

// The important new safety net: test the REAL generated large resources, not
// only a hand-sized fake dictionary. This is what PR #47 was missing.
$full = realLargeLexicon();
assertHardening($full->hasFullCoverage(), 'both generated full PT-PT and EN-GB lexical resources are installed');
$coverage = $full->classifyTokens([
    'well', 'beautiful', 'necessary',
    'cadeira', 'coração', 'toalha',
    'sdsdadcsda', 'yahoosads', 'drtiopyewrwerertwedf',
]);
assertHardening(($coverage['well'] ?? null) === 'en_only', 'well is covered by the full English lexicon');
assertHardening(($coverage['beautiful'] ?? null) === 'en_only', 'ordinary English outside the old core is covered');
assertHardening(($coverage['necessary'] ?? null) === 'en_only', 'additional ordinary English is covered');
assertHardening(($coverage['cadeira'] ?? null) === 'pt_only', 'ordinary Portuguese outside the old core is covered');
assertHardening(($coverage['coração'] ?? null) === 'pt_only', 'accented Portuguese vocabulary is covered');
assertHardening(($coverage['toalha'] ?? null) === 'pt_only', 'hospitality Portuguese vocabulary is covered');
foreach (['sdsdadcsda', 'yahoosads', 'drtiopyewrwerertwedf'] as $garbage) {
    assertHardening(($coverage[$garbage] ?? null) === 'unknown', "{$garbage} is absent from both full dictionaries");
    assertHardening(
        LanguageGuard::sourceAnalysis('Verificar se está limpo e sem danos ' . $garbage, 'pt', $full)['conclusion'] === 'unknown',
        "{$garbage} cannot inherit PT context when full coverage is available"
    );
    assertHardening(
        LanguageGuard::sourceAnalysis('Check that the room is clean ' . $garbage, 'en', $full)['conclusion'] === 'unknown',
        "{$garbage} cannot inherit EN context when full coverage is available"
    );
}
assertHardening(LanguageGuard::sourceAnalysis('Verificar uma cadeira limpa e uma toalha limpa', 'pt', $full)['conclusion'] === 'correct', 'normal broader Portuguese sentence is accepted with full coverage');
assertHardening(LanguageGuard::sourceAnalysis('Check the beautiful room and clean the window well', 'en', $full)['conclusion'] === 'correct', 'normal broader English sentence is accepted with full coverage');
assertHardening(LanguageGuard::sourceAnalysis('Verificar cadeira house', 'pt', $full)['conclusion'] === 'mixed', 'full dictionaries still reject EN intrusion in PT text');

assertHardeningRejects('Verificar a limpeza da cozinha e das janelas', 'en', $lexicon, 'clearly PT', 'whole Portuguese text is rejected in EN mode');
assertHardeningRejects('Check the kitchen windows and curtains', 'pt', $lexicon, 'claramente EN', 'whole English text is rejected in PT mode');

$root = dirname(__DIR__);
$guardSource = file_get_contents($root . '/src/I18n/LanguageGuard.php');
$lexicalSource = file_get_contents($root . '/src/I18n/LexicalLanguageChecker.php');
$technicalLexicon = file_get_contents($root . '/resources/lexicon/technical_neutral.txt');
assertHardening(is_string($guardSource) && is_string($lexicalSource) && is_string($technicalLexicon), 'language verifier sources are readable');
assertHardening(str_contains($guardSource, 'LexicalCoverageClassifier'), 'strict unknown handling is gated on full lexical coverage');
assertHardening(str_contains($guardSource, 'looksGibberishToken'), 'safe compact-dictionary recovery fallback remains available');
assertHardening(!str_contains($guardSource, '/^[\\p{Lu}]{2,}$/u'), 'ALL-CAPS words are not automatically technical');
assertHardening(!str_contains($guardSource, '$length <= 2'), 'short fragments are not automatically technical');
assertHardening(str_contains($lexicalSource, 'indexedMembership') && str_contains($lexicalSource, 'fseek'), 'large lexicons are read through indexed slices instead of huge PHP arrays');
assertHardening(!str_contains($lexicalSource, 'curl_init') && !str_contains(mb_strtolower($lexicalSource), 'wiktionary'), 'runtime lexical verification has no network dependency');
assertHardening(file_exists($root . '/resources/lexicon/full/en_GB.txt') && filesize($root . '/resources/lexicon/full/en_GB.txt') > 500000, 'large EN-GB resource is bundled');
assertHardening(file_exists($root . '/resources/lexicon/full/pt_PT.txt') && filesize($root . '/resources/lexicon/full/pt_PT.txt') > 5000000, 'large PT-PT resource is bundled');
assertHardening(file_exists($root . '/resources/lexicon/full/licenses/en_GB-MIT-LICENSE.txt'), 'English dictionary license is bundled');
assertHardening(file_exists($root . '/resources/lexicon/full/licenses/pt_PT-LICENSE.txt'), 'Portuguese dictionary license is bundled');
assertHardening(str_contains($technicalLexicon, 'wifi') && str_contains($technicalLexicon, 'hvac') && str_contains($technicalLexicon, 'yahoo'), 'exact technical/brand neutral terms remain preserved');

echo "Recovered large-lexicon PT/EN language guard regression passed.\n";
