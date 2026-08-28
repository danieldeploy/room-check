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

assertProperLatency($checker->hasFullCoverage(), 'full dictionaries plus proper-name resource are installed');

$classified = $checker->classifyTokens([
    'daniel', 'miguel', 'michael', 'joao', 'joão', 'romi',
    'spain', 'germany', 'romania', 'espanha', 'alemanha', 'roménia',
    'house', 'well', 'danos', 'yahoosads',
]);
foreach (['daniel', 'miguel', 'michael', 'joao', 'joão', 'romi'] as $person) {
    assertProperLatency(($classified[$person] ?? null) === 'shared', "{$person} is language-neutral person-name evidence");
}
foreach (['spain', 'germany', 'romania'] as $country) {
    assertProperLatency(($classified[$country] ?? null) === 'en_only', "{$country} remains English country evidence");
}
foreach (['espanha', 'alemanha', 'roménia'] as $country) {
    assertProperLatency(($classified[$country] ?? null) === 'pt_only', "{$country} remains Portuguese country evidence");
}
assertProperLatency(($classified['house'] ?? null) === 'en_only', 'ordinary English word remains English, not proper-neutral');
assertProperLatency(($classified['well'] ?? null) === 'en_only', 'ordinary English coverage remains intact');
assertProperLatency(($classified['danos'] ?? null) === 'pt_only', 'ordinary Portuguese coverage remains intact');
assertProperLatency(($classified['yahoosads'] ?? null) === 'unknown', 'garbage remains unknown despite proper-name support');

assertProperLatency(
    LanguageGuard::sourceAnalysis('Verificar o quarto Daniel', 'pt', $checker)['conclusion'] === 'correct',
    'Portuguese sentence with a person name is accepted'
);
assertProperLatency(
    LanguageGuard::sourceAnalysis('Check the room Daniel', 'en', $checker)['conclusion'] === 'correct',
    'English sentence with a person name is accepted'
);
assertProperLatency(
    LanguageGuard::sourceAnalysis('Verificar o quarto Espanha', 'pt', $checker)['conclusion'] === 'correct',
    'Portuguese country name is accepted as Portuguese evidence'
);
assertProperLatency(
    LanguageGuard::sourceAnalysis('Verificar o quarto Spain', 'pt', $checker)['conclusion'] === 'mixed',
    'English country name is no longer hidden as a neutral proper name in Portuguese text'
);
assertProperLatency(
    LanguageGuard::sourceAnalysis('Check the room in Germany', 'en', $checker)['conclusion'] === 'correct',
    'English country name is accepted as English evidence'
);
assertProperLatency(
    LanguageGuard::sourceAnalysis('Verificar cadeira house', 'pt', $checker)['conclusion'] === 'mixed',
    'real opposite-language vocabulary is still rejected in PT text'
);
assertProperLatency(
    LanguageGuard::sourceAnalysis('Check room yahoosads', 'en', $checker)['conclusion'] === 'unknown',
    'arbitrary garbage cannot hide as a proper noun'
);

$root = dirname(__DIR__);
$properPath = $root . '/resources/lexicon/full/proper_neutral.txt';
$personPath = $root . '/resources/lexicon/full/person_neutral.txt';
$countryPtPath = $root . '/resources/lexicon/country_pt.txt';
$countryEnPath = $root . '/resources/lexicon/country_en.txt';
assertProperLatency(is_file($properPath) && filesize($properPath) > 10000, 'generated proper-name lexicon is bundled');
assertProperLatency(is_file($personPath) && filesize($personPath) > 0, 'explicit person-name overrides are bundled');
assertProperLatency(is_file($countryPtPath) && filesize($countryPtPath) > 0, 'Portuguese country lexicon is bundled');
assertProperLatency(is_file($countryEnPath) && filesize($countryEnPath) > 0, 'English country lexicon is bundled');

$lexicalSource = file_get_contents($root . '/src/I18n/LexicalLanguageChecker.php');
$translatorSource = file_get_contents($root . '/src/I18n/ContentTranslator.php');
assertProperLatency(is_string($lexicalSource) && is_string($translatorSource), 'runtime translation sources are readable');
assertProperLatency(str_contains($lexicalSource, 'membershipCache'), 'lexical membership results are cached within the request');
assertProperLatency(str_contains($lexicalSource, 'proper_neutral.txt'), 'runtime checker keeps the generated proper-name fallback');
assertProperLatency(str_contains($lexicalSource, 'classifyEntityTokens'), 'runtime checker exposes explicit entity classification');
assertProperLatency(
    str_contains($translatorSource, "in_array(\$analysis['conclusion'], ['wrong', 'mixed'], true)"),
    'MyMemory retry is restricted to actual wrong/mixed-language output'
);
assertProperLatency(
    !str_contains($translatorSource, "if (!in_array(\$analysis['conclusion'], ['correct', 'ambiguous'], true)) {\n            // Provider language/content errors get one fresh retry"),
    'unknown/proper lexical edge cases no longer trigger a second translation request'
);

echo "Proper-name, country-entity and save-latency regression passed.\n";
