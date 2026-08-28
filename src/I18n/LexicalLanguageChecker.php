<?php
declare(strict_types=1);

interface LexicalLanguageClassifier
{
    /**
     * @param string[] $tokens Normalized lowercase lexical tokens.
     * @return array<string, string> Map token => pt_only|en_only|shared|unknown.
     */
    public function classifyTokens(array $tokens): array;
}

final class LexicalLookupException extends RuntimeException
{
}

/**
 * Deterministic PT/EN lexical lookup backed only by local files.
 *
 * No network request, external API, runtime dictionary cache or database table
 * is involved. MyMemory remains translation-only. The local word lists are
 * intentionally conservative: decisive everyday/hospitality vocabulary is
 * classified lexically; terms absent from both lists remain unknown and are
 * resolved by LanguageGuard using sentence context or rejected when they look
 * like typos/gibberish.
 */
final class LexicalLanguageChecker implements LexicalLanguageClassifier
{
    private const MAX_TOKEN_LENGTH = 190;

    /** @var array<string, bool>|null */
    private static ?array $ptWords = null;
    /** @var array<string, bool>|null */
    private static ?array $enWords = null;
    /** @var array<string, bool>|null */
    private static ?array $technicalWords = null;

    public function __construct(private PDO $pdo, private array $config = [])
    {
        // PDO is kept in the signature for backwards compatibility with the
        // application constructor. Local lexical verification does not use DB.
        self::loadLexicons($config);
    }

    public function classifyTokens(array $tokens): array
    {
        self::loadLexicons($this->config);
        $results = [];
        foreach ($tokens as $rawToken) {
            $token = self::normalizeToken((string) $rawToken);
            if ($token === '' || mb_strlen($token, 'UTF-8') > self::MAX_TOKEN_LENGTH) {
                continue;
            }

            if (isset(self::$technicalWords[$token])) {
                $results[$token] = 'shared';
                continue;
            }

            $hasPt = isset(self::$ptWords[$token]);
            $hasEn = isset(self::$enWords[$token]);
            $results[$token] = match (true) {
                $hasPt && $hasEn => 'shared',
                $hasPt => 'pt_only',
                $hasEn => 'en_only',
                default => 'unknown',
            };
        }
        return $results;
    }

    public static function isTechnicalNeutral(string $token): bool
    {
        self::loadLexicons([]);
        return isset(self::$technicalWords[self::normalizeToken($token)]);
    }

    public static function normalizeToken(string $token): string
    {
        $token = str_replace(['’', '‘'], "'", trim($token));
        return mb_strtolower($token, 'UTF-8');
    }

    private static function loadLexicons(array $config): void
    {
        if (self::$ptWords !== null && self::$enWords !== null && self::$technicalWords !== null) {
            return;
        }

        $root = dirname(__DIR__, 2);
        $ptPath = (string) ($config['pt_path'] ?? $root . '/resources/lexicon/pt_PT_core.txt');
        $enPath = (string) ($config['en_path'] ?? $root . '/resources/lexicon/en_GB_core.txt');
        $technicalPath = (string) ($config['technical_path'] ?? $root . '/resources/lexicon/technical_neutral.txt');

        self::$ptWords = self::readWordFile($ptPath);
        self::$enWords = self::readWordFile($enPath);
        self::$technicalWords = self::readWordFile($technicalPath);

        if (self::$ptWords === [] || self::$enWords === []) {
            throw new LexicalLookupException('Local PT/EN lexicon files are unavailable.');
        }
    }

    /** @return array<string, bool> */
    private static function readWordFile(string $path): array
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return [];
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            return [];
        }

        $words = [];
        foreach (preg_split('/\R/u', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $token = self::normalizeToken($line);
            if ($token !== '') {
                $words[$token] = true;
            }
        }
        return $words;
    }
}
