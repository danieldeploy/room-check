<?php
declare(strict_types=1);

function assertDecisionDialogStyle(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$root = dirname(__DIR__);
$css = file_get_contents($root . '/assets/decision-dialog.css');
$sessionBar = file_get_contents($root . '/src/UI/SessionBar.php');

assertDecisionDialogStyle(is_string($css) && is_string($sessionBar), 'decision dialog style sources are readable');
assertDecisionDialogStyle(
    str_contains($css, 'var(--brand')
        && str_contains($css, 'var(--brand-dark')
        && str_contains($css, 'var(--ink')
        && str_contains($css, 'var(--surface'),
    'decision dialog reuses the application visual tokens'
);
assertDecisionDialogStyle(
    str_contains($css, '.language-decision-actions button:first-child')
        && str_contains($css, '.language-decision-actions button:last-child')
        && str_contains($css, 'var(--wrong'),
    'dialog actions clearly distinguish continue editing from cancel edit'
);
assertDecisionDialogStyle(
    str_contains($css, '@media (max-width: 520px)')
        && str_contains($css, 'width: 100%')
        && str_contains($css, 'min-height: 46px'),
    'decision buttons remain touch-friendly on mobile'
);
assertDecisionDialogStyle(
    str_contains($sessionBar, 'decision-dialog.css?v=')
        && str_contains($sessionBar, '$decisionDialogCssVersion')
        && str_contains($sessionBar, 'filemtime'),
    'decision dialog stylesheet is cache-busted for deployment'
);

echo "Decision dialog visual contract passed.\n";
