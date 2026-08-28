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
    'Verificar se está limpo e sem danos. Estamos bem!!! "new"',
    'pt',
    $checker
);
assertEntityFix(
    !in_array($quoted['conclusion'], ['wrong', 'mixed', 'unknown'], true),
    'quoted English word is excluded from PT/EN and unknown-word validation'
);

$unquoted = LanguageGuard::sourceAnalysis(
    'Verificar se está limpo e sem danos. Estamos bem!!! new',
    'pt',
    $checker
);
assertEntityFix(
    in_array($unquoted['conclusion'], ['wrong', 'mixed'], true)
        && in_array('new', array_map('mb_strtolower', $unquoted['oppositeWords']), true),
    'the same unquoted English word is still rejected'
);

$entities = $checker->classifyEntityTokens(['romi', 'espanha', 'spain', 'alemanha', 'germany']);
assertEntityFix(($entities['romi'] ?? null) === 'person', 'Romi is an explicit PERSON entity');
foreach (['espanha', 'spain', 'alemanha', 'germany'] as $country) {
    assertEntityFix(($entities[$country] ?? null) === 'country', "{$country} is an explicit COUNTRY entity");
}

$classes = $checker->classifyTokens(['romi', 'espanha', 'spain', 'alemanha', 'germany']);
assertEntityFix(($classes['romi'] ?? null) === 'shared', 'PERSON is language-neutral');
assertEntityFix(($classes['espanha'] ?? null) === 'pt_only', 'Portuguese country name remains PT evidence');
assertEntityFix(($classes['alemanha'] ?? null) === 'pt_only', 'Alemanha remains PT evidence');
assertEntityFix(($classes['spain'] ?? null) === 'en_only', 'English country name remains EN evidence');
assertEntityFix(($classes['germany'] ?? null) === 'en_only', 'Germany remains EN evidence');

$translator = new ContentTranslator($pdo, ['enabled' => false], $checker);
$prepare = new ReflectionMethod(ContentTranslator::class, 'prepareTranslationInput');
$prepare->setAccessible(true);
$prepared = $prepare->invoke(
    $translator,
    'Verificar se está limpo e sem danos.supra "new" Romi Espanha'
);

assertEntityFix(
    str_contains($prepared['text'], 'danos. supra'),
    'provider copy inserts a separator after punctuation so supra never becomes upra'
);
assertEntityFix(
    !str_contains($prepared['text'], '"new"') && in_array('"new"', $prepared['protected'], true),
    'quoted content is protected from the translator'
);
assertEntityFix(
    !preg_match('/\bRomi\b/u', $prepared['text']) && in_array('Romi', $prepared['protected'], true),
    'PERSON is protected from the translator'
);
assertEntityFix(
    str_contains($prepared['text'], 'Espanha'),
    'COUNTRY remains in provider input and is therefore translated normally'
);

// Preparation changes only the provider copy. versions() still receives and
// persists the original source text unchanged.
$original = 'Verificar se está limpo e sem danos.supra';
$preparedOriginal = $prepare->invoke($translator, $original);
assertEntityFix($original === 'Verificar se está limpo e sem danos.supra', 'source string remains unchanged');
assertEntityFix($preparedOriginal['text'] !== $original, 'only the provider copy is normalized');

echo "Entity/quotes/punctuation regression passed.\n";
