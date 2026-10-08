<?php
// Tokenize only: never include/evaluate the production configuration.
$source = stream_get_contents(STDIN);
$tokens = token_get_all($source, TOKEN_PARSE);
if (($argv[1] ?? '') === '--mail-summary') {
    $semantic = [];
    foreach ($tokens as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $semantic[] = $token;
    }
    $settings = [];
    for ($i = 0; $i + 6 < count($semantic); $i++) {
        $part = array_slice($semantic, $i, 7);
        if (!is_array($part[0]) || $part[0][0] !== T_VARIABLE || $part[0][1] !== '$config'
            || $part[1] !== '[' || !is_array($part[2]) || $part[2][0] !== T_CONSTANT_ENCAPSED_STRING
            || $part[3] !== ']' || $part[4] !== '=' || !is_array($part[5]) || $part[5][0] !== T_CONSTANT_ENCAPSED_STRING
            || $part[6] !== ';') { continue; }
        $name = substr($part[2][1], 1, -1);
        if (in_array($name, ['smtp_host', 'smtp_server', 'smtp_user', 'smtp_pass'], true)) {
            $settings[$name] = substr($part[5][1], 1, -1);
        }
    }
    $host = strtolower($settings['smtp_host'] ?? $settings['smtp_server'] ?? '');
    $provider = 'other_or_default';
    foreach (['postmark' => ['postmark', 'mtasv'], 'resend' => ['resend'], 'smtp2go' => ['smtp2go'], 'local' => ['localhost', '127.0.0.1']] as $name => $markers) {
        foreach ($markers as $marker) { if (strpos($host, $marker) !== false) { $provider = $name; } }
    }
    $credentials = 0;
    foreach (['smtp_user', 'smtp_pass'] as $name) {
        if (isset($settings[$name]) && !in_array($settings[$name], ['', '%u', '%p'], true)) { $credentials++; }
    }
    echo json_encode(['smtp_route_provider' => $provider, 'nonplaceholder_smtp_credential_fields' => $credentials]);
    exit;
}
if (($argv[1] ?? '') === '--core-summary') {
    $semantic = [];
    $redacted = '';
    foreach ($tokens as $token) {
        if (!is_array($token)) { $semantic[] = $token; $redacted .= $token; continue; }
        [$kind, $value] = $token;
        if (!in_array($kind, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $semantic[] = [$kind, $value];
        }
        if (in_array($kind, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE,
                           T_INLINE_HTML, T_COMMENT, T_DOC_COMMENT, T_LNUMBER, T_DNUMBER], true)) {
            $redacted .= '[literal-or-comment]' . str_repeat("\n", substr_count($value, "\n"));
        } else { $redacted .= $value; }
    }
    echo json_encode(['semantic_sha256' => hash('sha256', json_encode($semantic)), 'redacted' => $redacted]);
    exit;
}
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
if ($hasInclude) { exit(2); }
$refCount = preg_match_all($reference, $code);
// Accept one static list followed by static [] additions (common custom-plugin setup).
$statement = '~\$config\s*\[\s*([\'\"])plugins\1\s*\]\s*(?:(\[\s*\])\s*=\s*([\'\"])([a-z][a-z0-9_]*)\3|=\s*(?:array\s*\(([^()]*)\)|\[([^\[\]]*)\]))\s*;~s';
$count = preg_match_all($statement, $code, $statements, PREG_SET_ORDER);
if ($count !== $refCount || $count < 1) { exit(2); }
$names = [];
$assigned = false;
foreach ($statements as $matches) {
    if (($matches[2] ?? '') !== '') {
        if (!$assigned) { exit(2); }
        $names[] = $matches[4];
        continue;
    }
    if ($assigned) { exit(2); }
    $assigned = true;
    $body = ($matches[5] ?? '') !== '' ? $matches[5] : ($matches[6] ?? '');
    foreach (explode(',', $body) as $item) {
        $item = trim($item);
        if ($item === '') { continue; }
        if (!preg_match('~^([\'\"])([a-z][a-z0-9_]*)\1$~D', $item, $match)) { exit(2); }
        $names[] = $match[2];
    }
}
echo json_encode(['plugins' => array_values(array_unique($names)), 'php_open' => $open]);
