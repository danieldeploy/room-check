<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/I18n/SiteTranslations.php';

function assertI18n(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$catalog = SiteTranslations::catalog();
assertI18n(($catalog['Controlo My2N'] ?? null) === 'My2N Control', 'My2N/Bell UI is in the shared catalogue');
assertI18n(($catalog['Estado da integração'] ?? null) === 'Integration status', 'ZKAccess UI is in the shared catalogue');
assertI18n(($catalog['Novo utilizador'] ?? null) === 'New user', 'Users UI is in the shared catalogue');
assertI18n(($catalog['Permissões dos perfis'] ?? null) === 'Role permissions', 'Permissions UI is in the shared catalogue');
assertI18n(($catalog['Configuração inicial'] ?? null) === 'Initial setup', 'Setup UI is in the shared catalogue');

$_SESSION = [];
Translator::setLocale('pt', false);
assertI18n(SiteTranslations::text('Guardar exemplo', 'Save example') === 'Guardar exemplo', 'static helper returns Portuguese in PT locale');
Translator::setLocale('en', false);
assertI18n(SiteTranslations::text('Guardar exemplo 2', 'Save example 2') === 'Save example 2', 'static helper returns English in EN locale');
assertI18n(
    SiteTranslations::format('Foram guardados {count} registos.', '{count} records were saved.', ['{count}' => '3']) === '3 records were saved.',
    'formatted static text follows active locale'
);

SiteTranslations::boot();
$json = '{"label":"Controlo My2N"}';
assertI18n(Translator::translateOutput($json) === $json, 'JSON/API payloads are never globally translated');
$html = '<html><body><span>Controlo My2N</span></body></html>';
$translatedOutput = Translator::translateOutput($html);
assertI18n(str_contains($translatedOutput, 'My2N Control'), 'HTML output receives the shared English catalogue');
assertI18n(str_contains($translatedOutput, 'MutationObserver'), 'dynamic DOM translation remains enabled for JS-rendered UI');

echo 'Site-wide i18n tests passed.' . PHP_EOL;
