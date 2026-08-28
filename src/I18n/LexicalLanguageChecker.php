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
 * Local PT-PT / en-GB lexical verifier.
 *
 * The spell dictionaries are vendored with the application, so language
 * verification never depends on Wiktionary, cURL, DNS or another remote
 * service. Hunspell affix rules are evaluated in reverse so common inflected
 * forms (plurals, verb forms, prefixes, etc.) are recognised without expanding
 * the dictionaries into a much larger word list.
 */
final class LexicalLanguageChecker implements LexicalLanguageClassifier
{
    private const MAX_TOKEN_LENGTH = 190;

    /** @var array<string, LocalHunspellLexicon> */
    private static array $lexicons = [];

    /** @var array<string, string> */
    private static array $classificationCache = [];

    private string $lexiconDirectory;

    /**
     * PDO remains an optional compatibility argument because ContentTranslator
     * historically constructed the lexical checker with the application PDO.
     * No lexical database table is created or read anymore.
     */
    public function __construct(?PDO $pdo = null, array $config = [])
    {
        unset($pdo);
        $directory = trim((string) ($config['directory'] ?? ''));
        $this->lexiconDirectory = $directory !== ''
            ? rtrim($directory, DIRECTORY_SEPARATOR)
            : __DIR__ . DIRECTORY_SEPARATOR . 'Lexicons';
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

        if ($normalized === []) {
            return [];
        }

        $pt = $this->lexicon('pt');
        $en = $this->lexicon('en');
        $results = [];

        foreach (array_keys($normalized) as $token) {
            if (isset(self::$classificationCache[$token])) {
                $results[$token] = self::$classificationCache[$token];
                continue;
            }

            $hasPt = $pt->contains($token);
            $hasEn = $en->contains($token);
            $classification = match (true) {
                $hasPt && $hasEn => 'shared',
                $hasPt => 'pt_only',
                $hasEn => 'en_only',
                default => 'unknown',
            };
            self::$classificationCache[$token] = $classification;
            $results[$token] = $classification;
        }

        return $results;
    }

    private function lexicon(string $language): LocalHunspellLexicon
    {
        $language = $language === 'en' ? 'en' : 'pt';
        $cacheKey = $language . '|' . $this->lexiconDirectory;
        if (isset(self::$lexicons[$cacheKey])) {
            return self::$lexicons[$cacheKey];
        }

        $baseName = $language === 'en' ? 'en_GB' : 'pt_PT';
        $dictionary = $this->lexiconDirectory . DIRECTORY_SEPARATOR . $baseName . '.dic';
        $affixes = $this->lexiconDirectory . DIRECTORY_SEPARATOR . $baseName . '.aff';

        if (!is_file($dictionary) || !is_readable($dictionary)
            || !is_file($affixes) || !is_readable($affixes)) {
            throw new LexicalLookupException(
                "Local {$baseName} spell lexicon is unavailable."
            );
        }

        self::$lexicons[$cacheKey] = LocalHunspellLexicon::load($dictionary, $affixes);
        return self::$lexicons[$cacheKey];
    }

    public static function normalizeToken(string $token): string
    {
        $token = str_replace(['’', '‘'], "'", trim($token));
        return mb_strtolower($token, 'UTF-8');
    }
}

/**
 * Minimal read-only Hunspell engine for lexical membership checks.
 *
 * It intentionally implements only what language verification needs: direct
 * dictionary entries, one prefix, one suffix, and Hunspell cross-products.
 * It does not provide spelling suggestions or mutate dictionary data.
 */
final class LocalHunspellLexicon
{
    /** @var array<string, string> normalized stem => raw Hunspell flags */
    private array $entries = [];

    /** @var array<int, array{flag:string,cross:bool,strip:string,add:string,condition:string}> */
    private array $prefixRules = [];

    /** @var array<int, array{flag:string,cross:bool,strip:string,add:string,condition:string}> */
    private array $suffixRules = [];

    /** @var array<string, bool> */
    private array $membershipCache = [];

    private string $flagMode = 'char';

    private function __construct()
    {
    }

    public static function load(string $dictionaryPath, string $affixPath): self
    {
        $lexicon = new self();
        $lexicon->parseAffixes($affixPath);
        $lexicon->parseDictionary($dictionaryPath);
        return $lexicon;
    }

    public function contains(string $token): bool
    {
        $token = LexicalLanguageChecker::normalizeToken($token);
        if ($token === '') {
            return false;
        }
        if (array_key_exists($token, $this->membershipCache)) {
            return $this->membershipCache[$token];
        }

        if (isset($this->entries[$token])) {
            return $this->membershipCache[$token] = true;
        }

        foreach ($this->suffixRules as $rule) {
            $base = $this->reverseSuffix($token, $rule);
            if ($base !== null
                && $this->entryHasFlag($base, $rule['flag'])
                && $this->conditionMatches($base, $rule['condition'], false)) {
                return $this->membershipCache[$token] = true;
            }
        }

        foreach ($this->prefixRules as $rule) {
            $base = $this->reversePrefix($token, $rule);
            if ($base !== null
                && $this->entryHasFlag($base, $rule['flag'])
                && $this->conditionMatches($base, $rule['condition'], true)) {
                return $this->membershipCache[$token] = true;
            }
        }

        // Hunspell cross-products allow one cross-enabled prefix and one
        // cross-enabled suffix when the base entry carries both flags.
        foreach ($this->suffixRules as $suffix) {
            if (!$suffix['cross']) {
                continue;
            }
            $withoutSuffix = $this->reverseSuffix($token, $suffix);
            if ($withoutSuffix === null) {
                continue;
            }
            foreach ($this->prefixRules as $prefix) {
                if (!$prefix['cross']) {
                    continue;
                }
                $base = $this->reversePrefix($withoutSuffix, $prefix);
                if ($base === null
                    || !$this->entryHasFlag($base, $suffix['flag'])
                    || !$this->entryHasFlag($base, $prefix['flag'])
                    || !$this->conditionMatches($base, $suffix['condition'], false)
                    || !$this->conditionMatches($base, $prefix['condition'], true)) {
                    continue;
                }
                return $this->membershipCache[$token] = true;
            }
        }

        return $this->membershipCache[$token] = false;
    }

    private function parseAffixes(string $path): void
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new LexicalLookupException("Cannot read local affix file: {$path}");
        }

        /** @var array<string, bool> $crossByKey */
        $crossByKey = [];
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim(self::stripBom($line));
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                $parts = preg_split('/\s+/u', $line) ?: [];
                if (($parts[0] ?? '') === 'FLAG' && isset($parts[1])) {
                    $mode = strtolower((string) $parts[1]);
                    $this->flagMode = match ($mode) {
                        'num' => 'num',
                        'long' => 'long',
                        default => 'char',
                    };
                    continue;
                }

                $type = (string) ($parts[0] ?? '');
                if (($type !== 'PFX' && $type !== 'SFX') || !isset($parts[1], $parts[2], $parts[3])) {
                    continue;
                }

                // Header: PFX/SFX <flag> <Y|N> <count>
                if (($parts[2] === 'Y' || $parts[2] === 'N') && ctype_digit((string) $parts[3])) {
                    $crossByKey[$type . '|' . $parts[1]] = $parts[2] === 'Y';
                    continue;
                }

                // Rule: PFX/SFX <flag> <strip> <add[/continuation]> <condition>
                if (!isset($parts[4])) {
                    continue;
                }
                $flag = (string) $parts[1];
                $strip = $parts[2] === '0' ? '' : (string) $parts[2];
                $addWithFlags = (string) $parts[3];
                $addParts = preg_split('/(?<!\\)\//u', $addWithFlags, 2) ?: [$addWithFlags];
                $add = ($addParts[0] ?? '') === '0' ? '' : (string) ($addParts[0] ?? '');
                $condition = (string) $parts[4];
                $rule = [
                    'flag' => $flag,
                    'cross' => $crossByKey[$type . '|' . $flag] ?? false,
                    'strip' => self::normalizeAffix($strip),
                    'add' => self::normalizeAffix($add),
                    'condition' => self::normalizeCondition($condition),
                ];
                if ($type === 'PFX') {
                    $this->prefixRules[] = $rule;
                } else {
                    $this->suffixRules[] = $rule;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private function parseDictionary(string $path): void
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new LexicalLookupException("Cannot read local dictionary file: {$path}");
        }

        $firstContentLine = true;
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim(self::stripBom($line));
                if ($line === '') {
                    continue;
                }
                if ($firstContentLine && ctype_digit($line)) {
                    $firstContentLine = false;
                    continue;
                }
                $firstContentLine = false;

                $lexicalPart = preg_split('/\s+/u', $line, 2)[0] ?? '';
                if ($lexicalPart === '') {
                    continue;
                }
                $wordAndFlags = preg_split('/(?<!\\)\//u', $lexicalPart, 2) ?: [$lexicalPart];
                $word = str_replace(['\\/', '\\\\'], ['/', '\\'], (string) ($wordAndFlags[0] ?? ''));
                if ($word === '') {
                    continue;
                }

                // Proper names/acronyms are not evidence that a lowercase token
                // belongs to a language. Common spell words are stored lowercase
                // in both dictionaries; excluding capital-only entries prevents
                // cases such as acronyms from turning gibberish into EN/PT.
                if (self::containsLetter($word) && mb_strtolower($word, 'UTF-8') !== $word) {
                    continue;
                }

                $normalized = LexicalLanguageChecker::normalizeToken($word);
                if ($normalized === '') {
                    continue;
                }
                $flags = (string) ($wordAndFlags[1] ?? '');
                if (!isset($this->entries[$normalized])) {
                    $this->entries[$normalized] = $flags;
                } elseif ($flags !== '') {
                    $this->entries[$normalized] .= $flags;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param array{strip:string,add:string} $rule */
    private function reverseSuffix(string $token, array $rule): ?string
    {
        $add = $rule['add'];
        if ($add !== '' && !self::endsWith($token, $add)) {
            return null;
        }
        $stem = $add === ''
            ? $token
            : mb_substr($token, 0, mb_strlen($token, 'UTF-8') - mb_strlen($add, 'UTF-8'), 'UTF-8');
        return $stem . $rule['strip'];
    }

    /** @param array{strip:string,add:string} $rule */
    private function reversePrefix(string $token, array $rule): ?string
    {
        $add = $rule['add'];
        if ($add !== '' && !str_starts_with($token, $add)) {
            return null;
        }
        $tail = $add === ''
            ? $token
            : mb_substr($token, mb_strlen($add, 'UTF-8'), null, 'UTF-8');
        return $rule['strip'] . $tail;
    }

    private function entryHasFlag(string $base, string $flag): bool
    {
        $base = LexicalLanguageChecker::normalizeToken($base);
        if (!isset($this->entries[$base])) {
            return false;
        }
        $flags = $this->splitFlags($this->entries[$base]);
        return in_array($flag, $flags, true);
    }

    /** @return string[] */
    private function splitFlags(string $flags): array
    {
        if ($flags === '') {
            return [];
        }
        if ($this->flagMode === 'num') {
            return array_values(array_filter(explode(',', $flags), static fn(string $flag): bool => $flag !== ''));
        }

        $characters = preg_split('//u', $flags, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($this->flagMode !== 'long') {
            return $characters;
        }

        $result = [];
        for ($index = 0; $index < count($characters); $index += 2) {
            $result[] = $characters[$index] . ($characters[$index + 1] ?? '');
        }
        return $result;
    }

    private function conditionMatches(string $base, string $condition, bool $prefix): bool
    {
        if ($condition === '' || $condition === '.') {
            return true;
        }
        $condition = str_replace('~', '\\~', $condition);
        $pattern = $prefix ? '~^' . $condition . '~u' : '~' . $condition . '$~u';
        return @preg_match($pattern, $base) === 1;
    }

    private static function normalizeAffix(string $value): string
    {
        $value = str_replace(['’', '‘'], "'", $value);
        return mb_strtolower($value, 'UTF-8');
    }

    private static function normalizeCondition(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    private static function endsWith(string $value, string $suffix): bool
    {
        if ($suffix === '') {
            return true;
        }
        $suffixLength = mb_strlen($suffix, 'UTF-8');
        if ($suffixLength > mb_strlen($value, 'UTF-8')) {
            return false;
        }
        return mb_substr($value, -$suffixLength, null, 'UTF-8') === $suffix;
    }

    private static function containsLetter(string $value): bool
    {
        return preg_match('/\p{L}/u', $value) === 1;
    }

    private static function stripBom(string $value): string
    {
        return preg_replace('/^\x{FEFF}/u', '', $value) ?? $value;
    }
}
