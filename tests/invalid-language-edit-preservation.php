<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/I18n/LanguageGuard.php';
function okp(bool $c, string $m): void { if (!$c) throw new RuntimeException('FAIL: '.$m); echo 'PASS: '.$m.PHP_EOL; }
try {
    LanguageGuard::assertExpectedLanguage('Check that it is clean and undamaged. nuvem', 'en');
    throw new RuntimeException('FAIL: nuvem was accepted');
} catch (LanguageValidationException $e) {
    okp(in_array('nuvem', $e->invalidWords, true), 'EN validation reports Portuguese offending word');
}
try {
    LanguageGuard::assertExpectedLanguage('Verificar se está limpo e sem danos. cloud', 'pt');
    throw new RuntimeException('FAIL: cloud was accepted');
} catch (LanguageValidationException $e) {
    okp(in_array('cloud', $e->invalidWords, true), 'PT validation reports English offending word');
}
$api = file_get_contents(dirname(__DIR__).'/api.php');
$app = file_get_contents(dirname(__DIR__).'/assets/app.js');
$css = file_get_contents(dirname(__DIR__).'/assets/app.css');
$rooms = file_get_contents(dirname(__DIR__).'/rooms.php');
okp(str_contains($api, 'validate_bilingual_texts'), 'server exposes validation-only endpoint');
okp(str_contains($api, "'invalidWords' => \$exception->invalidWords"), '422 includes offending words');
okp(str_contains($app, 'text-validation-overlay'), 'client overlays wrong-language word without replacing textarea');
okp(str_contains($app, 'language-edit-dialog'), 'context change has correction/cancel dialog');
okp(str_contains($app, 'resolveDirtyTextBeforeContextChange'), 'context changes validate pending text first');
okp(str_contains($app, 'if (!(await resolveDirtyTextBeforeContextChange()))'), 'programmatic interval creation guards pending text before changing context');
okp(str_contains($app, 'deletingActiveInterval && !(await resolveDirtyTextBeforeContextChange())'), 'deleting the active interval guards pending text before changing context');
okp(str_contains($css, '.invalid-language-word'), 'wrong-language word has separate red style');
okp(str_contains($rooms, "'locale' => Translator::locale()"), 'client receives active locale');
echo "Invalid-language edit preservation contract passed.\n";
