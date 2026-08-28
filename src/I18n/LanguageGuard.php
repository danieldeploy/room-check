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
 * Language validation coordinator.
 *
 * Lexical evidence decides individual PT/EN words. The statistical detector is
 * retained only as sentence-level context/diagnostics; it is no longer asked to
 * behave like a dictionary for words such as "extinguisher".
 */
final class LanguageGuard
{
    private const MODEL = 'large_2_1niz1ni';
    private const COMPONENT_MIN_SCORE = 0.18;
    private const COMPONENT_MIN_GAP = 0.08;
    private const COMPONENT_MIN_RATIO = 1.35;

    private static ?LanguageDetector $detector = null;

    /**
     * @return array{
     *   conclusion:string,
     *   expectedLanguage:string,
     *   sentenceLanguage:?string,
     *   expectedWords:array<int,string>,
     *   oppositeWords:array<int,string>,
     *   sharedWords:array<int,string>,
     *   technicalWords:array<int,string>,
     *   unknownWords:array<int,string>
     * }
     */
    public static function sourceAnalysis(
        string $text,
        string $expectedLanguage,
        ?LexicalLanguageClassifier $lexicalChecker = null
    ): array {
        $text = trim($text);
        $expectedLanguage = $expectedLanguage === 'en' ? 'en' : 'pt';
        $oppositeLanguage = $expectedLanguage === 'en' ? 'pt' : 'en';
        $tokens = self::tokenDetails($text);
        $sentenceLanguage = count($tokens) >= 3 ? self::confidentLanguage($text) : null;

        $base = [
            'conclusion' => 'ambiguous',
            'expectedLanguage' => $expectedLanguage,
            'sentenceLanguage' => $sentenceLanguage,
            'expectedWords' => [],
            'oppositeWords' => [],
            'sharedWords' => [],
            'technicalWords' => [],
            'unknownWords' => [],
        ];
        if ($tokens === []) {
            $base['conclusion'] = 'empty';
            return $base;
        }

        // Compatibility fallback for non-persistent legacy callers. Production
        // ContentTranslator always supplies the lexical checker.
        if ($lexicalChecker === null) {
            if ($sentenceLanguage === $expectedLanguage) {
                $base['conclusion'] = 'correct';
            } elseif ($sentenceLanguage === $oppositeLanguage) {
                $base['conclusion'] = 'wrong';
            }
            return $base;
        }

        // Identifiers such as HVAC, WiFi, My2N and ZKTeco are neutral technical
        // vocabulary regardless of whether a dictionary happens to list them
        // under one language. Ordinary Titlecase words (House) are not included.
        $lexicalTokens = [];
        foreach ($tokens as $token) {
            if (self::looksTechnicalIdentifier($token['raw'])) {
                $base['technicalWords'][] = $token['raw'];
                continue;
            }
            $lexicalTokens[$token['normalized']] = true;
        }
        $classifications = $lexicalTokens === []
            ? []
            : $lexicalChecker->classifyTokens(array_keys($lexicalTokens));

        foreach ($tokens as $token) {
            $normalized = $token['normalized'];
            $display = $token['raw'];
            if (self::looksTechnicalIdentifier($display)) {
                continue;
            }
            $classification = $classifications[$normalized] ?? 'unknown';

            if ($classification === 'shared') {
                $base['sharedWords'][] = $display;
                continue;
            }
            if ($classification === 'unknown') {
                $base['unknownWords'][] = $display;
                continue;
            }

            $tokenLanguage = $classification === 'pt_only' ? 'pt' : 'en';
            if ($tokenLanguage === $expectedLanguage) {
                $base['expectedWords'][] = $display;
            } else {
                $base['oppositeWords'][] = $display;
            }
        }

        foreach (['expectedWords', 'oppositeWords', 'sharedWords', 'technicalWords', 'unknownWords'] as $key) {
            $base[$key] = self::uniqueWords($base[$key]);
        }

        if ($base['unknownWords'] !== []) {
            $base['conclusion'] = 'unknown';
            return $base;
        }
        if ($base['expectedWords'] !== [] && $base['oppositeWords'] !== []) {
            $base['conclusion'] = 'mixed';
            return $base;
        }
        if ($base['oppositeWords'] !== []) {
            $base['conclusion'] = 'wrong';
            return $base;
        }
        if ($base['expectedWords'] !== []) {
            $base['conclusion'] = 'correct';
            return $base;
        }

        // Only shared words or technical identifiers remain. They are accepted
        // as ambiguous instead of being invented into either language by the
        // statistical detector.
        $base['conclusion'] = 'ambiguous';
        return $base;
    }

    /**
     * Returns the analysis when valid and throws a clear user-facing exception
     * for wrong-language, mixed-language or unrecognized ordinary words.
     */
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

    /**
     * Sentence-level statistical context only. It is intentionally not used as
     * a per-word dictionary.
     */
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

    /**
     * Legacy helper kept for callers that only need sentence context. Short
     * phrases are not classified statistically anymore; lexical checking owns
     * that job.
     */
    public static function confidentSentenceLanguage(string $text): ?string
    {
        return count(self::tokenDetails($text)) >= 3 ? self::confidentLanguage($text) : null;
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
            if ($normalized === '') {
                continue;
            }
            $tokens[] = ['raw' => $raw, 'normalized' => $normalized];
        }
        return $tokens;
    }

    private static function looksTechnicalIdentifier(string $token): bool
    {
        $length = mb_strlen($token, 'UTF-8');
        if ($length <= 2) {
            return true;
        }
        if (preg_match('/\d/u', $token) === 1) {
            return true;
        }
        if (preg_match('/^[\p{Lu}]{2,}$/u', $token) === 1) {
            return true;
        }
        // Camel/mixed case brands and identifiers: WiFi, ZKTeco, CloudBeds.
        if (preg_match('/\p{Ll}.*\p{Lu}|\p{Lu}.*\p{Lu}/u', $token) === 1) {
            return true;
        }
        return false;
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
        if ($score < self::COMPONENT_MIN_SCORE) {
            return false;
        }
        if (($score - $otherScore) < self::COMPONENT_MIN_GAP) {
            return false;
        }
        if ($otherScore > 0.0 && ($score / $otherScore) < self::COMPONENT_MIN_RATIO) {
            return false;
        }
        return true;
    }
}
