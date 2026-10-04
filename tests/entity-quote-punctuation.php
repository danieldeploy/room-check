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

$original = "Verifique \"ZKTeco\", “My2N”, «SIP», „API“, ‘PIN’, 'RFID' e `Cloudbeds` em https://check.welcomehostel.pt para info@welcomehostel.pt usando {{room_id}} no quarto 12 às 08:30.\nSubstitua-os na linha 2.";
$prepared = $prepare->invoke($translator, $original);
assertProtectedTranslation(
    $original === "Verifique \"ZKTeco\", “My2N”, «SIP», „API“, ‘PIN’, 'RFID' e `Cloudbeds` em https://check.welcomehostel.pt para info@welcomehostel.pt usando {{room_id}} no quarto 12 às 08:30.\nSubstitua-os na linha 2.",
    'source text is not modified'
);
foreach (['"ZKTeco"', '“My2N”', '«SIP»', '„API“', '‘PIN’', "'RFID'", '`Cloudbeds`', 'https://check.welcomehostel.pt', 'info@welcomehostel.pt', '{{room_id}}', '12', '08:30', '2'] as $protected) {
    assertProtectedTranslation(in_array($protected, $prepared['protected'], true), "{$protected} is protected exactly");
}
assertProtectedTranslation(str_contains($prepared['text'], "\n"), 'provider copy retains the source line break');
assertProtectedTranslation(str_contains($prepared['text'], 'Substitua-os'), 'ordinary hyphenated words are sent to Google without lexical classification');
assertProtectedTranslation(
    TranslationProtection::fragments("Don't change the guest's room") === [],
    'English apostrophes are not mistaken for quoted technical literals'
);

$collision = TranslationProtection::prepare('RoomCheckKeepALiteralToken e "SIP"');
assertProtectedTranslation(
    !isset($collision['protected']['RoomCheckKeepALiteralToken']),
    'generated provider markers cannot collide with source text'
);
$oneMarker = array_key_first($collision['protected']);
assertProtectedTranslation(
    is_string($oneMarker)
        && TranslationProtection::restore($oneMarker . ' ' . $oneMarker, $collision['protected']) === null,
    'duplicated provider markers are rejected'
);
assertProtectedTranslation(
    is_string($oneMarker) && TranslationProtection::restore('', $collision['protected']) === null,
    'missing provider markers are rejected'
);

$preserves = new ReflectionMethod(ContentTranslator::class, 'preservesProtectedSource');
$preserves->setAccessible(true);
assertProtectedTranslation(
    $preserves->invoke(
        $translator,
        $original,
        "Check \"ZKTeco\", “My2N”, «SIP», „API“, ‘PIN’, 'RFID' and `Cloudbeds` at https://check.welcomehostel.pt for info@welcomehostel.pt using {{room_id}} in room 12 at 08:30.\nReplace them on line 2."
    ),
    'cache integrity accepts exact quoted and numeric content'
);
assertProtectedTranslation(
    !$preserves->invoke($translator, $original, "Check ZKTeco in room 13 at 08:30.\nReplace them on line 2."),
    'cache integrity rejects changed protected content'
);
assertProtectedTranslation(
    !TranslationProtection::preserves('Use "SIP" twice: "SIP".', 'Use "SIP" once.'),
    'cache integrity compares repeated protected literal counts'
);

$translatorSource = file_get_contents(dirname(__DIR__) . '/src/I18n/Translator.php');
assertProtectedTranslation(
    is_string($translatorSource)
        && str_contains($translatorSource, 'translateOutsideTechnicalLiterals')
        && str_contains($translatorSource, 'TranslationProtection::literalPatternBody(false)'),
    'DOM text, attributes, alerts and confirmations use the shared technical-literal rule'
);

echo "Protected translation content tests passed.\n";
