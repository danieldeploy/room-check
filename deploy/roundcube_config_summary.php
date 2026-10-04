<?php
// Tokenize only: never include/evaluate the production configuration.
$source = stream_get_contents(STDIN);
$tokens = token_get_all($source, TOKEN_PARSE);
$code = '';
$open = false;
$hasInclude = false;
foreach ($tokens as $token) {
    if (is_array($token)) {
        if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        if ($token[0] === T_OPEN_TAG) { $open = true; continue; }
        if ($token[0] === T_CLOSE_TAG) { $open = false; continue; }
        if ($token[0] === T_INLINE_HTML) {
            if (trim($token[1]) !== '') { exit(2); }
            continue;
        }
        if (in_array($token[0], [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EVAL], true)) {
            $hasInclude = true;
        }
        $code .= $token[1];
    } else { $code .= $token; }
}
$reference = '~\$config\s*\[\s*([\'\"])plugins\1\s*\]~';
if ($hasInclude || preg_match_all($reference, $code) !== 1) { exit(2); }
$assignment = '~\$config\s*\[\s*([\'\"])plugins\1\s*\]\s*=\s*(?:array\s*\(([^()]*)\)|\[([^\[\]]*)\])\s*;~s';
if (!preg_match($assignment, $code, $matches)) { exit(2); }
$body = ($matches[2] ?? '') !== '' ? $matches[2] : ($matches[3] ?? '');
$names = [];
foreach (explode(',', $body) as $item) {
    $item = trim($item);
    if ($item === '') { continue; }
    if (!preg_match('~^([\'\"])([a-z][a-z0-9_]*)\1$~D', $item, $match)) { exit(2); }
    $names[] = $match[2];
}
echo json_encode(['plugins' => array_values(array_unique($names)), 'php_open' => $open]);
