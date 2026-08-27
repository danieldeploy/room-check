<?php
declare(strict_types=1);

/**
 * Fail-closed audit for persistent bilingual writes.
 *
 * User-facing code that writes a PT/EN database pair must:
 *  - write both columns together;
 *  - derive the source language from Translator::locale();
 *  - pass user text through ContentTranslator::versions();
 *  - for UPDATE/upsert paths, pass the existing PT/EN pair (4 arguments) so
 *    unchanged fields are reused before LanguageGuard validation.
 *
 * New modules are discovered recursively, so no filename needs to be added to
 * this test for ordinary application code.
 */

$root = dirname(__DIR__);
$schemaPath = $root . '/database.sql';
$schema = file_get_contents($schemaPath);
if (!is_string($schema)) {
    throw new RuntimeException('FAIL: cannot read database.sql');
}

$failures = [];
$protectedFiles = [];

$maintenanceExceptions = [
    'lib.php' => 'runtime schema/legacy backfill',
    'src/I18n/BilingualContentMaintenance.php' => 'legacy bilingual repair/backfill',
    'deploy/migrate_dynamic_list_item_names.php' => 'deployment migration',
];

/** @var array<string, array<string, string>> $pairsByTable */
$pairsByTable = [];
if (preg_match_all(
    '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?([A-Za-z0-9_]+)`?\s*\((.*?)\)\s*ENGINE/is',
    $schema,
    $tables,
    PREG_SET_ORDER
)) {
    foreach ($tables as $tableMatch) {
        $table = (string) $tableMatch[1];
        $body = (string) $tableMatch[2];
        $columns = [];
        if (preg_match_all(
            '/^\s*`?([A-Za-z_][A-Za-z0-9_]*)`?\s+(?:BIGINT|INT|TINYINT|SMALLINT|MEDIUMINT|VARCHAR|CHAR|TINYTEXT|TEXT|MEDIUMTEXT|LONGTEXT|ENUM|DATE|DATETIME|TIMESTAMP|TIME|JSON|DECIMAL|FLOAT|DOUBLE|BOOLEAN|BOOL)\b/im',
            $body,
            $columnMatches
        )) {
            foreach ($columnMatches[1] as $column) {
                $columns[(string) $column] = true;
            }
        }
        foreach (array_keys($columns) as $column) {
            if (!str_ends_with($column, '_en')) {
                continue;
            }
            $base = substr($column, 0, -3);
            if ($base !== '' && isset($columns[$base])) {
                $pairsByTable[$table][$base] = $column;
            }
        }
    }
}

if ($pairsByTable === []) {
    throw new RuntimeException('FAIL: no PT/EN column pairs discovered in database.sql');
}

/**
 * Return argument counts for every ->versions(...) or ::versions(...) call.
 * token_get_all keeps commas inside strings/comments out of the punctuation
 * stream, so nested calls and arrays can be counted safely.
 *
 * @return list<int>
 */
function bilingualVersionsArgumentCounts(string $source): array
{
    $tokens = token_get_all($source);
    $counts = [];
    $length = count($tokens);

    for ($i = 0; $i < $length; $i++) {
        $token = $tokens[$i];
        $isOperator = is_array($token)
            && in_array($token[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
        if (!$isOperator) {
            continue;
        }

        $j = $i + 1;
        while ($j < $length && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $length || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING
            || strtolower((string) $tokens[$j][1]) !== 'versions') {
            continue;
        }

        $j++;
        while ($j < $length && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $length || $tokens[$j] !== '(') {
            continue;
        }

        $depth = 0;
        $commas = 0;
        $hasArgument = false;
        for (; $j < $length; $j++) {
            $part = $tokens[$j];
            if ($part === '(' || $part === '[' || $part === '{') {
                $depth++;
                continue;
            }
            if ($part === ')' || $part === ']' || $part === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
                continue;
            }
            if ($depth === 1 && $part === ',') {
                $commas++;
                continue;
            }
            if ($depth === 1) {
                if (is_array($part)) {
                    if (!in_array($part[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        $hasArgument = true;
                    }
                } elseif (trim((string) $part) !== '') {
                    $hasArgument = true;
                }
            }
        }
        $counts[] = $hasArgument ? $commas + 1 : 0;
    }

    return $counts;
}

/**
 * @return list<string>
 */
function bilingualPhpFiles(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if (str_starts_with($path, 'tests/') || str_starts_with($path, '.git/')
            || str_starts_with($path, 'vendor/')) {
            continue;
        }
        $files[] = $path;
    }
    sort($files);
    return $files;
}

foreach (bilingualPhpFiles($root) as $path) {
    $source = file_get_contents($root . '/' . $path);
    if (!is_string($source)) {
        $failures[] = $path . ': unreadable PHP source';
        continue;
    }

    // These exact files repair/migrate already persisted data. They are not
    // user-submission paths, so writing only the missing target language is
    // intentional. Ordinary modules cannot opt out through a broad directory.
    if (isset($maintenanceExceptions[$path])) {
        echo 'PASS: maintenance exception ' . $path . ' (' . $maintenanceExceptions[$path] . ')' . PHP_EOL;
        continue;
    }

    $writes = [];
    foreach ($pairsByTable as $table => $pairs) {
        $tablePattern = preg_quote($table, '/');

        if (preg_match_all(
            '/\bUPDATE\s+`?' . $tablePattern . '`?\s+SET\s+(.*?)(?=\bWHERE\b|[\'\"]\s*[;)]|$)/is',
            $source,
            $updates,
            PREG_SET_ORDER
        )) {
            foreach ($updates as $update) {
                $setClause = (string) $update[1];
                foreach ($pairs as $base => $english) {
                    $baseWritten = (bool) preg_match('/\b' . preg_quote($base, '/') . '\s*=/', $setClause);
                    $englishWritten = (bool) preg_match('/\b' . preg_quote($english, '/') . '\s*=/', $setClause);
                    if (!$baseWritten && !$englishWritten) {
                        continue;
                    }
                    $writes[] = ['kind' => 'update', 'table' => $table, 'base' => $base, 'en' => $english];
                    if ($baseWritten !== $englishWritten) {
                        $failures[] = "$path: UPDATE $table must write $base and $english together";
                    }
                }
            }
        }

        if (preg_match_all(
            '/\bINSERT(?:\s+IGNORE)?\s+INTO\s+`?' . $tablePattern . '`?\s*\((.*?)\)\s*VALUES/is',
            $source,
            $inserts,
            PREG_SET_ORDER
        )) {
            foreach ($inserts as $insert) {
                $columnList = (string) $insert[1];
                foreach ($pairs as $base => $english) {
                    $baseWritten = (bool) preg_match('/\b' . preg_quote($base, '/') . '\b/', $columnList);
                    $englishWritten = (bool) preg_match('/\b' . preg_quote($english, '/') . '\b/', $columnList);
                    if (!$baseWritten && !$englishWritten) {
                        continue;
                    }
                    $upsert = (bool) preg_match(
                        '/\bINSERT(?:\s+IGNORE)?\s+INTO\s+`?' . $tablePattern . '`?.{0,16000}?\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/is',
                        $source
                    );
                    $writes[] = [
                        'kind' => $upsert ? 'upsert' : 'insert',
                        'table' => $table,
                        'base' => $base,
                        'en' => $english,
                    ];
                    if ($baseWritten !== $englishWritten) {
                        $failures[] = "$path: INSERT $table must write $base and $english together";
                    }
                }
            }
        }
    }

    if ($writes === []) {
        continue;
    }

    $protectedFiles[$path] = true;
    if (!str_contains($source, 'ContentTranslator')) {
        $failures[] = "$path: bilingual writes must use ContentTranslator";
    }
    if (!str_contains($source, 'Translator::locale()')) {
        $failures[] = "$path: bilingual writes must use the active Translator::locale()";
    }

    $argumentCounts = bilingualVersionsArgumentCounts($source);
    if ($argumentCounts === []) {
        $failures[] = "$path: bilingual writes must pass through ->versions(...)";
        continue;
    }

    $needsExistingPair = false;
    foreach ($writes as $write) {
        if (in_array($write['kind'], ['update', 'upsert'], true)) {
            $needsExistingPair = true;
            break;
        }
    }

    if ($needsExistingPair && max($argumentCounts) < 4) {
        $failures[] = "$path: bilingual UPDATE/upsert must call versions(text, locale, existingPt, existingEn)";
    }
    if (!$needsExistingPair && max($argumentCounts) < 2) {
        $failures[] = "$path: bilingual INSERT must call versions(text, locale)";
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Bilingual write audit failed:\n - " . implode("\n - ", array_values(array_unique($failures))) . "\n");
    exit(1);
}

if ($protectedFiles === []) {
    throw new RuntimeException('FAIL: audit found no user-facing bilingual write paths');
}

echo 'PASS: project-wide bilingual write boundary covers ' . count($protectedFiles) . ' application file(s): '
    . implode(', ', array_keys($protectedFiles)) . PHP_EOL;
echo "Bilingual write audit passed.\n";
