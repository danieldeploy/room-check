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

interface LexicalNearMatchClassifier
{
    /** @return array{candidate:string,distance:int,classification:string}|null */
    public function likelyMisspelling(string $token): ?array;
}

interface LexicalCoverageClassifier
{
    /** True only when both generated full-language resources are available. */
    public function hasFullCoverage(): bool;
}

final class LexicalLookupException extends RuntimeException
{
}

/**
 * Deterministic PT/EN lexical lookup backed only by local files.
 *
 * The full PT-PT and EN-GB dictionaries are sorted text files with small
 * two-character byte-range indexes. Only the relevant slices are read for each
 * request, so cPanel/PHP never needs to materialize hundreds of thousands of
 * dictionary entries in memory. The compact core lists remain only for bounded
 * typo-near-match checks and as a fail-safe if a generated full resource is
 * temporarily missing during deployment.
 */
final class LexicalLanguageChecker implements LexicalLanguageClassifier, LexicalNearMatchClassifier, LexicalCoverageClassifier
{
    private const MAX_TOKEN_LENGTH = 190;

    /** @var array<string, bool>|null */
    private static ?array $ptCore = null;
    /** @var array<string, bool>|null */
    private static ?array $enCore = null;
    /** @var array<string, bool>|null */
    private static ?array $technicalWords = null;
    /** @var array<string, array<string, array{0:int,1:int}>> */
    private static array $indexCache = [];

    public function __construct(PDO $pdo, private array $config = [])
    {
        // PDO stays in the public signature for compatibility with
        // ContentTranslator. Lexical validation itself is file-only.
        self::loadSmallLexicons($config);
    }

    public function hasFullCoverage(): bool
    {
        [$ptWord, $ptIndex] = $this->fullPaths('pt');
        [$enWord, $enIndex] = $this->fullPaths('en');
        return self::usableFile($ptWord)
            && self::usableFile($ptIndex)
            && self::usableFile($enWord)
            && self::usableFile($enIndex);
    }

    public function classifyTokens(array $tokens): array
    {
        self::loadSmallLexicons($this->config);
        $normalized = [];
        foreach ($tokens as $rawToken) {
            $token = self::normalizeToken((string) $rawToken);
            if ($token !== '' && mb_strlen($token, 'UTF-8') <= self::MAX_TOKEN_LENGTH) {
                $normalized[$token] = true;
            }
        }
        $tokens = array_keys($normalized);
        if ($tokens === []) {
            return [];
        }

        $ptMembership = $this->languageMembership($tokens, 'pt');
        $enMembership = $this->languageMembership($tokens, 'en');
        $results = [];

        foreach ($tokens as $token) {
            if (isset(self::$technicalWords[$token])) {
                $results[$token] = 'shared';
                continue;
            }
            $hasPt = isset($ptMembership[$token]);
            $hasEn = isset($enMembership[$token]);
            $results[$token] = match (true) {
                $hasPt && $hasEn => 'shared',
                $hasPt => 'pt_only',
                $hasEn => 'en_only',
                default => 'unknown',
            };
        }
        return $results;
    }

    /**
     * Near-match checks deliberately use only the compact project vocabulary and
     * exact technical list. Scanning a complete language dictionary for every
     * typo would be wasteful and would also create many accidental near-matches.
     * This keeps the useful HAR regressions such as danosht -> danos and
     * YAHOOX -> yahoo without changing ordinary dictionary coverage.
     *
     * @return array{candidate:string,distance:int,classification:string}|null
     */
    public function likelyMisspelling(string $token): ?array
    {
        self::loadSmallLexicons($this->config);
        $token = self::normalizeToken($token);
        $length = mb_strlen($token, 'UTF-8');
        if ($token === '' || $length < 4 || $length > self::MAX_TOKEN_LENGTH) {
            return null;
        }

        $known = self::$ptCore + self::$enCore + self::$technicalWords;
        if (isset($known[$token])) {
            return null;
        }

        $maxDistance = $length >= 5 ? 2 : 1;
        $best = null;
        foreach (array_keys($known) as $candidate) {
            $candidateLength = mb_strlen($candidate, 'UTF-8');
            if ($candidateLength < 3 || abs($candidateLength - $length) > $maxDistance) {
                continue;
            }
            $distance = self::unicodeEditDistance($token, $candidate, $maxDistance);
            if ($distance < 1 || $distance > $maxDistance) {
                continue;
            }
            if ($best !== null && $distance >= $best['distance']) {
                continue;
            }
            $classification = isset(self::$technicalWords[$candidate])
                ? 'technical'
                : (isset(self::$ptCore[$candidate]) && isset(self::$enCore[$candidate])
                    ? 'shared'
                    : (isset(self::$ptCore[$candidate]) ? 'pt_only' : 'en_only'));
            $best = [
                'candidate' => $candidate,
                'distance' => $distance,
                'classification' => $classification,
            ];
            if ($distance === 1) {
                break;
            }
        }
        return $best;
    }

    public static function isTechnicalNeutral(string $token): bool
    {
        self::loadSmallLexicons([]);
        return isset(self::$technicalWords[self::normalizeToken($token)]);
    }

    public static function normalizeToken(string $token): string
    {
        $token = str_replace(['’', '‘'], "'", trim($token));
        return mb_strtolower($token, 'UTF-8');
    }

    /** @param string[] $tokens @return array<string, bool> */
    private function languageMembership(array $tokens, string $language): array
    {
        [$fullPath, $indexPath] = $this->fullPaths($language);
        if (self::usableFile($fullPath) && self::usableFile($indexPath)) {
            return self::indexedMembership($tokens, $fullPath, $indexPath);
        }

        // Deployment fail-safe: the last nearly-good compact behavior remains
        // available during a partial deploy. Once resources/lexicon/full exists,
        // production automatically uses the large dictionaries instead.
        $core = $language === 'pt' ? self::$ptCore : self::$enCore;
        $found = [];
        foreach ($tokens as $token) {
            if (isset($core[$token])) {
                $found[$token] = true;
            }
        }
        return $found;
    }

    /** @return array{0:string,1:string} */
    private function fullPaths(string $language): array
    {
        $root = dirname(__DIR__, 2);
        $isPt = $language === 'pt';
        $word = (string) ($this->config[$isPt ? 'pt_full_path' : 'en_full_path']
            ?? $root . '/resources/lexicon/full/' . ($isPt ? 'pt_PT.txt' : 'en_GB.txt'));
        $index = (string) ($this->config[$isPt ? 'pt_index_path' : 'en_index_path']
            ?? $root . '/resources/lexicon/full/' . ($isPt ? 'pt_PT.index.json' : 'en_GB.index.json'));
        return [$word, $index];
    }

    private static function usableFile(string $path): bool
    {
        return $path !== '' && is_file($path) && is_readable($path) && filesize($path) > 0;
    }

    /** @param string[] $tokens @return array<string, bool> */
    private static function indexedMembership(array $tokens, string $wordPath, string $indexPath): array
    {
        $index = self::readIndex($indexPath);
        $groups = [];
        foreach ($tokens as $token) {
            $prefix = mb_substr($token, 0, 2, 'UTF-8');
            $groups[$prefix][$token] = true;
        }

        $handle = @fopen($wordPath, 'rb');
        if ($handle === false) {
            throw new LexicalLookupException('Large local lexical resource is unreadable.');
        }

        $found = [];
        try {
            foreach ($groups as $prefix => $wanted) {
                $range = $index[$prefix] ?? null;
                if (!is_array($range) || count($range) !== 2) {
                    continue;
                }
                $start = (int) $range[0];
                $end = (int) $range[1];
                if ($start < 0 || $end < $start || fseek($handle, $start) !== 0) {
                    throw new LexicalLookupException('Large local lexical index is invalid.');
                }

                while (ftell($handle) < $end && ($line = fgets($handle)) !== false) {
                    $word = rtrim($line, "\r\n");
                    if (isset($wanted[$word])) {
                        $found[$word] = true;
                        unset($wanted[$word]);
                        if ($wanted === []) {
                            break;
                        }
                    }
                }
            }
        } finally {
            fclose($handle);
        }
        return $found;
    }

    /** @return array<string, array{0:int,1:int}> */
    private static function readIndex(string $path): array
    {
        if (isset(self::$indexCache[$path])) {
            return self::$indexCache[$path];
        }
        $json = file_get_contents($path);
        $decoded = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($decoded)) {
            throw new LexicalLookupException('Large local lexical index is unavailable.');
        }

        $index = [];
        foreach ($decoded as $prefix => $range) {
            if (!is_string($prefix) || !is_array($range) || count($range) !== 2) {
                continue;
            }
            $index[$prefix] = [(int) $range[0], (int) $range[1]];
        }
        self::$indexCache[$path] = $index;
        return $index;
    }

    private static function loadSmallLexicons(array $config): void
    {
        if (self::$ptCore !== null && self::$enCore !== null && self::$technicalWords !== null) {
            return;
        }
        $root = dirname(__DIR__, 2);
        $ptPath = (string) ($config['pt_path'] ?? $root . '/resources/lexicon/pt_PT_core.txt');
        $enPath = (string) ($config['en_path'] ?? $root . '/resources/lexicon/en_GB_core.txt');
        $technicalPath = (string) ($config['technical_path'] ?? $root . '/resources/lexicon/technical_neutral.txt');

        self::$ptCore = self::readSmallWordFile($ptPath);
        self::$enCore = self::readSmallWordFile($enPath);
        self::$technicalWords = self::readSmallWordFile($technicalPath);
        if (self::$ptCore === [] || self::$enCore === []) {
            throw new LexicalLookupException('Local PT/EN lexical core files are unavailable.');
        }
    }

    /** @return array<string, bool> */
    private static function readSmallWordFile(string $path): array
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

    private static function unicodeEditDistance(string $left, string $right, int $cutoff): int
    {
        $a = preg_split('//u', $left, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $b = preg_split('//u', $right, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $aCount = count($a);
        $bCount = count($b);
        if (abs($aCount - $bCount) > $cutoff) {
            return $cutoff + 1;
        }

        $previous = range(0, $bCount);
        for ($i = 1; $i <= $aCount; $i++) {
            $current = [$i];
            $rowMin = $i;
            for ($j = 1; $j <= $bCount; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $current[$j] = min(
                    $current[$j - 1] + 1,
                    $previous[$j] + 1,
                    $previous[$j - 1] + $cost
                );
                $rowMin = min($rowMin, $current[$j]);
            }
            if ($rowMin > $cutoff) {
                return $cutoff + 1;
            }
            $previous = $current;
        }
        return $previous[$bCount] ?? ($cutoff + 1);
    }
}
