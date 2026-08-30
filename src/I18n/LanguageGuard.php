<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/ThirdParty/efficient-language-detector/manual_loader.php';
require_once __DIR__ . '/LexicalLanguageChecker.php';

use Nitotm\Eld\EldMode;
use Nitotm\Eld\EldScheme;
use Nitotm\Eld\LanguageDetector;
use Nitotm\Eld\LanguageResult;

final class LanguageValidationException extends InvalidArgumentException
{
    public function __construct(
        string $message,
        public readonly array $invalidWords = [],
        public ?string $fieldKey = null
    ) {
        parent::__construct($message);
    }

    public function withField(string $fieldKey): self
    {
        $this->fieldKey = $fieldKey;
        return $this;
    }
}

/**
 * PT/EN validation coordinator.
 *
 * ELD supplies sentence-level evidence. Word-level evidence comes only from the
 * bundled generated PT-PT/EN-GB resources, externally sourced person names and
 * CLDR country labels. Unknown unquoted tokens are rejected; there is no local
 * technical-word list or capitalization-based escape. Double quotes are the one
 * explicit user escape for custom/technical text.
 */
final class LanguageGuard
{
    private const MODEL = 'large_2_1niz1ni';
    private const COMPONENT_MIN_SCORE = 0.18;
    private const COMPONENT_MIN_GAP = 0.08;
    private const COMPONENT_MIN_RATIO = 1.35;

    private static ?LanguageDetector $detector = null;

    /** @return array<string, mixed> */
    public static function sourceAnalysis(
        string $text,
        string $expectedLanguage,
        ?LexicalLanguageClassifier $lexicalChecker = null
    ): array {
        $text = trim($text);
        $expectedLanguage = $expectedLanguage === 'en' ? 'en' : 'pt';
        $oppositeLanguage = $expectedLanguage === 'en' ? 'pt' : 'en';

        // Quoted content is deliberately outside language validation.
        $analysisText = self::withoutProtectedQuotedSpans($text);
        $tokens = self::tokenDetails($analysisText);
        $sentenceLanguage = count($tokens) >= 3 ? self::confidentLanguage($analysisText) : null;

        $base = [
            'conclusion' => 'ambiguous',
            'expectedLanguage' => $expectedLanguage,
            'sentenceLanguage' => $sentenceLanguage,
            'expectedWords' => [],
            'oppositeWords' => [],
            'sharedWords' => [],
            // Kept for response compatibility. Technical text is no longer
            // inferred; it must be quoted and therefore never reaches this list.
            'technicalWords' => [],
            'unknownWords' => [],
            'likelyMisspellings' => [],
        ];

        if ($tokens === []) {
            $base['conclusion'] = $text === '' ? 'empty' : 'ambiguous';
            return $base;
        }

        if ($lexicalChecker === null) {
            if ($sentenceLanguage === $expectedLanguage) {
                $base['conclusion'] = 'correct';
            } elseif ($sentenceLanguage === $oppositeLanguage) {
                $base['conclusion'] = 'wrong';
            }
            return $base;
        }

        if ($lexicalChecker instanceof LexicalCoverageClassifier
            && !$lexicalChecker->hasFullCoverage()) {
            throw new LexicalLookupException('Generated language resources are incomplete.');
        }

        $normalizedTokens = [];
        $rawTokens = [];
        foreach ($tokens as $token) {
            $normalizedTokens[$token['normalized']] = true;
            $exact = LexicalLanguageChecker::normalizeCaseSensitiveToken($token['raw']);
            if ($exact !== '') {
                $rawTokens[$exact] = true;
            }
        }

        $classifications = $lexicalChecker->classifyTokens(array_keys($normalizedTokens));

        $countryEntities = [];
        if ($lexicalChecker instanceof LexicalEntityClassifier) {
            $countryEntities = $lexicalChecker->classifyEntityTokens(array_keys($normalizedTokens));
        }

        $people = [];
        if ($lexicalChecker instanceof LexicalPersonClassifier) {
            $people = $lexicalChecker->classifyPersonTokens(array_keys($rawTokens));
        }

        $caseSensitiveEnglish = [];
        if ($lexicalChecker instanceof LexicalCaseSensitiveClassifier) {
            $caseSensitiveEnglish = $lexicalChecker->classifyCaseSensitiveTokens(array_keys($rawTokens));
        }

        foreach ($tokens as $token) {
            $normalized = $token['normalized'];
            $display = $token['raw'];
            $exact = LexicalLanguageChecker::normalizeCaseSensitiveToken($display);
            $classification = $classifications[$normalized] ?? 'unknown';

            // Country semantics win over person-name ambiguity: countries are
            // language-bearing and must translate (Spain <-> Espanha).
            if (($countryEntities[$normalized] ?? null) === 'country') {
                self::appendLanguageEvidence($base, $display, $classification, $expectedLanguage);
                continue;
            }

            // A sourced person name is neutral in both languages and preserved.
            if (isset($people[$exact])) {
                $base['sharedWords'][] = $display;
                continue;
            }

            if ($classification === 'shared') {
                $base['sharedWords'][] = $display;
                continue;
            }
            if ($classification === 'pt_only' || $classification === 'en_only') {
                self::appendLanguageEvidence($base, $display, $classification, $expectedLanguage);
                continue;
            }

            // CSpell's keep-case resource is English lexical evidence, not an
            // automatic neutral/proper-name escape. This is what makes I valid
            // while leaving arbitrary capitalized garbage invalid.
            if (isset($caseSensitiveEnglish[$exact])) {
                self::appendLanguageEvidence($base, $display, 'en_only', $expectedLanguage);
                continue;
            }

            $base['unknownWords'][] = $display;
        }

        foreach (['expectedWords', 'oppositeWords', 'sharedWords', 'unknownWords'] as $key) {
            $base[$key] = self::uniqueWords($base[$key]);
        }

        if ($base['expectedWords'] !== [] && $base['oppositeWords'] !== []) {
            $base['conclusion'] = 'mixed';
            return $base;
        }
        if ($base['oppositeWords'] !== []) {
            $base['conclusion'] = 'wrong';
            return $base;
        }
        if ($base['unknownWords'] !== []) {
            $base['conclusion'] = 'unknown';
            return $base;
        }
        if ($base['expectedWords'] !== []) {
            $base['conclusion'] = 'correct';
            return $base;
        }
        if ($sentenceLanguage === $expectedLanguage) {
            $base['conclusion'] = 'correct';
            return $base;
        }
        if ($sentenceLanguage === $oppositeLanguage) {
            $base['conclusion'] = 'wrong';
            return $base;
        }

        $base['conclusion'] = 'ambiguous';
        return $base;
    }

    /** @param array<string, mixed> $base */
    private static function appendLanguageEvidence(
        array &$base,
        string $display,
        string $classification,
        string $expectedLanguage
    ): void {
        $tokenLanguage = $classification === 'pt_only' ? 'pt' : 'en';
        if ($classification === 'shared') {
            $base['sharedWords'][] = $display;
        } elseif ($tokenLanguage === $expectedLanguage) {
            $base['expectedWords'][] = $display;
        } else {
            $base['oppositeWords'][] = $display;
        }
    }

    /** @return array<string, mixed> */
    public static function validateSource(
        string $text,
        string $expectedLanguage,
        ?LexicalLanguageClassifier $lexicalChecker = null
    ): array {
        $analysis = self::sourceAnalysis($text, $expectedLanguage, $lexicalChecker);
        $expectedLanguage = $analysis['expectedLanguage'];
        $oppositeLanguage = $expectedLanguage === 'en' ? 'pt' : 'en';

        if ($analysis['conclusion'] === 'unknown') {
            $words = $analysis['unknownWords'];
            $quoted = self::quotedWords($words);
            $message = count($words) === 1
                ? ($expectedLanguage === 'en'
                    ? "Not saved: unrecognized word — {$quoted}."
                    : "Não guardado: palavra não reconhecida — {$quoted}.")
                : ($expectedLanguage === 'en'
                    ? "Not saved: unrecognized words — {$quoted}."
                    : "Não guardado: palavras não reconhecidas — {$quoted}.");
            throw new LanguageValidationException($message, $words);
        }

        if ($analysis['conclusion'] === 'mixed') {
            $words = $analysis['oppositeWords'];
            $quoted = self::quotedWords($words);
            $message = $expectedLanguage === 'en'
                ? "Not saved: text mixes EN and PT — PT word(s): {$quoted}."
                : "Não guardado: o texto mistura PT e EN — palavra(s) EN: {$quoted}.";
            throw new LanguageValidationException($message, $words);
        }

        if ($analysis['conclusion'] === 'wrong') {
            $words = $analysis['oppositeWords'];
            $quoted = self::quotedWords($words);
            $label = strtoupper($oppositeLanguage);
            $message = $expectedLanguage === 'en'
                ? "Not saved: text is clearly {$label}" . ($quoted !== '' ? " — {$quoted}." : '.')
                : "Não guardado: texto claramente {$label}" . ($quoted !== '' ? " — {$quoted}." : '.');
            throw new LanguageValidationException($message, $words);
        }

        return $analysis;
    }

    public static function assertExpectedLanguage(
        string $text,
        string $expectedLanguage,
        ?LexicalLanguageClassifier $lexicalChecker = null
    ): void {
        self::validateSource($text, $expectedLanguage, $lexicalChecker);
    }

    public static function confidentLanguage(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $result = self::detect($text);
        if (($result->language === 'pt' || $result->language === 'en') && $result->isReliable()) {
            return $result->language;
        }

        $scores = $result->scores();
        foreach (['pt', 'en'] as $language) {
            $other = $language === 'pt' ? 'en' : 'pt';
            if (self::scoreDominates($scores, $language, $other)) {
                return $language;
            }
        }
        return null;
    }

    public static function confidentSentenceLanguage(string $text): ?string
    {
        return count(self::tokenDetails($text)) >= 3 ? self::confidentLanguage($text) : null;
    }

    private static function withoutProtectedQuotedSpans(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $stripped = preg_replace('/"[^"\r\n]*"|“[^”\r\n]*”/u', ' ', $text);
        return is_string($stripped) ? trim($stripped) : $text;
    }

    /** @return array<int, array{raw:string, normalized:string}> */
    private static function tokenDetails(string $text): array
    {
        if ($text === '') {
            return [];
        }
        preg_match_all("/[\\p{L}]+(?:[’'\\-][\\p{L}]+)*/u", $text, $matches);
        $tokens = [];
        foreach (($matches[0] ?? []) as $raw) {
            $raw = (string) $raw;
            $normalized = LexicalLanguageChecker::normalizeToken($raw);
            if ($normalized !== '') {
                $tokens[] = ['raw' => $raw, 'normalized' => $normalized];
            }
        }
        return $tokens;
    }

    /** @param string[] $words @return string[] */
    private static function uniqueWords(array $words): array
    {
        $seen = [];
        $unique = [];
        foreach ($words as $word) {
            $key = mb_strtolower((string) $word, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = (string) $word;
        }
        return $unique;
    }

    /** @param string[] $words */
    private static function quotedWords(array $words): string
    {
        return implode(', ', array_map(
            static fn(string $word): string => '“' . $word . '”',
            $words
        ));
    }

    private static function detector(): LanguageDetector
    {
        if (self::$detector === null) {
            self::$detector = new LanguageDetector(
                self::MODEL,
                EldScheme::ISO639_1,
                EldMode::MODE_ARRAY
            );
        }
        return self::$detector;
    }

    private static function detect(string $text): LanguageResult
    {
        return self::detector()->detect($text);
    }

    /** @param array<string, float> $scores */
    private static function scoreDominates(array $scores, string $language, string $otherLanguage): bool
    {
        $score = (float) ($scores[$language] ?? 0.0);
        $otherScore = (float) ($scores[$otherLanguage] ?? 0.0);
        if ($score < self::COMPONENT_MIN_SCORE) return false;
        if (($score - $otherScore) < self::COMPONENT_MIN_GAP) return false;
        if ($otherScore > 0.0 && ($score / $otherScore) < self::COMPONENT_MIN_RATIO) return false;
        return true;
    }
}
