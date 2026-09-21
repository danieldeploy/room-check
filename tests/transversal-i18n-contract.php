<?php
declare(strict_types=1);

/**
 * Project-wide translation contract.
 *
 * This audit intentionally discovers entry points recursively. A future
 * module therefore has to join the shared HTML/JSON translation boundaries
 * instead of maintaining a private dictionary or returning untranslated UI
 * errors.
 */

$root = dirname(__DIR__);
$failures = [];
$htmlPages = [];
$jsonEndpoints = [];

$excludedPrefixes = [
    '.git/', 'tests/', 'vendor/', 'node_modules/', 'migrations/', 'deploy/', 'cron/',
];
$machineJsonExceptions = [
    // Authenticated by a device bearer token and consumed only by the Android
    // SMS forwarder. Its response is a boolean protocol, not human UI text.
    'invoice-auth.php' => true,
];

function emitsHtmlDocument(string $source): bool
{
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && $token[0] === T_INLINE_HTML
            && preg_match('/<!doctype\s+html|<html\b/i', (string) $token[1]) === 1) {
            return true;
        }
    }
    return false;
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    foreach ($excludedPrefixes as $prefix) {
        if (str_starts_with($path, $prefix)) {
            continue 2;
        }
    }
    $source = file_get_contents($file->getPathname());
    if (!is_string($source)) {
        $failures[] = $path . ': unreadable PHP source';
        continue;
    }

    if (emitsHtmlDocument($source)) {
        $htmlPages[] = $path;
        if (!str_contains($source, 'SessionBar::render(')
            && !str_contains($source, 'SiteTranslations::boot(')) {
            $failures[] = $path . ': HTML page must boot the shared translation catalogue';
        }
    }

    $declaresJson = preg_match(
        '/\bheader\s*\(\s*([\'\"])Content-Type:\s*application\/json/i',
        $source
    ) === 1;
    $usesSharedJson = str_contains($source, 'jsonResponse(');
    if (($declaresJson || $usesSharedJson) && $path !== 'lib.php') {
        $jsonEndpoints[] = $path;
        if (!isset($machineJsonExceptions[$path]) && !$usesSharedJson) {
            $failures[] = $path . ': user-facing JSON must use jsonResponse()';
        }
    }
}

$applicationFiles = [
    'config.php',
    'config.local.example.php',
    'src/I18n/ContentTranslator.php',
];
$providerOwners = [];
$architectureIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($architectureIterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }
    $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (!in_array($file->getExtension(), ['php', 'js'], true)
        || str_starts_with($path, 'tests/') || str_starts_with($path, 'vendor/')) {
        continue;
    }
    $source = file_get_contents($file->getPathname());
    if (!is_string($source)) {
        continue;
    }
    if (str_contains($source, 'translation.googleapis.com')) {
        $providerOwners[] = $path;
        if (!in_array($path, $applicationFiles, true)) {
            $failures[] = $path . ': direct Google Translation endpoint outside the shared translator/configuration';
        }
    }
    foreach ([
        'LanguageGuard', 'LexicalLanguageChecker', 'efficient-language-detector',
        'validate_bilingual_texts', 'translation-validate.php', 'invalidWords', 'unknownWords',
    ] as $obsolete) {
        if (str_contains($source, $obsolete)) {
            $failures[] = $path . ': obsolete local language validation detected (' . $obsolete . ')';
        }
    }
}

if ($htmlPages === []) {
    $failures[] = 'no HTML module pages discovered';
}
if ($jsonEndpoints === []) {
    $failures[] = 'no JSON endpoints discovered';
}
if (!in_array('src/I18n/ContentTranslator.php', $providerOwners, true)) {
    $failures[] = 'shared Google ContentTranslator endpoint not found';
}

$contentTranslatorSource = file_get_contents($root . '/src/I18n/ContentTranslator.php');
$domTranslatorSource = file_get_contents($root . '/src/I18n/Translator.php');
if (!is_string($contentTranslatorSource)
    || !str_contains($contentTranslatorSource, 'TranslationProtection::prepare(')
    || !str_contains($contentTranslatorSource, 'TranslationProtection::restore(')
    || !str_contains($contentTranslatorSource, 'TranslationProtection::preserves(')) {
    $failures[] = 'Google/cache translation must use the shared technical-literal protection';
}
if (!is_string($domTranslatorSource)
    || !str_contains($domTranslatorSource, 'TranslationProtection::literalPatternBody(false)')
    || !str_contains($domTranslatorSource, 'translateOutsideTechnicalLiterals')) {
    $failures[] = 'DOM translation must use the shared quoted-literal protection';
}

if ($failures !== []) {
    fwrite(STDERR, "Transversal i18n contract failed:\n - "
        . implode("\n - ", array_values(array_unique($failures))) . "\n");
    exit(1);
}

echo 'PASS: shared translation boot covers ' . count($htmlPages) . " HTML module page(s).\n";
echo 'PASS: shared localized response boundary covers ' . count($jsonEndpoints) . " JSON endpoint(s).\n";
echo "PASS: Google translation and local-validation architecture is centralized.\n";
echo "PASS: technical literals share one Google/cache/DOM protection rule.\n";
echo "Transversal i18n contract passed.\n";
