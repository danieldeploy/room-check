<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/I18n/SiteTranslations.php';

$root = dirname(__DIR__);
$catalog = SiteTranslations::catalog();

// Include the legacy Translator dictionary as well as the shared catalogue.
$dictionaryMethod = new ReflectionMethod(Translator::class, 'dictionary');
$dictionaryMethod->setAccessible(true);
$dictionary = array_replace($dictionaryMethod->invoke(null), $catalog);
$portugueseKeys = [];
foreach (array_keys($dictionary) as $key) {
    $key = trim((string) $key);
    if ($key !== '' && mb_strlen($key) >= 2) {
        $portugueseKeys[$key] = true;
    }
}
$portugueseKeys = array_keys($portugueseKeys);
usort($portugueseKeys, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

$excludedPaths = [
    '/migrations/', '/tests/', '/deploy/', '/docs/', '/.github/',
    '/src/I18n/', '/database.sql', '/lib.php', '/config.php', '/config.local.example.php',
];

// Machine identifiers are deliberately not translated. Keep this list small:
// new human-readable literals must be covered by the PT/EN catalogue instead.
$technicalLiterals = array_fill_keys([
    'gerente', 'governanta', 'tecnico_manutencao', 'empregada_andares',
    'utilizador', 'rooms', 'shared_bathrooms', 'corridors', 'kitchens', 'terraces',
], true);

// Exact server-only exceptions. These are not website UI: they are transport
// diagnostics or the PT branch of a message that already has an explicit EN branch.
$technicalExceptions = [
    'cron/whatsapp-reminders.php' => [
        '. Consulte no Portal de Gestão os itens e respetivas instruções.',
    ],
    'src/Notifications/WhatsAppCloudClient.php' => [
        'A extensão PHP cURL não está disponível.',
        'Número de telemóvel inválido.',
        'A Meta não devolveu o identificador da mensagem.',
        'Credenciais WhatsApp Cloud API não configuradas.',
    ],
];
foreach ($technicalExceptions as $path => $values) {
    $technicalExceptions[$path] = array_fill_keys($values, true);
}

$strongPortuguese = [
    'não', 'guardar', 'guardado', 'utilizador', 'utilizadores', 'configuração', 'configurar',
    'verificação', 'verificar', 'campainha', 'campainhas', 'telemóvel', 'telemóveis',
    'quarto', 'quartos', 'alojamento', 'empregada', 'empregadas', 'governanta',
    'permissão', 'permissões', 'apagar', 'criar', 'editar', 'selecionar', 'escolher',
    'atualizar', 'alterações', 'atribuído', 'atribuídos', 'atribuir', 'concluído',
    'problema', 'problemas', 'limpeza', 'funcionamento', 'lâmpada', 'lâmpadas',
    'portas', 'janelas', 'chaves', 'camas', 'corredores', 'cozinhas', 'terraços',
    'intervalo', 'intervalos', 'ficheiro', 'ficheiros', 'leitura', 'disponível',
    'desativado', 'desativada', 'ativar', 'desativar', 'conta', 'contas', 'dados',
    'destinatários', 'associações', 'carregar', 'carregando', 'nenhum', 'nenhuma',
    'inválido', 'inválida', 'obrigatório', 'obrigatória', 'manutenção', 'horário',
    'agendamento', 'mensagem', 'estado', 'ações', 'ação', 'sessão', 'instruções',
];

function looksPortuguese(string $text, array $strongPortuguese): bool
{
    $plain = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = preg_replace('/\s+/u', ' ', $plain) ?? $plain;
    $plain = trim($plain);
    if ($plain === '' || mb_strlen($plain) < 2) {
        return false;
    }

    $lower = mb_strtolower($plain, 'UTF-8');
    if (preg_match('/[ãõç]/u', $lower) === 1) {
        return true;
    }
    foreach ($strongPortuguese as $word) {
        if (preg_match('/(?<![\p{L}\p{N}_])' . preg_quote($word, '/') . '(?![\p{L}\p{N}_])/u', $lower) === 1) {
            return true;
        }
    }
    return false;
}

function uncoveredRemainder(string $text, array $portugueseKeys): string
{
    $remaining = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    foreach ($portugueseKeys as $key) {
        $remaining = str_replace($key, ' ', $remaining);
    }
    $remaining = preg_replace(
        '/\$\{[^}]*\}|\{[A-Za-z0-9_.-]+\}|%[0-9$+\-.]*[bcdeEfFgGosuxX]|<\?=.*?\?>/s',
        ' ',
        $remaining
    ) ?? $remaining;
    return trim(preg_replace('/\s+/u', ' ', $remaining) ?? $remaining);
}

function decodeQuotedLiteral(string $literal): string
{
    $quote = $literal[0] ?? '';
    if (($quote !== "'" && $quote !== '"') || strlen($literal) < 2) {
        return $literal;
    }
    $body = substr($literal, 1, -1);
    if ($quote === "'") {
        return str_replace(["\\\\", "\\'"], ["\\", "'"], $body);
    }
    return stripcslashes($body);
}

function phpCandidates(string $content): array
{
    $result = [];
    $tokens = token_get_all($content);
    $inInterpolated = false;
    $interpolated = '';
    $interpolatedLine = 1;

    foreach ($tokens as $token) {
        if (!is_array($token)) {
            if ($token === '"') {
                if ($inInterpolated) {
                    $result[] = ['line' => $interpolatedLine, 'text' => $interpolated];
                    $inInterpolated = false;
                    $interpolated = '';
                } else {
                    $inInterpolated = true;
                    $interpolated = '';
                }
            } elseif ($inInterpolated && $token === '{') {
                $interpolated .= '{value}';
            }
            continue;
        }

        [$type, $text, $line] = $token;
        if ($type === T_CONSTANT_ENCAPSED_STRING) {
            $result[] = ['line' => $line, 'text' => decodeQuotedLiteral($text)];
            continue;
        }

        if ($inInterpolated) {
            if ($interpolated === '') {
                $interpolatedLine = $line;
            }
            if ($type === T_ENCAPSED_AND_WHITESPACE) {
                $interpolated .= $text;
            } elseif (in_array($type, [T_VARIABLE, T_STRING_VARNAME, T_NUM_STRING], true)) {
                $interpolated .= '{value}';
            }
            continue;
        }

        if ($type === T_INLINE_HTML) {
            $visible = preg_replace('/<script\b[^>]*>.*?<\/script>|<style\b[^>]*>.*?<\/style>/is', ' ', $text) ?? $text;
            $visible = preg_replace('/<[^>]+>/', "\n", $visible) ?? $visible;
            foreach (preg_split('/\R/u', $visible) ?: [] as $offset => $piece) {
                $piece = trim(html_entity_decode($piece, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($piece !== '') {
                    $result[] = ['line' => $line + $offset, 'text' => $piece];
                }
            }
        }
    }
    return $result;
}

function jsCandidates(string $content): array
{
    $result = [];
    $pattern = '/([\'"`])((?:\\.|(?!\1).)*)\1/s';
    if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE) !== false) {
        foreach ($matches[2] as $match) {
            [$text, $offset] = $match;
            $line = substr_count(substr($content, 0, $offset), "\n") + 1;
            $text = preg_replace('/\$\{.*?\}/s', '{value}', $text) ?? $text;
            $result[] = ['line' => $line, 'text' => stripcslashes($text)];
        }
    }
    return $result;
}

$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $path = str_replace('\\', '/', $file->getPathname());
    $relative = substr($path, strlen(str_replace('\\', '/', $root)));
    $extension = strtolower($file->getExtension());
    if (!in_array($extension, ['php', 'js'], true)) {
        continue;
    }
    $excluded = false;
    foreach ($excludedPaths as $needle) {
        if (str_contains($relative, $needle)) {
            $excluded = true;
            break;
        }
    }
    if (!$excluded) {
        $files[] = [$file->getPathname(), ltrim($relative, '/')];
    }
}

$findings = [];
foreach ($files as [$absolute, $relative]) {
    $content = file_get_contents($absolute);
    if (!is_string($content)) {
        continue;
    }
    $candidates = str_ends_with($relative, '.php') ? phpCandidates($content) : jsCandidates($content);
    foreach ($candidates as $candidate) {
        $text = trim((string) $candidate['text']);
        if ($text === '' || isset($technicalLiterals[mb_strtolower($text, 'UTF-8')])) {
            continue;
        }
        if (isset($technicalExceptions[$relative][$text])) {
            continue;
        }
        if (!looksPortuguese($text, $strongPortuguese)) {
            continue;
        }
        $remaining = uncoveredRemainder($text, $portugueseKeys);
        if (!looksPortuguese($remaining, $strongPortuguese)) {
            continue;
        }
        $key = $relative . ':' . (int) $candidate['line'] . ':' . $text;
        $findings[$key] = [
            'file' => $relative,
            'line' => (int) $candidate['line'],
            'text' => $text,
            'remaining' => $remaining,
        ];
    }
}
$findings = array_values($findings);

if ($findings === []) {
    echo "PASS: no uncovered Portuguese-looking static UI literals found.\n";
    exit(0);
}

echo "Static PT/EN audit failed with " . count($findings) . " uncovered candidate(s):\n";
foreach ($findings as $finding) {
    echo sprintf(
        "- %s:%d\n  text: %s\n  uncovered: %s\n",
        $finding['file'],
        $finding['line'],
        json_encode($finding['text'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($finding['remaining'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

exit(1);
