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

interface LexicalCaseSensitiveClassifier
{
    /**
     * Return exact-case tokens that are present in the upstream keep-case
     * English resource. Keys preserve case; values are always true.
     *
     * @param string[] $tokens Original-case lexical tokens.
     * @return array<string, bool>
     */
    public function classifyCaseSensitiveTokens(array $tokens): array;
}

interface LexicalNearMatchClassifier
{
    /** @return array{candidate:string,distance:int,classification:string}|null */
    public function likelyMisspelling(string $token): ?array;
}

interface LexicalCoverageClassifier
{
    /** True only when all generated full-language resources are available. */
    public function hasFullCoverage(): bool;
}

interface LexicalEntityClassifier
{
    /**
     * Explicit entity candidates used by the translator.
     *
     * @param string[] $tokens Normalized lowercase lexical tokens.
     * @return array<string, string> Map token => person|country|proper.
     */
    public function classifyEntityTokens(array $tokens): array;
}

final class LexicalLookupException extends RuntimeException
{
}

/**
 * Deterministic PT/EN lexical lookup backed only by local files.
 *
 * The full PT-PT and EN-GB dictionaries are sorted text files with small
 * two-character byte-range indexes. Only the relevant slices are read for each
 * request. The English source is `keep-case`, so source entries that must retain
 * their original capitalization are indexed separately instead of being lost by
 * lowercase normalization. Explicit person and country resources take precedence
 * over the broader proper-name fallback: people are neutral/preserved, while
 * country names remain lexical PT/EN evidence and are translated normally.
 */
final class LexicalLanguageChecker implements LexicalLanguageClassifier, LexicalCaseSensitiveClassifier, LexicalNearMatchClassifier, LexicalCoverageClassifier, LexicalEntityClassifier
{
    private const MAX_TOKEN_LENGTH = 190;

    /** @var array<string, bool>|null */
    private static ?array $ptCore = null;
    /** @var array<string, bool>|null */
    private static ?array $enCore = null;
    /** @var array<string, bool>|null */
    private static ?array $technicalWords = null;
    /** @var array<string, bool>|null */
    private static ?array $properWords = null;
    /** @var array<string, bool>|null */
    private static ?array $personWords = null;
    /** @var array<string, bool>|null */
    private static ?array $countryPtWords = null;
    /** @var array<string, bool>|null */
    private static ?array $countryEnWords = null;
    /** @var array<string, array<string, array{0:int,1:int}>> */
    private static array $indexCache = [];
    /** @var array<string, array<string, bool>> */
    private static array $membershipCache = [];

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
        [$enCaseWord, $enCaseIndex] = $this->caseSensitiveEnglishPaths();
        return self::usableFile($ptWord)
            && self::usableFile($ptIndex)
            && self::usableFile($enWord)
            && self::usableFile($enIndex)
            && self::usableFile($enCaseWord)
            && self::usableFile($enCaseIndex)
            && self::usableFile($this->properPath());
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
            // A confirmed person name is deliberately neutral. If a token is
            // both a person and a country name, preserving the person's name is
            // safer than translating it without sentence-level certainty.
            if (isset(self::$personWords[$token])) {
                $results[$token] = 'shared';
                continue;
            }

            // Countries are explicit language-bearing entities. This override
            // must run before the broad proper-name fallback so Spain/Germany/
            // Romania are not silently treated as neutral names.
            $countryPt = isset(self::$countryPtWords[$token]);
            $countryEn = isset(self::$countryEnWords[$token]);
            if ($countryPt || $countryEn) {
                $results[$token] = match (true) {
                    $countryPt && $countryEn => 'shared',
                    $countryPt => 'pt_only',
                    default => 'en_only',
                };
                continue;
            }

            // Explicit technology/brand identifiers and remaining generated
            // proper names are neutral for compatibility with existing data.
            if (isset(self::$technicalWords[$token]) || isset(self::$properWords[$token])) {
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

    public function classifyCaseSensitiveTokens(array $tokens): array
    {
        $exact = [];
        foreach ($tokens as $rawToken) {
            $token = self::normalizeCaseSensitiveToken((string) $rawToken);
            if ($token !== '' && mb_strlen($token, 'UTF-8') <= self::MAX_TOKEN_LENGTH) {
                $exact[$token] = true;
            }
        }
        $tokens = array_keys($exact);
        if ($tokens === []) {
            return [];
        }

        [$wordPath, $indexPath] = $this->caseSensitiveEnglishPaths();
        if (!self::usableFile($wordPath) || !self::usableFile($indexPath)) {
            return [];
        }

        $cacheKey = 'en-case|' . $wordPath . '|' . $indexPath;
        $cache = self::$membershipCache[$cacheKey] ?? [];
        $found = [];
        $unresolved = [];
        foreach ($tokens as $token) {
            if (array_key_exists($token, $cache)) {
                if ($cache[$token]) {
                    $found[$token] = true;
                }
            } else {
                $unresolved[] = $token;
            }
        }

        if ($unresolved !== []) {
            $fresh = self::indexedMembership($unresolved, $wordPath, $indexPath);
            foreach ($unresolved as $token) {
                $present = isset($fresh[$token]);
                $cache[$token] = $present;
                if ($present) {
                    $found[$token] = true;
                }
            }
            self::$membershipCache[$cacheKey] = $cache;
        }
        return $found;
    }

    public function classifyEntityTokens(array $tokens): array
    {
        self::loadSmallLexicons($this->config);
        $entities = [];
        foreach ($tokens as $rawToken) {
            $token = self::normalizeToken((string) $rawToken);
            if ($token === '' || mb_strlen($token, 'UTF-8') > self::MAX_TOKEN_LENGTH) {
                continue;
            }
            if (isset(self::$personWords[$token])) {
                $entities[$token] = 'person';
                continue;
            }
            if (isset(self::$countryPtWords[$token]) || isset(self::$countryEnWords[$token])) {
                $entities[$token] = 'country';
                continue;
            }
            if (isset(self::$properWords[$token])) {
                $entities[$token] = 'proper';
            }
        }
        return $entities;
    }

    /**
     * Near-match checks deliberately use only compact project vocabulary and
     * the exact technical list. Scanning full dictionaries/proper names on every
     * typo would be slower and would create many accidental near-matches.
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

        // Exact explicit entities/proper names are valid tokens, never typo candidates.
        if (isset(self::$personWords[$token])
            || isset(self::$countryPtWords[$token])
            || isset(self::$countryEnWords[$token])
            || isset(self::$properWords[$token])) {
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
        return mb_strtolower(self::normalizeCaseSensitiveToken($token), 'UTF-8');
    }

    public static function normalizeCaseSensitiveToken(string $token): string
    {
        return str_replace(['’', '‘'], "'", trim($token));
    }

    /** @param string[] $tokens @return array<string, bool> */
    private function languageMembership(array $tokens, string $language): array
    {
        [$fullPath, $indexPath] = $this->fullPaths($language);
        $cacheKey = $language . '|' . $fullPath . '|' . $indexPath;
        $cache = self::$membershipCache[$cacheKey] ?? [];
        $found = [];
        $unresolved = [];

        foreach ($tokens as $token) {
            if (array_key_exists($token, $cache)) {
                if ($cache[$token]) {
                    $found[$token] = true;
                }
            } else {
                $unresolved[] = $token;
            }
        }

        if ($unresolved === []) {
            return $found;
        }

        if (self::usableFile($fullPath) && self::usableFile($indexPath)) {
            $fresh = self::indexedMembership($unresolved, $fullPath, $indexPath);
            foreach ($unresolved as $token) {
                $present = isset($fresh[$token]);
                $cache[$token] = $present;
                if ($present) {
                    $found[$token] = true;
                }
            }
            self::$membershipCache[$cacheKey] = $cache;
            return $found;
        }

        // Deployment fail-safe: compact behavior remains available during a
        // partial deploy. Once all generated resources exist, the full
        // dictionaries and neutral proper-name list are used automatically.
        $core = $language === 'pt' ? self::$ptCore : self::$enCore;
        foreach ($unresolved as $token) {
            $present = isset($core[$token]);
            $cache[$token] = $present;
            if ($present) {
                $found[$token] = true;
            }
        }
        self::$membershipCache[$cacheKey] = $cache;
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

    /** @return array{0:string,1:string} */
    private function caseSensitiveEnglishPaths(): array
    {
        $root = dirname(__DIR__, 2);
        $word = (string) ($this->config['en_case_full_path']
            ?? $root . '/resources/lexicon/full/en_GB_case_sensitive.txt');
        $index = (string) ($this->config['en_case_index_path']
            ?? $root . '/resources/lexicon/full/en_GB_case_sensitive.index.json');
        return [$word, $index];
    }

    private function properPath(): string
    {
        $root = dirname(__DIR__, 2);
        return (string) ($this->config['proper_path']
            ?? $root . '/resources/lexicon/full/proper_neutral.txt');
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
        if (self::$ptCore !== null
            && self::$enCore !== null
            && self::$technicalWords !== null
            && self::$properWords !== null
            && self::$personWords !== null
            && self::$countryPtWords !== null
            && self::$countryEnWords !== null) {
            return;
        }
        $root = dirname(__DIR__, 2);
        $ptPath = (string) ($config['pt_path'] ?? $root . '/resources/lexicon/pt_PT_core.txt');
        $enPath = (string) ($config['en_path'] ?? $root . '/resources/lexicon/en_GB_core.txt');
        $technicalPath = (string) ($config['technical_path'] ?? $root . '/resources/lexicon/technical_neutral.txt');
        $properPath = (string) ($config['proper_path'] ?? $root . '/resources/lexicon/full/proper_neutral.txt');
        $personPath = (string) ($config['person_path'] ?? $root . '/resources/lexicon/full/person_neutral.txt');
        $countryPtPath = (string) ($config['country_pt_path'] ?? $root . '/resources/lexicon/country_pt.txt');
        $countryEnPath = (string) ($config['country_en_path'] ?? $root . '/resources/lexicon/country_en.txt');

        self::$ptCore = self::readSmallWordFile($ptPath);
        self::$enCore = self::readSmallWordFile($enPath);
        self::$technicalWords = self::readSmallWordFile($technicalPath);
        self::$properWords = self::readSmallWordFile($properPath);
        self::$personWords = self::readSmallWordFile($personPath);
        self::$countryPtWords = self::readSmallWordFile($countryPtPath);
        self::$countryEnWords = self::readSmallWordFile($countryEnPath);
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
