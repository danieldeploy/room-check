<?php
declare(strict_types=1);

function assertChecklistAutosave(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$root = dirname(__DIR__);
$api = file_get_contents($root . '/api.php');
$app = file_get_contents($root . '/assets/app.js');

assertChecklistAutosave(is_string($api), 'api.php is readable');
assertChecklistAutosave(is_string($app), 'assets/app.js is readable');

// The UI intentionally displays list defaults as a visual fallback when a room
// has no room-specific text. The multi-row autosave therefore submits values
// that the user did not edit. Those fallbacks must be discarded server-side
// before language validation/translation so one untouched row cannot reject the
// whole English (or Portuguese) save request.
assertChecklistAutosave(
    str_contains($api, "$defaultProblem = trim((string) ($selectedList['defaults'][$name] ?? ''));"),
    'checklist save identifies the active-language visual fallback'
);
assertChecklistAutosave(
    str_contains($api, "$existingPt === '' && $existingEn === '' && $problem === $defaultProblem"),
    'untouched fallback is ignored only when no persisted bilingual override exists'
);

$fallbackPosition = strpos($api, '$problem === $defaultProblem');
$translationPosition = strpos($api, '$problemVersions = $contentTranslator->versions(', $fallbackPosition ?: 0);
assertChecklistAutosave(
    $fallbackPosition !== false && $translationPosition !== false && $fallbackPosition < $translationPosition,
    'fallback is removed before LanguageGuard/provider translation is reached'
);
assertChecklistAutosave(
    str_contains($api, "$existingPt,\n            $existingEn\n        );"),
    'real room-specific values still use the existing PT/EN pair on update'
);

// This is the browser behavior that makes the server-side distinction necessary:
// one autosave request contains all visible checklist rows.
assertChecklistAutosave(
    str_contains($app, 'const collectItems = () => rows.map((row) => {'),
    'checklist autosave remains a multi-row request'
);
assertChecklistAutosave(
    str_contains($api, ": ($roomInstructions !== '' ? $roomInstructions : $listInstructions)"),
    'default instructions remain visible when there is no room-specific text'
);

echo "Checklist autosave fallback contract passed.\n";
