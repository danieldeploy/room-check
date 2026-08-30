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

// Keep a small fake only for deterministic core PT/EN decisions. Production
// behaviour below is tested against the generated external resources.
$lexicon = translationRegressionClassifier();
assertHardening(LanguageGuard::sourceAnalysis('casa grande', 'pt', $lexicon)['conclusion'] === 'correct', 'casa grande is lexically Portuguese');
assertHardening(LanguageGuard::sourceAnalysis('new house', 'en', $lexicon)['conclusion'] === 'correct', 'new house is lexically English');
assertHardeningRejects('new house', 'pt', $lexicon, 'claramente EN', 'English text cannot be saved in PT mode');
assertHardening(LanguageGuard::sourceAnalysis('Verificar se está limpo house', 'pt', $lexicon)['conclusion'] === 'mixed', 'one EN word makes a PT sentence mixed');
assertHardening(LanguageGuard::sourceAnalysis('Verificar se está limpo qdsffasdfaasdf', 'pt', $lexicon)['conclusion'] === 'unknown', 'unknown text never inherits PT context');

$full = realLargeLexicon();
assertHardening($full->hasFullCoverage(), 'all generated validation resources are installed');

$coverage = $full->classifyTokens([
    'well', 'beautiful', 'necessary',
    'cadeira', 'coração', 'toalha',
    'spain', 'espanha', 'romania', 'roménia', 'spania',
    'sdsdadcsda', 'ggjhrtgu', 'pnsjdhd', 'hebde',
]);
assertHardening(($coverage['well'] ?? null) === 'en_only', 'ordinary English comes from the full EN-GB source');
assertHardening(($coverage['beautiful'] ?? null) === 'en_only', 'broad English vocabulary is covered');
assertHardening(($coverage['necessary'] ?? null) === 'en_only', 'additional English vocabulary is covered');
assertHardening(($coverage['cadeira'] ?? null) === 'pt_only', 'ordinary Portuguese comes from the full PT-PT source');
assertHardening(($coverage['coração'] ?? null) === 'pt_only', 'accented Portuguese is covered');
assertHardening(($coverage['toalha'] ?? null) === 'pt_only', 'hospitality Portuguese is covered');
assertHardening(($coverage['spain'] ?? null) === 'en_only', 'Spain is English country evidence');
assertHardening(($coverage['espanha'] ?? null) === 'pt_only', 'Espanha is Portuguese country evidence');
assertHardening(($coverage['romania'] ?? null) === 'en_only', 'Romania is English country evidence');
assertHardening(($coverage['roménia'] ?? null) === 'pt_only', 'Roménia is Portuguese country evidence');
assertHardening(($coverage['spania'] ?? null) === 'unknown', 'Romanian Spania is not accepted as PT or EN');
foreach (['sdsdadcsda', 'ggjhrtgu', 'pnsjdhd', 'hebde'] as $garbage) {
    assertHardening(($coverage[$garbage] ?? null) === 'unknown', "{$garbage} is absent from validated PT/EN sources");
}

$caseSensitive = $full->classifyCaseSensitiveTokens([
    'I', "I'm", "I'd", "I'll", "I've", 'i', "i'm", "i'd",
]);
foreach (['I', "I'm", "I'd", "I'll", "I've"] as $validatedCase) {
    assertHardening(isset($caseSensitive[$validatedCase]), "{$validatedCase} is preserved from CSpell keep-case data");
}
foreach (['i', "i'm", "i'd"] as $wrongCase) {
    assertHardening(!isset($caseSensitive[$wrongCase]), "{$wrongCase} does not replace the validated keep-case form");
}

$people = $full->classifyPersonTokens(['Michael', 'Ranjana', 'Ggjhrtgu', 'michael']);
assertHardening(isset($people['Michael']), 'Michael is recognized from sourced person-name data');
assertHardening(isset($people['Ranjana']), 'Ranjana is recognized from sourced global person-name data');
assertHardening(!isset($people['Ggjhrtgu']), 'capitalization alone never creates a person name');
assertHardening(!isset($people['michael']), 'person recognition requires normal name capitalization');

assertHardening(
    LanguageGuard::sourceAnalysis('Check that it is clean and undamaged. I feel good', 'en', $full)['conclusion'] === 'correct',
    'normal English containing I is accepted'
);
assertHardening(
    LanguageGuard::sourceAnalysis("I'm happy that the room is clean", 'en', $full)['conclusion'] === 'correct',
    'normal English contraction is accepted'
);
assertHardeningRejects(
    'Check that it is clean and undamaged. i feel good',
    'en',
    $full,
    'unrecognized word',
    'wrong lowercase i is rejected'
);

foreach (['Michael', 'Ranjana'] as $person) {
    assertHardening(
        LanguageGuard::sourceAnalysis('Verificar o quarto ' . $person, 'pt', $full)['conclusion'] === 'correct',
        "{$person} is neutral inside Portuguese text"
    );
    assertHardening(
        LanguageGuard::sourceAnalysis('Check the room ' . $person, 'en', $full)['conclusion'] === 'correct',
        "{$person} is neutral inside English text"
    );
}

foreach (['Ggjhrtgu', 'Pnsjdhd', 'Hebde'] as $garbage) {
    assertHardening(
        LanguageGuard::sourceAnalysis('Check the room ' . $garbage, 'en', $full)['conclusion'] === 'unknown',
        "{$garbage} is rejected in English despite capitalization"
    );
    assertHardening(
        LanguageGuard::sourceAnalysis('Verificar o quarto ' . $garbage, 'pt', $full)['conclusion'] === 'unknown',
        "{$garbage} is rejected in Portuguese despite capitalization"
    );
}

assertHardening(LanguageGuard::sourceAnalysis('Check the room in Spain', 'en', $full)['conclusion'] === 'correct', 'Spain is valid in English');
assertHardening(LanguageGuard::sourceAnalysis('Verificar o quarto em Espanha', 'pt', $full)['conclusion'] === 'correct', 'Espanha is valid in Portuguese');
assertHardening(LanguageGuard::sourceAnalysis('Check the room in Espanha', 'en', $full)['conclusion'] === 'mixed', 'Espanha is rejected inside English text');
assertHardening(LanguageGuard::sourceAnalysis('Verificar o quarto em Spain', 'pt', $full)['conclusion'] === 'mixed', 'Spain is rejected inside Portuguese text');
assertHardening(LanguageGuard::sourceAnalysis('Check the room in Spania', 'en', $full)['conclusion'] === 'unknown', 'Spania is rejected in English');
assertHardening(LanguageGuard::sourceAnalysis('Verificar o quarto em Spania', 'pt', $full)['conclusion'] === 'unknown', 'Spania is rejected in Portuguese');

assertHardening(
    !in_array(LanguageGuard::sourceAnalysis('Check the room "ZKTeco"', 'en', $full)['conclusion'], ['wrong', 'mixed', 'unknown'], true),
    'quoted custom technical text is accepted in English'
);
assertHardening(
    !in_array(LanguageGuard::sourceAnalysis('Verificar o quarto "ZKTeco"', 'pt', $full)['conclusion'], ['wrong', 'mixed', 'unknown'], true),
    'quoted custom technical text is accepted in Portuguese'
);
assertHardening(
    LanguageGuard::sourceAnalysis('Check the room Ggjhrtgu', 'en', $full)['conclusion'] === 'unknown',
    'the same custom token is rejected when unquoted'
);

$root = dirname(__DIR__);
$guardSource = file_get_contents($root . '/src/I18n/LanguageGuard.php');
$lexicalSource = file_get_contents($root . '/src/I18n/LexicalLanguageChecker.php');
$builderSource = file_get_contents($root . '/tools/build-large-lexicons.sh');
assertHardening(is_string($guardSource) && is_string($lexicalSource) && is_string($builderSource), 'language architecture sources are readable');
assertHardening(!str_contains($guardSource, 'looksTechnicalIdentifier'), 'capitalization-based technical bypass is removed');
assertHardening(!str_contains($lexicalSource, 'technical_neutral.txt'), 'runtime has no manual technical allowlist');
assertHardening(!str_contains($lexicalSource, 'en_GB_core.txt') && !str_contains($lexicalSource, 'pt_PT_core.txt'), 'runtime has no compact manual PT/EN dictionary fallback');
assertHardening(str_contains($lexicalSource, 'person_neutral.txt'), 'runtime uses generated person-name data');
assertHardening(str_contains($lexicalSource, '/resources/lexicon/full/'), 'runtime country and language resources are generated files');
assertHardening(str_contains($builderSource, 'cspell-people-names.txt'), 'person names include a maintained CSpell source');
assertHardening(str_contains($builderSource, 'global-popular-names.csv'), 'person names include a pinned global source');
assertHardening(str_contains($builderSource, 'cldr-en-territories.json') && str_contains($builderSource, 'cldr-pt-territories.json'), 'country names come from Unicode CLDR');
assertHardening(file_exists($root . '/resources/lexicon/full/person_neutral.txt'), 'generated person-name resource is bundled');
assertHardening(file_exists($root . '/resources/lexicon/full/person_neutral.index.json'), 'generated person-name index is bundled');
assertHardening(file_exists($root . '/resources/lexicon/full/country_en.txt'), 'generated English country resource is bundled');
assertHardening(file_exists($root . '/resources/lexicon/full/country_pt.txt'), 'generated Portuguese country resource is bundled');
assertHardening(!file_exists($root . '/resources/lexicon/full/proper_neutral.txt'), 'obsolete broad proper-name bypass is removed');

echo "Sourced PT/EN language guard regression passed.\n";
