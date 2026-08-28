<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/I18n/SiteTranslations.php';
require_once dirname(__DIR__) . '/src/I18n/ContentTranslator.php';

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

// Language validation is now sentence-level and conservative. Technical or
// short ambiguous wording must be allowed to reach contextual translation.
assertI18n(LanguageGuard::confidentSentenceLanguage('Verificação da cozinha e das janelas') === 'pt', 'Portuguese sentence is detected with confidence');
assertI18n(LanguageGuard::confidentSentenceLanguage('Check the kitchen windows and curtains') === 'en', 'English sentence is detected with confidence');
assertI18n(LanguageGuard::confidentSentenceLanguage('WiFi HVAC') === null, 'short technical text is deliberately ambiguous');
LanguageGuard::assertExpectedLanguage('Check the fire extinguisher detector and HVAC thermostat.', 'en');
LanguageGuard::assertExpectedLanguage('fire extinguisher', 'en');
assertI18n(true, 'technical English vocabulary is not rejected word by word');
assertThrowsI18n(
    static fn() => LanguageGuard::assertExpectedLanguage('Verificar a limpeza da cozinha e das janelas.', 'en'),
    'clearly PT',
    'clear Portuguese sentence is blocked in English input mode'
);
assertThrowsI18n(
    static fn() => LanguageGuard::assertExpectedLanguage('Check the kitchen windows and curtains.', 'pt'),
    'claramente EN',
    'clear English sentence is blocked in Portuguese input mode'
);

// Existing bilingual values remain reusable without provider access. Extra
// validation metadata must not alter the persisted PT/EN pair.
$contentTranslatorWithoutProvider = (new ReflectionClass(ContentTranslator::class))->newInstanceWithoutConstructor();
$reusedEn = $contentTranslatorWithoutProvider->versions('Kitchen Check', 'en', 'Verificação da cozinha', 'Kitchen Check');
assertI18n(($reusedEn['pt'] ?? null) === 'Verificação da cozinha' && ($reusedEn['en'] ?? null) === 'Kitchen Check', 'unchanged English value reuses existing bilingual pair');
$reusedPt = $contentTranslatorWithoutProvider->versions('Verificação da cozinha', 'pt', 'Verificação da cozinha', 'Kitchen Check');
assertI18n(($reusedPt['pt'] ?? null) === 'Verificação da cozinha' && ($reusedPt['en'] ?? null) === 'Kitchen Check', 'unchanged Portuguese value reuses existing bilingual pair');

$contentTranslatorSource = file_get_contents(dirname(__DIR__) . '/src/I18n/ContentTranslator.php');
assertI18n(is_string($contentTranslatorSource), 'content translator source is readable');
$guardPosition = strpos((string) $contentTranslatorSource, 'LanguageGuard::assertExpectedLanguage($text, $sourceLanguage);');
$reusePosition = strpos((string) $contentTranslatorSource, '$existingEn === $text');
assertI18n($guardPosition !== false && $reusePosition !== false && $reusePosition < $guardPosition, 'unchanged-value reuse happens before source-language check');
assertI18n(!str_contains((string) $contentTranslatorSource, '$strongPortuguese') && !str_contains((string) $contentTranslatorSource, '$strongEnglish'), 'manual PT/EN vocabulary lists are removed');
assertI18n(str_contains((string) $contentTranslatorSource, 'targetConclusion'), 'translated phrase receives correct/ambiguous/wrong conclusion');

assertI18n(ContentTranslator::targetConclusion('Confirm that the windows are clean and securely fitted.', 'en') === 'correct', 'clear English provider output is correct for EN target');
assertI18n(ContentTranslator::targetConclusion('Confirmar que as janelas estão limpas e bem fixas.', 'pt') === 'correct', 'clear Portuguese provider output is correct for PT target');
assertI18n(ContentTranslator::targetConclusion('WiFi HVAC', 'en') === 'ambiguous', 'short technical provider output is accepted as ambiguous');
assertI18n(ContentTranslator::isPlausibleTargetText('WiFi HVAC', 'en'), 'ambiguous technical translation remains plausible');
assertI18n(!ContentTranslator::isPlausibleTargetText('Verificar a limpeza da cozinha e das janelas.', 'en'), 'clearly Portuguese output is rejected for EN target');
assertI18n(!ContentTranslator::isPlausibleTargetText('Check the kitchen windows and curtains.', 'pt'), 'clearly English output is rejected for PT target');

SiteTranslations::boot();
$json = '{"label":"Controlo My2N"}';
assertI18n(Translator::translateOutput($json) === $json, 'JSON/API payloads are never globally translated');
$html = '<html><body><span>Controlo My2N</span><code>device_id=123</code><div data-i18n-skip>rooms</div></body></html>';
$translatedOutput = Translator::translateOutput($html);
assertI18n(str_contains($translatedOutput, 'My2N Control'), 'HTML output receives shared English catalogue');
assertI18n(str_contains($translatedOutput, 'MutationObserver'), 'dynamic DOM translation remains enabled');
assertI18n(str_contains($translatedOutput, "closest('[data-i18n-skip]')"), 'technical DOM subtrees can opt out of translation');

$validatorSource = file_get_contents(dirname(__DIR__) . '/translation-validate.php');
assertI18n(is_string($validatorSource) && str_contains($validatorSource, '->versions('), 'validation-only endpoint reuses ContentTranslator/cache/provider algorithm');

echo 'Site-wide contextual i18n tests passed.' . PHP_EOL;
