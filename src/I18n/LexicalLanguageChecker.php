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
    /**
     * @return array{candidate:string,distance:int,classification:string}|null
     */
    public function likelyMisspelling(string $token): ?array;
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
final class LexicalLanguageChecker implements LexicalLanguageClassifier, LexicalNearMatchClassifier
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

    /**
     * Unknown words that are only one or two edits away from a known PT, EN or
     * technical token are almost always typing corruptions, not rare technical
     * vocabulary. This catches cases such as danosht -> danos and YAHOOX -> yahoo
     * without making the general unknown-word heuristic more aggressive.
     *
     * @return array{candidate:string,distance:int,classification:string}|null
     */
    public function likelyMisspelling(string $token): ?array
    {
        self::loadLexicons($this->config);
        $token = self::normalizeToken($token);
        $length = mb_strlen($token, 'UTF-8');
        if ($token === '' || $length < 4 || $length > self::MAX_TOKEN_LENGTH) {
            return null;
        }

        if (isset(self::$technicalWords[$token]) || isset(self::$ptWords[$token]) || isset(self::$enWords[$token])) {
            return null;
        }

        // One edit is already strong evidence on short words. From five letters
        // onward, allow two edits so one/two accidental suffix letters are caught.
        $maxDistance = $length >= 5 ? 2 : 1;
        $best = null;

        $known = self::$ptWords + self::$enWords + self::$technicalWords;
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
                : (isset(self::$ptWords[$candidate]) && isset(self::$enWords[$candidate])
                    ? 'shared'
                    : (isset(self::$ptWords[$candidate]) ? 'pt_only' : 'en_only'));
            $best = [
                'candidate' => $candidate,
                'distance' => $distance,
                'classification' => $classification,
            ];

            if ($distance === 1) {
                // Distance 1 is the strongest possible non-exact match.
                break;
            }
        }

        return $best;
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

    /**
     * UTF-8 aware Levenshtein distance with an early length cutoff. The local
     * lexicons are small, so this remains deterministic and fast on each new word.
     */
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
