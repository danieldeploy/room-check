<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/I18n/ContentTranslator.php';

function assertProtectedTranslation(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$pdo = (new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
$translator = new ContentTranslator($pdo, ['enabled' => false]);
$prepare = new ReflectionMethod(ContentTranslator::class, 'prepareTranslationInput');
$prepare->setAccessible(true);

$original = "Verifique \"ZKTeco\" no quarto 12 às 08:30.\nSubstitua-os na linha 2.";
$prepared = $prepare->invoke($translator, $original);
assertProtectedTranslation($original === "Verifique \"ZKTeco\" no quarto 12 às 08:30.\nSubstitua-os na linha 2.", 'source text is not modified');
foreach (['"ZKTeco"', '12', '08:30', '2'] as $protected) {
    assertProtectedTranslation(in_array($protected, $prepared['protected'], true), "{$protected} is protected exactly");
}
assertProtectedTranslation(str_contains($prepared['text'], "\n"), 'provider copy retains the source line break');
assertProtectedTranslation(str_contains($prepared['text'], 'Substitua-os'), 'ordinary hyphenated words are sent to Google without lexical classification');

$preserves = new ReflectionMethod(ContentTranslator::class, 'preservesProtectedSource');
$preserves->setAccessible(true);
assertProtectedTranslation(
    $preserves->invoke($translator, $original, "Check \"ZKTeco\" in room 12 at 08:30.\nReplace them on line 2."),
    'cache integrity accepts exact quoted and numeric content'
);
assertProtectedTranslation(
    !$preserves->invoke($translator, $original, "Check ZKTeco in room 13 at 08:30.\nReplace them on line 2."),
    'cache integrity rejects changed protected content'
);

echo "Protected translation content tests passed.\n";
