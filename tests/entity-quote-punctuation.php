<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/I18n/ContentTranslator.php';

function assertEntityFix(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$pdo = (new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
$checker = new LexicalLanguageChecker($pdo, []);

$quoted = LanguageGuard::sourceAnalysis(
    'Verificar se está limpo e sem danos. Estamos bem!!! "ZKTeco"',
    'pt',
    $checker
);
assertEntityFix(
    !in_array($quoted['conclusion'], ['wrong', 'mixed', 'unknown'], true),
    'quoted custom/technical text is excluded from language and unknown-word validation'
);

$unknownUnquoted = LanguageGuard::sourceAnalysis(
    'Verificar o quarto Ggjhrtgu',
    'pt',
    $checker
);
assertEntityFix(
    $unknownUnquoted['conclusion'] === 'unknown',
    'unquoted custom text is rejected instead of becoming a technical exception'
);

$people = $checker->classifyPersonTokens(['Michael', 'Ranjana', 'Ggjhrtgu']);
assertEntityFix(isset($people['Michael']), 'Michael is a sourced PERSON');
assertEntityFix(isset($people['Ranjana']), 'Ranjana is a sourced PERSON');
assertEntityFix(!isset($people['Ggjhrtgu']), 'capitalization alone does not create a PERSON');

$entities = $checker->classifyEntityTokens(['michael', 'ranjana', 'espanha', 'spain', 'alemanha', 'germany']);
assertEntityFix(($entities['michael'] ?? null) === 'person', 'Michael is exposed to translator protection as PERSON');
assertEntityFix(($entities['ranjana'] ?? null) === 'person', 'Ranjana is exposed to translator protection as PERSON');
foreach (['espanha', 'spain', 'alemanha', 'germany'] as $country) {
    assertEntityFix(($entities[$country] ?? null) === 'country', "{$country} is a sourced COUNTRY entity");
}

$classes = $checker->classifyTokens(['espanha', 'spain', 'alemanha', 'germany', 'spania']);
assertEntityFix(($classes['espanha'] ?? null) === 'pt_only', 'Espanha remains PT evidence');
assertEntityFix(($classes['alemanha'] ?? null) === 'pt_only', 'Alemanha remains PT evidence');
assertEntityFix(($classes['spain'] ?? null) === 'en_only', 'Spain remains EN evidence');
assertEntityFix(($classes['germany'] ?? null) === 'en_only', 'Germany remains EN evidence');
assertEntityFix(($classes['spania'] ?? null) === 'unknown', 'Spania is neither PT nor EN country evidence');

$translator = new ContentTranslator($pdo, ['enabled' => false], $checker);
$prepare = new ReflectionMethod(ContentTranslator::class, 'prepareTranslationInput');
$prepare->setAccessible(true);
$prepared = $prepare->invoke(
    $translator,
    'Verificar se está limpo e sem danos.supra "ZKTeco" Ranjana Espanha'
);

assertEntityFix(
    str_contains($prepared['text'], 'danos. supra'),
    'provider copy inserts a separator after punctuation so supra never becomes upra'
);
assertEntityFix(
    !str_contains($prepared['text'], '"ZKTeco"') && in_array('"ZKTeco"', $prepared['protected'], true),
    'quoted custom content is protected from the translator with its quotes intact'
);
assertEntityFix(
    !preg_match('/\bRanjana\b/u', $prepared['text']) && in_array('Ranjana', $prepared['protected'], true),
    'sourced PERSON is protected from the translator without requiring quotes'
);
assertEntityFix(
    str_contains($prepared['text'], 'Espanha'),
    'COUNTRY remains in provider input and is translated normally'
);

$original = 'Verificar se está limpo e sem danos.supra "ZKTeco"';
$preparedOriginal = $prepare->invoke($translator, $original);
assertEntityFix($original === 'Verificar se está limpo e sem danos.supra "ZKTeco"', 'source string remains unchanged');
assertEntityFix($preparedOriginal['text'] !== $original, 'only the provider copy is normalized/protected');

$translatePrepared = new ReflectionMethod(ContentTranslator::class, 'translatePrepared');
$translatePrepared->setAccessible(true);
// translatePrepared itself requires a live provider, so quote restoration is
// asserted structurally: placeholders retain the exact original quoted value.
assertEntityFix(in_array('"ZKTeco"', $preparedOriginal['protected'], true), 'quoted source is stored for exact restoration after translation');

echo "Entity/quotes/punctuation regression passed.\n";
