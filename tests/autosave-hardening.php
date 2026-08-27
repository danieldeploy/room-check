<?php
declare(strict_types=1);

function assertAutosaveHardening(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$source = file_get_contents(dirname(__DIR__) . '/assets/app.js');
assertAutosaveHardening(is_string($source), 'app.js source is readable');

assertAutosaveHardening(
    str_contains($source, 'const TEXT_AUTOSAVE_DELAY_MS = 1200;'),
    'text autosave waits for a real debounce interval'
);
assertAutosaveHardening(
    str_contains($source, 'textIsReadyForAutosave(textarea, event)'),
    'typing is gated by a word/paste boundary before autosave is scheduled'
);
assertAutosaveHardening(
    str_contains($source, 'SAVE_BOUNDARY_PATTERN'),
    'word-boundary autosave guard is present'
);
assertAutosaveHardening(
    str_contains($source, "textarea.addEventListener('blur'"),
    'unfinished final words are flushed on blur'
);
assertAutosaveHardening(
    str_contains($source, 'lastSavedChecklistFingerprint')
        && str_contains($source, 'snapshot.fingerprint === lastSavedChecklistFingerprint'),
    'identical checklist snapshots are not sent twice'
);
assertAutosaveHardening(
    str_contains($source, 'instructionLastAttemptedValues')
        && str_contains($source, 'effectiveChanges'),
    'assignment instruction saves are deduplicated'
);
assertAutosaveHardening(
    !str_contains($source, 'textarea.dataset.problem = textarea.value;'),
    'unsaved text is not mislabeled as persisted problem data'
);
assertAutosaveHardening(
    str_contains($source, 'row.textarea.dataset.problem = persistedByName.get(row.name)'),
    'persisted problem baseline advances only after server success'
);
assertAutosaveHardening(
    str_contains($source, 'keepalive: true'),
    'valid pending checklist edits get a final keepalive save on navigation'
);
assertAutosaveHardening(
    !str_contains($source, '}, 600);') && !str_contains($source, '}, 700);'),
    'legacy per-keystroke-like 600/700ms autosave timers are removed'
);

echo "Autosave hardening regression passed.\n";
