<?php
declare(strict_types=1);

function assertRuntimeTranslation(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$root = dirname(__DIR__);
$lib = file_get_contents($root . '/lib.php');
$api = file_get_contents($root . '/api.php');
$config = file_get_contents($root . '/config.php');
assertRuntimeTranslation(is_string($lib) && is_string($api) && is_string($config), 'runtime sources are readable');
assertRuntimeTranslation(!str_contains($lib, 'BilingualContentMaintenance'), 'database startup does not run bilingual maintenance');
assertRuntimeTranslation(!str_contains($lib, 'information_schema.COLUMNS'), 'database startup does not inspect or alter schema');
assertRuntimeTranslation(!str_contains($lib, 'translateStrict('), 'database startup performs no external translation backfill');
assertRuntimeTranslation(!str_contains($lib, 'registerDynamic('), 'database startup does not load all persisted content into the DOM dictionary');
assertRuntimeTranslation(str_contains($lib, "'itemDisplayNames' => []"), 'item view-model carries explicit localized display names');
assertRuntimeTranslation(str_contains($api, "'displayName' =>"), 'API exposes localized item names separately from canonical keys');
assertRuntimeTranslation(
    str_contains($config, 'GOOGLE_CLOUD_TRANSLATION_API_KEY')
        && str_contains($config, 'room-check-private/google-translation.json'),
    'Google key is read from server environment or an external private file'
);
assertRuntimeTranslation(!file_exists($root . '/src/I18n/BilingualContentMaintenance.php'), 'automatic runtime maintenance class is removed');
assertRuntimeTranslation(!file_exists($root . '/resources/lexicon/full/pt_PT.txt'), 'large local lexicon is removed');
assertRuntimeTranslation(!file_exists($root . '/src/ThirdParty/efficient-language-detector/manual_loader.php'), 'local language detector is removed');

echo "Runtime translation cleanup tests passed.\n";
