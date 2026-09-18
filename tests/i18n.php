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

$root = dirname(__DIR__);
$catalog = SiteTranslations::catalog();
assertI18n(($catalog['Controlo My2N'] ?? null) === 'My2N Control', 'static UI keeps the shared PT/EN catalogue');

$_SESSION = [];
Translator::setLocale('pt', false);
assertI18n(Translator::localized('Verificação da cozinha', 'Kitchen Check') === 'Verificação da cozinha', 'PT locale reads the PT column');
Translator::setLocale('en', false);
assertI18n(Translator::localized('Verificação da cozinha', 'Kitchen Check') === 'Kitchen Check', 'EN locale reads the EN column');

$pdo = (new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
$translator = new ContentTranslator($pdo, ['enabled' => false]);
$reusedEn = $translator->versions('Kitchen Check', 'en', 'Verificação da cozinha', 'Kitchen Check');
assertI18n($reusedEn['pt'] === 'Verificação da cozinha' && $reusedEn['en'] === 'Kitchen Check', 'unchanged EN value reuses the persisted pair');
assertI18n($reusedEn['status'] === 'reused', 'unchanged pair reports reuse');
$reusedPt = $translator->versions('Verificação da cozinha', 'pt', 'Verificação da cozinha', 'Kitchen Check');
assertI18n($reusedPt['status'] === 'reused', 'unchanged PT value reuses the persisted pair');
assertI18n($translator->versions('', 'pt')['status'] === 'empty', 'empty text does not call the provider');

$source = file_get_contents($root . '/src/I18n/ContentTranslator.php');
$config = file_get_contents($root . '/config.php');
$api = file_get_contents($root . '/api.php');
assertI18n(is_string($source) && is_string($config) && is_string($api), 'translation sources are readable');
assertI18n(str_contains($source, 'translation.googleapis.com/language/translate/v2'), 'Google Basic v2 endpoint is used');
assertI18n(str_contains($source, "CURLOPT_POST => true") && str_contains($source, "'format' => 'text'"), 'Google request uses JSON POST text mode');
assertI18n(str_contains($source, "'model' => 'nmt'"), 'Google request pins the standard NMT model used by the cache namespace');
assertI18n(str_contains($source, "return \$language === 'pt' ? 'pt-PT' : 'en';"), 'provider receives explicit pt-PT and en language codes');
assertI18n(str_contains($source, "\$this->config['secrets_file']"), 'Google key can be loaded from a private server-side JSON file');
assertI18n(str_contains($source, 'engine_key') && str_contains($source, 'google-basic-nmt-v2'), 'translation cache is namespaced by engine');
assertI18n(!str_contains($source, 'LanguageGuard') && !str_contains($source, 'LexicalLanguage'), 'content translation has no local linguistic gate');
assertI18n(!str_contains($source, 'MyMemory') && !str_contains($source, 'langpair'), 'MyMemory-specific processing is removed');
assertI18n(!str_contains($api, 'validate_bilingual_texts') && !str_contains($api, 'invalidWords'), 'API has no language-validation action or invalid-word response');
assertI18n(!file_exists($root . '/translation-validate.php'), 'validation-only translation endpoint is removed');
assertI18n(!file_exists($root . '/src/I18n/LanguageGuard.php'), 'local LanguageGuard is removed');
assertI18n(!file_exists($root . '/src/I18n/LexicalLanguageChecker.php'), 'local lexical checker is removed');

SiteTranslations::boot();
$json = '{"label":"Controlo My2N"}';
assertI18n(Translator::translateOutput($json) === $json, 'JSON/API payloads are never globally translated');
$html = '<html><body><span>Controlo My2N</span><code>device_id=123</code></body></html>';
assertI18n(str_contains(Translator::translateOutput($html), 'My2N Control'), 'static HTML translation remains available');

echo "Google translation architecture tests passed.\n";
