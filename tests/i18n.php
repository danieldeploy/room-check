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
$unprotectedCatalogEntries = [];
$missingEnglishEntries = [];
$unexpectedIdenticalEntries = [];
$intentionalIdenticalEntries = array_fill_keys(['Google Authenticator (TOTP)', 'TOConline', 'Site'], true);
foreach ($catalog as $portuguese => $english) {
    if (trim((string) $english) === '') {
        $missingEnglishEntries[] = (string) $portuguese;
    }
    if ((string) $portuguese === (string) $english && !isset($intentionalIdenticalEntries[(string) $portuguese])) {
        $unexpectedIdenticalEntries[] = (string) $portuguese;
    }
    if (!TranslationProtection::preserves((string) $portuguese, (string) $english, false)) {
        $unprotectedCatalogEntries[] = (string) $portuguese;
    }
}
assertI18n($missingEnglishEntries === [], 'static catalogue has no missing English translation');
assertI18n(
    $unexpectedIdenticalEntries === [],
    'only language-neutral technical labels are intentionally identical in PT and EN'
);
assertI18n(
    $unprotectedCatalogEntries === [],
    'static catalogue preserves all technical literals'
        . ($unprotectedCatalogEntries === [] ? '' : ': ' . implode(' | ', $unprotectedCatalogEntries))
);

$_SESSION = [];
Translator::setLocale('pt', false);
assertI18n(Translator::localized('Verificação da cozinha', 'Kitchen Check') === 'Verificação da cozinha', 'PT locale reads the PT column');
Translator::setLocale('en', false);
assertI18n(Translator::localized('Verificação da cozinha', 'Kitchen Check') === 'Kitchen Check', 'EN locale reads the EN column');
assertI18n(
    SiteTranslations::localizeMessage('Método não permitido.') === 'Method not allowed.',
    'JSON/server messages use the shared catalogue in EN'
);
assertI18n(
    SiteTranslations::localizeMessage(
        'O intervalo tem itens atribuídos entre 2026-09-20 e 2026-09-22. As novas datas têm de incluir todo esse período.'
    ) === 'The period has items assigned between 2026-09-20 and 2026-09-22. The new dates must include that entire range.',
    'JSON/server messages preserve values in catalogue templates'
);
assertI18n(
    SiteTranslations::localizeMessage('Quer apagar a área “My2N”?') === 'Do you want to delete the “My2N” area?',
    'API templates preserve quoted technical values'
);
assertI18n(
    SiteTranslations::localizeMessage('provider_error: quota') === 'provider_error: quota',
    'unknown provider diagnostics remain literal'
);
$localizedPayload = SiteTranslations::localizePayload([
    'ok' => false,
    'error' => 'Método não permitido.',
    'data' => ['name' => 'Quarto', 'status' => 'pending'],
]);
assertI18n(
    $localizedPayload['error'] === 'Method not allowed.'
        && $localizedPayload['data']['name'] === 'Quarto'
        && $localizedPayload['data']['status'] === 'pending',
    'only human-readable JSON message fields are localized'
);

$pdo = (new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
$translator = new ContentTranslator($pdo, ['enabled' => false]);
$reusedEn = $translator->versions('Kitchen Check', 'en', 'Verificação da cozinha', 'Kitchen Check');
assertI18n($reusedEn['pt'] === 'Verificação da cozinha' && $reusedEn['en'] === 'Kitchen Check', 'unchanged EN value reuses the persisted pair');
assertI18n($reusedEn['status'] === 'reused', 'unchanged pair reports reuse');
$reusedPt = $translator->versions('Verificação da cozinha', 'pt', 'Verificação da cozinha', 'Kitchen Check');
assertI18n($reusedPt['status'] === 'reused', 'unchanged PT value reuses the persisted pair');
assertI18n($translator->versions('', 'pt')['status'] === 'empty', 'empty text does not call the provider');

$cache = new PDO('sqlite::memory:');
$cache->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$cache->exec(
    'CREATE TABLE translation_cache (
        engine_key TEXT NOT NULL,
        source_language TEXT NOT NULL,
        target_language TEXT NOT NULL,
        source_hash TEXT NOT NULL,
        source_text TEXT NOT NULL,
        translated_text TEXT NOT NULL,
        updated_at TEXT,
        PRIMARY KEY (engine_key, source_language, target_language, source_hash)
    )'
);
$insertCached = $cache->prepare(
    'INSERT INTO translation_cache
        (engine_key, source_language, target_language, source_hash, source_text, translated_text)
     VALUES
        (:engine, :source_language, :target_language, :source_hash, :source_text, :translated_text)'
);
$cachedPairs = [
    ['pt', 'en', 'Verifique "ZKTeco" no quarto 12.', 'Check "ZKTeco" in room 12.'],
    ['en', 'pt', 'Replace "SIP" at 08:30.', 'Substitua "SIP" às 08:30.'],
];
foreach ($cachedPairs as [$sourceLanguage, $targetLanguage, $sourceText, $translatedText]) {
    $insertCached->execute([
        'engine' => 'google-basic-nmt-v2',
        'source_language' => $sourceLanguage,
        'target_language' => $targetLanguage,
        'source_hash' => hash('sha256', $sourceText),
        'source_text' => $sourceText,
        'translated_text' => $translatedText,
    ]);
}
$bidirectional = new ContentTranslator($cache, ['enabled' => false]);
$fromPortuguese = $bidirectional->versions('Verifique "ZKTeco" no quarto 12.', 'pt');
assertI18n(
    $fromPortuguese['pt'] === 'Verifique "ZKTeco" no quarto 12.'
        && $fromPortuguese['en'] === 'Check "ZKTeco" in room 12.'
        && $fromPortuguese['status'] === 'cached',
    'PT to EN stores the source and translated versions in the correct columns'
);
$fromEnglish = $bidirectional->versions('Replace "SIP" at 08:30.', 'en');
assertI18n(
    $fromEnglish['pt'] === 'Substitua "SIP" às 08:30.'
        && $fromEnglish['en'] === 'Replace "SIP" at 08:30.'
        && $fromEnglish['status'] === 'cached',
    'EN to PT stores the translated and source versions in the correct columns'
);

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
