<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/I18n/SiteTranslations.php';
require_once dirname(__DIR__) . '/src/I18n/ContentTranslator.php';
require_once __DIR__ . '/support/FakeLexicalLanguageClassifier.php';

function assertI18n(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

function assertThrowsI18n(callable $callback, string $expectedFragment, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        assertI18n(str_contains($exception->getMessage(), $expectedFragment), $message);
        return;
    }
    throw new RuntimeException('FAIL: ' . $message);
}

$catalog = SiteTranslations::catalog();
assertI18n(($catalog['Controlo My2N'] ?? null) === 'My2N Control', 'My2N/Bell UI is in the shared catalogue');
assertI18n(($catalog['Estado da integração'] ?? null) === 'Integration status', 'ZKAccess UI is in the shared catalogue');
assertI18n(($catalog['Novo utilizador'] ?? null) === 'New user', 'Users UI is in the shared catalogue');
assertI18n(($catalog['Permissões dos perfis'] ?? null) === 'Role permissions', 'Permissions UI is in the shared catalogue');

$_SESSION = [];
Translator::setLocale('pt', false);
assertI18n(SiteTranslations::text('Guardar exemplo', 'Save example') === 'Guardar exemplo', 'static helper returns Portuguese in PT locale');
assertI18n(Translator::localized('Verificação da cozinha', 'Kitchen Check') === 'Verificação da cozinha', 'bilingual value uses Portuguese column in PT locale');
Translator::setLocale('en', false);
assertI18n(SiteTranslations::text('Guardar exemplo 2', 'Save example 2') === 'Save example 2', 'static helper returns English in EN locale');
assertI18n(Translator::localized('Verificação da cozinha', 'Kitchen Check') === 'Kitchen Check', 'bilingual value uses English column in EN locale');

$itemListsSource = file_get_contents(dirname(__DIR__) . '/item-lists.php');
assertI18n(is_string($itemListsSource), 'item-list editor source is readable');
assertI18n(str_contains((string) $itemListsSource, "\$selectedList['displayName'] = Translator::localized("), 'list editor derives editable list name from both saved languages');

$lexicon = translationRegressionClassifier();
$ptAnalysis = LanguageGuard::sourceAnalysis('Verificar a limpeza da cozinha e das janelas', 'pt', $lexicon);
$enAnalysis = LanguageGuard::sourceAnalysis('Check the kitchen windows and curtains', 'en', $lexicon);
assertI18n($ptAnalysis['conclusion'] === 'correct', 'Portuguese source is confirmed lexically');
assertI18n($enAnalysis['conclusion'] === 'correct', 'English source is confirmed lexically');
assertI18n(LanguageGuard::sourceAnalysis('casa grande', 'pt', $lexicon)['conclusion'] === 'correct', 'short Portuguese source is lexically confirmed');
assertI18n(LanguageGuard::sourceAnalysis('new house', 'pt', $lexicon)['conclusion'] === 'wrong', 'short English source is rejected in PT mode');
assertI18n(LanguageGuard::sourceAnalysis('extinguisher', 'en', $lexicon)['conclusion'] === 'correct', 'extinguisher is positively identified as English');
assertI18n(LanguageGuard::sourceAnalysis('detector', 'pt', $lexicon)['conclusion'] === 'ambiguous', 'shared PT/EN word stays ambiguous');
assertI18n(LanguageGuard::sourceAnalysis('HVAC', 'pt', $lexicon)['conclusion'] === 'ambiguous', 'technical identifier stays neutral');
assertI18n(LanguageGuard::sourceAnalysis('Verificar se está limpo house', 'pt', $lexicon)['conclusion'] === 'mixed', 'one EN word makes a PT sentence mixed');
assertI18n(LanguageGuard::sourceAnalysis('Verificar se está limpo qdsffasdfaasdf', 'pt', $lexicon)['conclusion'] === 'unknown', 'unknown ordinary word is not hidden by PT sentence context');

assertThrowsI18n(
    static fn() => LanguageGuard::assertExpectedLanguage('Verificar a limpeza da cozinha e das janelas', 'en', translationRegressionClassifier()),
    'clearly PT',
    'Portuguese source is blocked in English input mode'
);
assertThrowsI18n(
    static fn() => LanguageGuard::assertExpectedLanguage('Check the kitchen windows and curtains', 'pt', translationRegressionClassifier()),
    'claramente EN',
    'English source is blocked in Portuguese input mode'
);
assertThrowsI18n(
    static fn() => LanguageGuard::assertExpectedLanguage('Verificar se está limpo house', 'pt', translationRegressionClassifier()),
    'mistura PT e EN',
    'mixed text is blocked before a translator could normalize it'
);

$contentTranslatorWithoutProvider = (new ReflectionClass(ContentTranslator::class))->newInstanceWithoutConstructor();
$reusedEn = $contentTranslatorWithoutProvider->versions('Kitchen Check', 'en', 'Verificação da cozinha', 'Kitchen Check');
assertI18n(($reusedEn['pt'] ?? null) === 'Verificação da cozinha' && ($reusedEn['en'] ?? null) === 'Kitchen Check', 'unchanged English value reuses existing bilingual pair');
assertI18n(($reusedEn['sourceConclusion'] ?? null) === 'reused', 'reused value reports reuse instead of pretending a fresh validation');
$reusedPt = $contentTranslatorWithoutProvider->versions('Verificação da cozinha', 'pt', 'Verificação da cozinha', 'Kitchen Check');
assertI18n(($reusedPt['pt'] ?? null) === 'Verificação da cozinha' && ($reusedPt['en'] ?? null) === 'Kitchen Check', 'unchanged Portuguese value reuses existing bilingual pair');

$contentTranslatorSource = file_get_contents(dirname(__DIR__) . '/src/I18n/ContentTranslator.php');
$guardSource = file_get_contents(dirname(__DIR__) . '/src/I18n/LanguageGuard.php');
$lexicalSource = file_get_contents(dirname(__DIR__) . '/src/I18n/LexicalLanguageChecker.php');
assertI18n(is_string($contentTranslatorSource) && is_string($guardSource) && is_string($lexicalSource), 'translation architecture sources are readable');
$sourceValidationPosition = strpos((string) $contentTranslatorSource, '$sourceAnalysis = $this->validateSource');
$translationPosition = strpos((string) $contentTranslatorSource, '$translated = $this->translateValidated');
$reusePosition = strpos((string) $contentTranslatorSource, '$existingEn === $text');
assertI18n($reusePosition !== false && $sourceValidationPosition !== false && $reusePosition < $sourceValidationPosition, 'unchanged pair reuse happens before lexical verification');
assertI18n($sourceValidationPosition !== false && $translationPosition !== false && $sourceValidationPosition < $translationPosition, 'source is verified before MyMemory translation');
assertI18n(str_contains((string) $contentTranslatorSource, "'langpair' => \$source . '|' . \$target"), 'MyMemory receives explicit source/target only after source verification');
assertI18n(!str_contains((string) $guardSource, 'SHORT_MIN_') && !str_contains((string) $guardSource, 'MIXED_MIN_'), 'obsolete short/mixed statistical thresholds are removed');
assertI18n(str_contains((string) $guardSource, 'TECHNICAL_NEUTRAL'), 'technical neutrality is explicit and separate from language vocabulary');
assertI18n(
    str_contains((string) $lexicalSource, 'LocalHunspellLexicon')
        && !str_contains((string) $lexicalSource, 'w/api.php')
        && !str_contains((string) $lexicalSource, 'fetchBatch(')
        && !str_contains((string) $lexicalSource, 'lexical_language_cache')
        && !str_contains((string) $lexicalSource, 'curl_init'),
    'lexical verification is local and has no runtime network/database dependency'
);

assertI18n(ContentTranslator::targetConclusion('Check the kitchen windows and curtains', 'en', $lexicon) === 'correct', 'clear English translation is correct for EN target');
assertI18n(ContentTranslator::targetConclusion('Verificar a limpeza da cozinha e das janelas', 'pt', $lexicon) === 'correct', 'clear Portuguese translation is correct for PT target');
assertI18n(ContentTranslator::targetConclusion('casa grande', 'en', $lexicon) === 'wrong', 'Portuguese translation is wrong for EN target');
assertI18n(ContentTranslator::targetConclusion('new house', 'pt', $lexicon) === 'wrong', 'English translation is wrong for PT target');
assertI18n(ContentTranslator::targetConclusion('detector', 'en', $lexicon) === 'ambiguous', 'shared translated word is accepted as ambiguous');
assertI18n(ContentTranslator::targetConclusion('HVAC', 'pt', $lexicon) === 'ambiguous', 'technical translated identifier is accepted as ambiguous');
assertI18n(ContentTranslator::targetConclusion('qdsffasdfaasdf', 'en', $lexicon) === 'wrong', 'unknown ordinary translation is not accepted');

$ptGood = ContentTranslator::successMessage('correct', 'correct', 'pt');
$ptTechnical = ContentTranslator::successMessage('ambiguous', 'correct', 'pt');
$enTargetAmbiguous = ContentTranslator::successMessage('correct', 'ambiguous', 'en');
assertI18n(str_contains($ptGood, 'texto PT confirmado') && str_contains($ptGood, 'tradução EN confirmada'), 'green PT message states confirmed source and translation');
assertI18n(str_contains($ptTechnical, 'termo técnico/partilhado aceite') && str_contains($ptTechnical, 'tradução EN confirmada'), 'green PT message accurately reports neutral technical/shared source');
assertI18n(str_contains($enTargetAmbiguous, 'EN text confirmed') && str_contains($enTargetAmbiguous, 'ambiguous/technical PT translation accepted'), 'green EN message accurately reports ambiguous target');

SiteTranslations::boot();
$json = '{"label":"Controlo My2N"}';
assertI18n(Translator::translateOutput($json) === $json, 'JSON/API payloads are never globally translated');
$html = '<html><body><span>Controlo My2N</span><code>device_id=123</code><div data-i18n-skip>rooms</div></body></html>';
$translatedOutput = Translator::translateOutput($html);
assertI18n(str_contains($translatedOutput, 'My2N Control'), 'HTML output receives shared English catalogue');
assertI18n(str_contains($translatedOutput, 'MutationObserver'), 'dynamic DOM translation remains enabled');
assertI18n(str_contains($translatedOutput, "closest('[data-i18n-skip]')"), 'technical DOM subtrees can opt out of translation');

$validatorSource = file_get_contents(dirname(__DIR__) . '/translation-validate.php');
assertI18n(is_string($validatorSource) && str_contains($validatorSource, 'sourceConclusion') && str_contains($validatorSource, 'translationConclusion') && str_contains($validatorSource, "'message'"), 'validation-only endpoint still reports exact conclusions for non-persisted text');

echo 'Site-wide lexical translation tests passed.' . PHP_EOL;
