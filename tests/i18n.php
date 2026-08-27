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
assertI18n(($catalog['Configuração inicial'] ?? null) === 'Initial setup', 'Setup UI is in the shared catalogue');
assertI18n(
    ($catalog['O item {value} já está atribuído ou concluído noutra data deste intervalo.'] ?? null)
        === 'The item {value} is already assigned or completed on another date in this period.',
    'audited dynamic template is in the shared catalogue'
);
assertI18n(
    ($catalog['Credenciais My2N não configuradas no servidor.'] ?? null)
        === 'My2N credentials are not configured on the server.',
    'provider errors that can reach the UI are covered'
);

$_SESSION = [];
Translator::setLocale('pt', false);
assertI18n(SiteTranslations::text('Guardar exemplo', 'Save example') === 'Guardar exemplo', 'static helper returns Portuguese in PT locale');
assertI18n(
    Translator::localized('Verificação da cozinha', 'Kitchen Check') === 'Verificação da cozinha',
    'user-authored bilingual value uses Portuguese column in PT locale'
);
Translator::setLocale('en', false);
assertI18n(SiteTranslations::text('Guardar exemplo 2', 'Save example 2') === 'Save example 2', 'static helper returns English in EN locale');
assertI18n(
    Translator::localized('Verificação da cozinha', 'Kitchen Check') === 'Kitchen Check',
    'user-authored bilingual value uses English column in EN locale'
);
assertI18n(
    SiteTranslations::format('Foram guardados {count} registos.', '{count} records were saved.', ['{count}' => '3']) === '3 records were saved.',
    'formatted static text follows active locale'
);

$itemListsSource = file_get_contents(dirname(__DIR__) . '/item-lists.php');
assertI18n(is_string($itemListsSource), 'item-list editor source is readable');
assertI18n(
    str_contains((string) $itemListsSource, "\$selectedList['displayName'] = Translator::localized("),
    'list editor derives the editable list name from both saved languages'
);
assertI18n(
    str_contains((string) $itemListsSource, "value=\"<?= listEscape((string) \$selectedList['displayName']) ?>\""),
    'list editor renders the active-language value instead of the canonical PT value'
);
assertI18n(
    !str_contains((string) $itemListsSource, "value=\"<?= listEscape(\$selectedList['name']) ?>\""),
    'list editor cannot fall back to the old PT-only editable value path'
);

// User-authored source-language guard: one server-side algorithm for every
// current/future bilingual save that passes through ContentTranslator::versions().
assertI18n(LanguageGuard::confidentLanguage('Verificação da cozinha') === 'pt', 'Portuguese user text is detected with high confidence');
assertI18n(LanguageGuard::confidentLanguage('Kitchen inspection') === 'en', 'English user text is detected with high confidence');
assertI18n(LanguageGuard::confidentLanguage('Limpeza') === 'pt', 'short Portuguese domain text can be detected');
assertI18n(LanguageGuard::confidentLanguage('Kitchen') === 'en', 'short English domain text can be detected');
assertI18n(LanguageGuard::confidentLanguage('WiFi Café') === null, 'neutral technical/loan words remain ambiguous and are allowed');
assertI18n(LanguageGuard::confidentLanguage('Café Central') === null, 'proper-name-like neutral text is not blocked');
LanguageGuard::assertExpectedLanguage('Check kitchen cleanliness', 'en');
LanguageGuard::assertExpectedLanguage('Verificar a limpeza da cozinha', 'pt');
assertI18n(true, 'matching user language is accepted');
assertThrowsI18n(
    static fn() => LanguageGuard::assertExpectedLanguage('Verificar a limpeza da cozinha', 'en'),
    'written in Portuguese',
    'Portuguese text is blocked when the active input language is English'
);
assertThrowsI18n(
    static fn() => LanguageGuard::assertExpectedLanguage('Check kitchen cleanliness', 'pt'),
    'escrito em inglês',
    'English text is blocked when the active input language is Portuguese'
);
$contentTranslatorSource = file_get_contents(dirname(__DIR__) . '/src/I18n/ContentTranslator.php');
assertI18n(
    is_string($contentTranslatorSource)
        && str_contains($contentTranslatorSource, 'LanguageGuard::assertExpectedLanguage($text, $sourceLanguage);'),
    'all bilingual versions() saves pass through the central language guard before translation'
);

SiteTranslations::boot();
$json = '{"label":"Controlo My2N"}';
assertI18n(Translator::translateOutput($json) === $json, 'JSON/API payloads are never globally translated');
$html = '<html><body><span>Controlo My2N</span><code>device_id=123</code><div data-i18n-skip>rooms</div></body></html>';
$translatedOutput = Translator::translateOutput($html);
assertI18n(str_contains($translatedOutput, 'My2N Control'), 'HTML output receives the shared English catalogue');
assertI18n(str_contains($translatedOutput, 'MutationObserver'), 'dynamic DOM translation remains enabled for JS-rendered UI');
assertI18n(str_contains($translatedOutput, 'templatePatterns'), 'runtime supports audited {value} templates');
assertI18n(str_contains($translatedOutput, "closest('[data-i18n-skip]')"), 'technical DOM subtrees can opt out of translation');
assertI18n(str_contains($translatedOutput, "'CODE', 'PRE', 'KBD', 'SAMP'"), 'technical markup tags are excluded from translation');
assertI18n(!str_contains($translatedOutput, 'input[name="name"]'), 'user-authored input values are not DOM-translated');

// Regression coverage for the production hybrid-translation failure mode.
assertI18n(
    ContentTranslator::isPlausibleTargetText('Confirm that they are clean and securely fitted.', 'en'),
    'valid English translation passes the provider quality guard'
);
assertI18n(
    !ContentTranslator::isPlausibleTargetText('Confirm que estão limpas e bem fixas.', 'en'),
    'hybrid Portuguese/English output is rejected for an English target'
);
assertI18n(
    !ContentTranslator::isPlausibleTargetText('Confirm que todas as lâmpadas acendem.', 'en'),
    'partially translated lamp instruction is rejected for an English target'
);
assertI18n(
    ContentTranslator::isPlausibleTargetText('Café', 'en'),
    'language-neutral loan words are not rejected merely because they contain an accent'
);
assertI18n(
    ContentTranslator::isPlausibleTargetText('WiFi', 'en'),
    'language-neutral technical terms remain valid in English'
);
assertI18n(
    ContentTranslator::isPlausibleTargetText('Confirmar que estão limpas e bem fixas.', 'pt'),
    'valid Portuguese translation passes the provider quality guard'
);
assertI18n(
    !ContentTranslator::isPlausibleTargetText('Confirm that it is clean and in good condition.', 'pt'),
    'obvious English provider output is rejected for a Portuguese target'
);

echo 'Site-wide i18n tests passed.' . PHP_EOL;
