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
 * Deterministic PT/EN lexical lookup backed by English Wiktionary language
 * sections. Results (including unknown words) are cached in MySQL, so normal
 * saves do not repeatedly call the lexical source.
 *
 * This class answers only the lexical question "is this word attested as
 * Portuguese and/or English?". It never translates text.
 */
final class LexicalLanguageChecker implements LexicalLanguageClassifier
{
    private const DEFAULT_ENDPOINT = 'https://en.wiktionary.org/w/api.php';
    private const MAX_BATCH_SIZE = 40;
    private const MAX_TOKEN_LENGTH = 190;

    /** @var array<string, string> */
    private static array $memoryCache = [];
    private static bool $schemaAttempted = false;

    public function __construct(private PDO $pdo, private array $config = [])
    {
        $this->ensureCacheSchema();
    }

    public function classifyTokens(array $tokens): array
    {
        $normalized = [];
        foreach ($tokens as $token) {
            $token = self::normalizeToken((string) $token);
            if ($token === '' || mb_strlen($token, 'UTF-8') > self::MAX_TOKEN_LENGTH) {
                continue;
            }
            $normalized[$token] = true;
        }
        $tokens = array_keys($normalized);
        if ($tokens === []) {
            return [];
        }

        $results = [];
        $missing = [];
        foreach ($tokens as $token) {
            if (isset(self::$memoryCache[$token])) {
                $results[$token] = self::$memoryCache[$token];
            } else {
                $missing[] = $token;
            }
        }

        if ($missing !== []) {
            $cached = $this->loadDatabaseCache($missing);
            foreach ($cached as $token => $classification) {
                self::$memoryCache[$token] = $classification;
                $results[$token] = $classification;
            }
            $missing = array_values(array_filter(
                $missing,
                static fn(string $token): bool => !isset($results[$token])
            ));
        }

        if ($missing !== []) {
            if (($this->config['enabled'] ?? true) !== true || !function_exists('curl_init')) {
                throw new LexicalLookupException('Lexical language lookup is unavailable.');
            }

            $batchSize = max(1, min(
                self::MAX_BATCH_SIZE,
                (int) ($this->config['batch_size'] ?? self::MAX_BATCH_SIZE)
            ));
            foreach (array_chunk($missing, $batchSize) as $batch) {
                $fresh = $this->fetchBatch($batch);
                foreach ($batch as $token) {
                    $classification = $fresh[$token] ?? 'unknown';
                    self::$memoryCache[$token] = $classification;
                    $results[$token] = $classification;
                    $this->storeDatabaseCache($token, $classification);
                }
            }
        }

        return $results;
    }

    /**
     * Public pure helper used by regression tests and by the HTTP parser.
     */
    public static function classifyWikitext(string $wikitext): string
    {
        $hasEnglish = preg_match('/^==\s*English\s*==\s*$/mi', $wikitext) === 1;
        $hasPortuguese = preg_match('/^==\s*Portuguese\s*==\s*$/mi', $wikitext) === 1;

        if ($hasEnglish && $hasPortuguese) {
            return 'shared';
        }
        if ($hasPortuguese) {
            return 'pt_only';
        }
        if ($hasEnglish) {
            return 'en_only';
        }
        return 'unknown';
    }

    /** @param string[] $tokens @return array<string, string> */
    private function fetchBatch(array $tokens): array
    {
        $endpoint = (string) ($this->config['endpoint'] ?? self::DEFAULT_ENDPOINT);
        $query = [
            'action' => 'query',
            'prop' => 'revisions',
            'titles' => implode('|', $tokens),
            'rvslots' => 'main',
            'rvprop' => 'content',
            'redirects' => '1',
            'format' => 'json',
            'formatversion' => '2',
        ];
        $url = rtrim($endpoint, '?') . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(3, (int) ($this->config['timeout_seconds'] ?? 6)),
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'RoomCheck/1.0 lexical-language-check',
        ]);
        $response = curl_exec($curl);
        $errno = curl_errno($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if (!is_string($response) || $errno !== 0 || $status < 200 || $status >= 300) {
            throw new LexicalLookupException('Lexical language lookup is temporarily unavailable.');
        }
        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['query']) || !is_array($data['query'])) {
            throw new LexicalLookupException('Lexical language lookup returned an invalid response.');
        }

        $queryData = $data['query'];
        $aliases = [];
        foreach (['normalized', 'redirects'] as $aliasType) {
            foreach (($queryData[$aliasType] ?? []) as $alias) {
                if (!is_array($alias)) {
                    continue;
                }
                $from = self::normalizeToken((string) ($alias['from'] ?? ''));
                $to = self::normalizeToken((string) ($alias['to'] ?? ''));
                if ($from !== '' && $to !== '') {
                    $aliases[$from] = $to;
                }
            }
        }

        $byCanonicalTitle = [];
        foreach (($queryData['pages'] ?? []) as $page) {
            if (!is_array($page)) {
                continue;
            }
            $title = self::normalizeToken((string) ($page['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            if (array_key_exists('missing', $page)) {
                $byCanonicalTitle[$title] = 'unknown';
                continue;
            }
            $revision = $page['revisions'][0] ?? null;
            $content = is_array($revision)
                ? (string) ($revision['slots']['main']['content'] ?? '')
                : '';
            $byCanonicalTitle[$title] = self::classifyWikitext($content);
        }

        $results = [];
        foreach ($tokens as $token) {
            $canonical = $token;
            $visited = [];
            while (isset($aliases[$canonical]) && !isset($visited[$canonical])) {
                $visited[$canonical] = true;
                $canonical = $aliases[$canonical];
            }
            $results[$token] = $byCanonicalTitle[$canonical]
                ?? $byCanonicalTitle[$token]
                ?? 'unknown';
        }
        return $results;
    }

    /** @param string[] $tokens @return array<string, string> */
    private function loadDatabaseCache(array $tokens): array
    {
        try {
            $hashes = array_map(static fn(string $token): string => hash('sha256', $token), $tokens);
            $placeholders = implode(',', array_fill(0, count($hashes), '?'));
            $statement = $this->pdo->prepare(
                "SELECT token, has_pt, has_en FROM lexical_language_cache WHERE token_hash IN ({$placeholders})"
            );
            $statement->execute($hashes);
            $results = [];
            foreach ($statement->fetchAll() as $row) {
                $token = self::normalizeToken((string) ($row['token'] ?? ''));
                if ($token === '') {
                    continue;
                }
                $results[$token] = self::classificationFromFlags(
                    (bool) ($row['has_pt'] ?? false),
                    (bool) ($row['has_en'] ?? false)
                );
            }
            return $results;
        } catch (Throwable) {
            return [];
        }
    }

    private function storeDatabaseCache(string $token, string $classification): void
    {
        [$hasPt, $hasEn] = match ($classification) {
            'pt_only' => [1, 0],
            'en_only' => [0, 1],
            'shared' => [1, 1],
            default => [0, 0],
        };
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO lexical_language_cache (token_hash, token, has_pt, has_en)
                 VALUES (:token_hash, :token, :has_pt, :has_en)
                 ON DUPLICATE KEY UPDATE
                    token = VALUES(token), has_pt = VALUES(has_pt), has_en = VALUES(has_en),
                    updated_at = CURRENT_TIMESTAMP'
            );
            $statement->execute([
                'token_hash' => hash('sha256', $token),
                'token' => $token,
                'has_pt' => $hasPt,
                'has_en' => $hasEn,
            ]);
        } catch (Throwable) {
            // The verifier still works without persistent cache.
        }
    }

    private function ensureCacheSchema(): void
    {
        if (self::$schemaAttempted) {
            return;
        }
        self::$schemaAttempted = true;
        try {
            $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS lexical_language_cache (
    token_hash CHAR(64) NOT NULL,
    token VARCHAR(190) NOT NULL,
    has_pt TINYINT(1) NOT NULL DEFAULT 0,
    has_en TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (token_hash),
    INDEX idx_lexical_language_cache_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        } catch (Throwable) {
            // Some hosting accounts cannot CREATE TABLE at runtime. Lookup still
            // works; only persistent lexical caching is unavailable.
        }
    }

    private static function classificationFromFlags(bool $hasPt, bool $hasEn): string
    {
        if ($hasPt && $hasEn) {
            return 'shared';
        }
        if ($hasPt) {
            return 'pt_only';
        }
        if ($hasEn) {
            return 'en_only';
        }
        return 'unknown';
    }

    public static function normalizeToken(string $token): string
    {
        $token = str_replace(['’', '‘'], "'", trim($token));
        return mb_strtolower($token, 'UTF-8');
    }
}
