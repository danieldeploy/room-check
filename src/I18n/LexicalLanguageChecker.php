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
     * @param string[] $tokens Original-case lexical tokens.
     * @return array<string, bool> Exact upstream EN-GB keep-case matches.
     */
    public function classifyCaseSensitiveTokens(array $tokens): array;
}

interface LexicalPersonClassifier
{
    /**
     * A person is accepted only when the original token has normal name
     * capitalization AND its normalized spelling exists in a generated,
     * externally sourced person-name corpus. Capitalization alone is never
     * evidence that a token is a name.
     *
     * @param string[] $tokens Original-case lexical tokens.
     * @return array<string, bool> Exact original-case token => true.
     */
    public function classifyPersonTokens(array $tokens): array;
}

interface LexicalCoverageClassifier
{
    /** True only when every generated validation resource is available. */
    public function hasFullCoverage(): bool;
}

interface LexicalEntityClassifier
{
    /**
     * Compatibility interface for language-bearing country entities.
     * Person recognition is exposed separately because it needs original case.
     *
     * @param string[] $tokens Normalized lowercase lexical tokens.
     * @return array<string, string> Map token => country.
     */
    public function classifyEntityTokens(array $tokens): array;
}

// Kept as a compatibility contract for older test doubles. Production lexical
// verification no longer uses hand-sized near-match lists.
interface LexicalNearMatchClassifier
{
    /** @return array{candidate:string,distance:int,classification:string}|null */
    public function likelyMisspelling(string $token): ?array;
}

final class LexicalLookupException extends RuntimeException
{
}

/**
 * Deterministic PT/EN lexical lookup backed only by generated, externally
 * sourced files bundled with Room Check.
 *
 * - ordinary EN: pinned CSpell EN-GB
 * - ordinary PT: pinned PT-PT Hunspell expansion
 * - EN keep-case forms: preserved from CSpell
 * - people: generated global person-name corpus
 * - countries: generated Unicode CLDR labels for EN and PT-PT
 *
 * There is intentionally no project-maintained technical-word list, compact
 * language dictionary, capitalization heuristic or generic proper-name bypass.
 * Unknown custom/technical text must use the explicit double-quote escape.
 */
final class LexicalLanguageChecker implements
    LexicalLanguageClassifier,
    LexicalCaseSensitiveClassifier,
    LexicalPersonClassifier,
    LexicalCoverageClassifier,
    LexicalEntityClassifier
{
    private const MAX_TOKEN_LENGTH = 190;

    /** @var array<string, array<string, array{0:int,1:int}>> */
    private static array $indexCache = [];
    /** @var array<string, array<string, bool>> */
    private static array $membershipCache = [];
    /** @var array<string, array<string, bool>> */
    private static array $plainWordCache = [];

    public function __construct(PDO $pdo, private array $config = [])
    {
        // PDO stays in the public signature for ContentTranslator compatibility.
        // Validation itself is local and file-only.
    }

    public function hasFullCoverage(): bool
    {
        [$ptWord, $ptIndex] = $this->fullPaths('pt');
        [$enWord, $enIndex] = $this->fullPaths('en');
        [$enCaseWord, $enCaseIndex] = $this->caseSensitiveEnglishPaths();
        [$personWord, $personIndex] = $this->personPaths();

        return self::usableFile($ptWord)
            && self::usableFile($ptIndex)
            && self::usableFile($enWord)
            && self::usableFile($enIndex)
            && self::usableFile($enCaseWord)
            && self::usableFile($enCaseIndex)
            && self::usableFile($personWord)
            && self::usableFile($personIndex)
            && self::usableFile($this->countryPath('pt'))
            && self::usableFile($this->countryPath('en'));
    }

    public function classifyTokens(array $tokens): array
    {
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

        $countryPt = $this->countryMembership('pt');
        $countryEn = $this->countryMembership('en');
        $ptMembership = $this->languageMembership($tokens, 'pt');
        $enMembership = $this->languageMembership($tokens, 'en');
        $results = [];

        foreach ($tokens as $token) {
            // Country labels are explicitly language-bearing and take priority
            // over other lexical ambiguity so Spain/Espanha translate normally.
            $isCountryPt = isset($countryPt[$token]);
            $isCountryEn = isset($countryEn[$token]);
            if ($isCountryPt || $isCountryEn) {
                $results[$token] = match (true) {
                    $isCountryPt && $isCountryEn => 'shared',
                    $isCountryPt => 'pt_only',
                    default => 'en_only',
                };
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
        return $this->requiredIndexedMembership($tokens, $wordPath, $indexPath, 'English keep-case');
    }

    public function classifyPersonTokens(array $tokens): array
    {
        $eligible = [];
        $normalizedWanted = [];
        foreach ($tokens as $rawToken) {
            $exact = self::normalizeCaseSensitiveToken((string) $rawToken);
            if (!self::looksLikePersonCase($exact)) {
                continue;
            }
            $normalized = self::normalizeToken($exact);
            if ($normalized === '' || mb_strlen($normalized, 'UTF-8') > self::MAX_TOKEN_LENGTH) {
                continue;
            }
            $eligible[$exact] = $normalized;
            $normalizedWanted[$normalized] = true;
        }
        if ($eligible === []) {
            return [];
        }

        [$wordPath, $indexPath] = $this->personPaths();
        $members = $this->requiredIndexedMembership(
            array_keys($normalizedWanted),
            $wordPath,
            $indexPath,
            'person-name'
        );

        $found = [];
        foreach ($eligible as $exact => $normalized) {
            if (isset($members[$normalized])) {
                $found[$exact] = true;
            }
        }
        return $found;
    }

    public function classifyEntityTokens(array $tokens): array
    {
        $countryPt = $this->countryMembership('pt');
        $countryEn = $this->countryMembership('en');
        $entities = [];
        foreach ($tokens as $rawToken) {
            $token = self::normalizeToken((string) $rawToken);
            if ($token !== '' && (isset($countryPt[$token]) || isset($countryEn[$token]))) {
                $entities[$token] = 'country';
            }
        }
        return $entities;
    }

    public static function normalizeToken(string $token): string
    {
        return mb_strtolower(self::normalizeCaseSensitiveToken($token), 'UTF-8');
    }

    public static function normalizeCaseSensitiveToken(string $token): string
    {
        return str_replace(['’', '‘'], "'", trim($token));
    }

    private static function looksLikePersonCase(string $token): bool
    {
        if ($token === '' || mb_strlen($token, 'UTF-8') > self::MAX_TOKEN_LENGTH) {
            return false;
        }
        // Require the first Unicode letter to be uppercase. Membership in the
        // sourced corpus remains mandatory, so Ggjhrtgu does not pass merely
        // because it has name-like capitalization.
        return preg_match('/^\p{Lu}[\p{L}’\'\-]*$/u', $token) === 1;
    }

    /** @param string[] $tokens @return array<string, bool> */
    private function languageMembership(array $tokens, string $language): array
    {
        [$fullPath, $indexPath] = $this->fullPaths($language);
        return $this->requiredIndexedMembership(
            $tokens,
            $fullPath,
            $indexPath,
            strtoupper($language) . ' lexical'
        );
    }

    /** @param string[] $tokens @return array<string, bool> */
    private function requiredIndexedMembership(
        array $tokens,
        string $wordPath,
        string $indexPath,
        string $label
    ): array {
        if (!self::usableFile($wordPath) || !self::usableFile($indexPath)) {
            throw new LexicalLookupException($label . ' resource is unavailable.');
        }

        $cacheKey = $label . '|' . $wordPath . '|' . $indexPath;
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
        return [
            (string) ($this->config['en_case_full_path']
                ?? $root . '/resources/lexicon/full/en_GB_case_sensitive.txt'),
            (string) ($this->config['en_case_index_path']
                ?? $root . '/resources/lexicon/full/en_GB_case_sensitive.index.json'),
        ];
    }

    /** @return array{0:string,1:string} */
    private function personPaths(): array
    {
        $root = dirname(__DIR__, 2);
        return [
            (string) ($this->config['person_full_path']
                ?? $root . '/resources/lexicon/full/person_neutral.txt'),
            (string) ($this->config['person_index_path']
                ?? $root . '/resources/lexicon/full/person_neutral.index.json'),
        ];
    }

    private function countryPath(string $language): string
    {
        $root = dirname(__DIR__, 2);
        return (string) ($this->config[$language === 'pt' ? 'country_pt_path' : 'country_en_path']
            ?? $root . '/resources/lexicon/full/' . ($language === 'pt' ? 'country_pt.txt' : 'country_en.txt'));
    }

    /** @return array<string, bool> */
    private function countryMembership(string $language): array
    {
        $path = $this->countryPath($language);
        if (!self::usableFile($path)) {
            throw new LexicalLookupException(strtoupper($language) . ' country resource is unavailable.');
        }
        if (isset(self::$plainWordCache[$path])) {
            return self::$plainWordCache[$path];
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new LexicalLookupException('Country resource is unreadable.');
        }
        $words = [];
        foreach (preg_split('/\R/u', $contents) ?: [] as $line) {
            $token = self::normalizeToken($line);
            if ($token !== '') {
                $words[$token] = true;
            }
        }
        self::$plainWordCache[$path] = $words;
        return $words;
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
            throw new LexicalLookupException('Generated lexical resource is unreadable.');
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
                    throw new LexicalLookupException('Generated lexical index is invalid.');
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
            throw new LexicalLookupException('Generated lexical index is unavailable.');
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
}
