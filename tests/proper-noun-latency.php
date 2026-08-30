<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/I18n/LanguageGuard.php';
require_once dirname(__DIR__) . '/src/I18n/ContentTranslator.php';

function assertProperLatency(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$reflection = new ReflectionClass(LexicalLanguageChecker::class);
/** @var LexicalLanguageChecker $checker */
$checker = $reflection->newInstanceWithoutConstructor();
$config = $reflection->getProperty('config');
$config->setAccessible(true);
$config->setValue($checker, []);

assertProperLatency($checker->hasFullCoverage(), 'generated language, person and country resources are installed');

$people = $checker->classifyPersonTokens(['Michael', 'Ranjana', 'Ggjhrtgu', 'michael']);
assertProperLatency(isset($people['Michael']), 'Michael is a sourced person name');
assertProperLatency(isset($people['Ranjana']), 'Ranjana is a sourced person name');
assertProperLatency(!isset($people['Ggjhrtgu']), 'invented capitalized text is not a person name');
assertProperLatency(!isset($people['michael']), 'lowercase spelling is not treated as a person-name escape');

$classified = $checker->classifyTokens([
    'spain', 'germany', 'romania', 'espanha', 'alemanha', 'roménia', 'spania',
    'house', 'well', 'danos', 'yahoosads',
]);
foreach (['spain', 'germany', 'romania'] as $country) {
    assertProperLatency(($classified[$country] ?? null) === 'en_only', "{$country} is English country evidence");
}
foreach (['espanha', 'alemanha', 'roménia'] as $country) {
    assertProperLatency(($classified[$country] ?? null) === 'pt_only', "{$country} is Portuguese country evidence");
}
assertProperLatency(($classified['spania'] ?? null) === 'unknown', 'Romanian Spania is not PT/EN country evidence');
assertProperLatency(($classified['house'] ?? null) === 'en_only', 'ordinary English remains English');
assertProperLatency(($classified['well'] ?? null) === 'en_only', 'broad English coverage remains intact');
assertProperLatency(($classified['danos'] ?? null) === 'pt_only', 'ordinary Portuguese remains Portuguese');
assertProperLatency(($classified['yahoosads'] ?? null) === 'unknown', 'arbitrary garbage remains unknown');

foreach (['Michael', 'Ranjana'] as $person) {
    assertProperLatency(
        LanguageGuard::sourceAnalysis('Verificar o quarto ' . $person, 'pt', $checker)['conclusion'] === 'correct',
        "Portuguese sentence preserves {$person} as neutral person evidence"
    );
    assertProperLatency(
        LanguageGuard::sourceAnalysis('Check the room ' . $person, 'en', $checker)['conclusion'] === 'correct',
        "English sentence preserves {$person} as neutral person evidence"
    );
}
assertProperLatency(
    LanguageGuard::sourceAnalysis('Verificar o quarto Espanha', 'pt', $checker)['conclusion'] === 'correct',
    'Portuguese country name is accepted in Portuguese'
);
assertProperLatency(
    LanguageGuard::sourceAnalysis('Verificar o quarto Spain', 'pt', $checker)['conclusion'] === 'mixed',
    'English country name is rejected in Portuguese'
);
assertProperLatency(
    LanguageGuard::sourceAnalysis('Check the room in Germany', 'en', $checker)['conclusion'] === 'correct',
    'English country name is accepted in English'
);
assertProperLatency(
    LanguageGuard::sourceAnalysis('Check the room in Espanha', 'en', $checker)['conclusion'] === 'mixed',
    'Portuguese country name is rejected in English'
);
assertProperLatency(
    LanguageGuard::sourceAnalysis('Check the room in Spania', 'en', $checker)['conclusion'] === 'unknown',
    'Romanian country spelling is rejected in English'
);
assertProperLatency(
    LanguageGuard::sourceAnalysis('Verificar o quarto em Spania', 'pt', $checker)['conclusion'] === 'unknown',
    'Romanian country spelling is rejected in Portuguese'
);
assertProperLatency(
    LanguageGuard::sourceAnalysis('Check room Ggjhrtgu', 'en', $checker)['conclusion'] === 'unknown',
    'capitalized garbage cannot hide as a person name'
);

$root = dirname(__DIR__);
$personPath = $root . '/resources/lexicon/full/person_neutral.txt';
$personIndex = $root . '/resources/lexicon/full/person_neutral.index.json';
$countryPtPath = $root . '/resources/lexicon/full/country_pt.txt';
$countryEnPath = $root . '/resources/lexicon/full/country_en.txt';
assertProperLatency(is_file($personPath) && filesize($personPath) > 10000, 'generated global person-name resource is bundled');
assertProperLatency(is_file($personIndex) && filesize($personIndex) > 0, 'person-name index is bundled');
assertProperLatency(is_file($countryPtPath) && filesize($countryPtPath) > 0, 'generated Portuguese CLDR country resource is bundled');
assertProperLatency(is_file($countryEnPath) && filesize($countryEnPath) > 0, 'generated English CLDR country resource is bundled');
assertProperLatency(!is_file($root . '/resources/lexicon/full/proper_neutral.txt'), 'generic proper-name bypass is removed');

$lexicalSource = file_get_contents($root . '/src/I18n/LexicalLanguageChecker.php');
$translatorSource = file_get_contents($root . '/src/I18n/ContentTranslator.php');
assertProperLatency(is_string($lexicalSource) && is_string($translatorSource), 'runtime translation sources are readable');
assertProperLatency(str_contains($lexicalSource, 'membershipCache'), 'lexical membership results are cached within the request');
assertProperLatency(str_contains($lexicalSource, 'classifyPersonTokens'), 'runtime exposes sourced person classification');
assertProperLatency(str_contains($lexicalSource, 'classifyEntityTokens'), 'translator compatibility exposes sourced people and countries');
assertProperLatency(!str_contains($lexicalSource, 'technical_neutral.txt'), 'runtime has no manual technical allowlist');
assertProperLatency(
    str_contains($translatorSource, "in_array(\$analysis['conclusion'], ['wrong', 'mixed'], true)"),
    'translation retry remains restricted to wrong/mixed provider output'
);

echo "Sourced person/country and save-latency regression passed.\n";
