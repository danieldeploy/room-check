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
 * Sentence-level evidence comes from the bundled ELD model and decisive normal
 * word evidence comes from the validated local PT-PT + EN-GB resources. The
 * upstream English dictionary is `keep-case`; exact-case entries are recognized
 * as real neutral tokens so capitalization-sensitive grammar, names and acronyms
 * are not lost by lowercase lookup and do not by themselves decide the sentence
 * language. With all generated resources available, an ordinary token absent
 * from every validated source is still rejected deterministically. MyMemory
 * never decides the source language.
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

        // Text explicitly enclosed in double quotes is user-protected content:
        // it is neither PT/EN evidence nor an unknown-word candidate.
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
            'technicalWords' => [],
            'unknownWords' => [],
            'likelyMisspellings' => [],
        ];
        if ($tokens === []) {
            // A field containing only protected quoted content is valid neutral
            // content, not an empty field.
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

        $hasFullCoverage = $lexicalChecker instanceof LexicalCoverageClassifier
            && $lexicalChecker->hasFullCoverage();

        $lexicalTokens = [];
        foreach ($tokens as $token) {
            $lexicalTokens[$token['normalized']] = true;
        }
        $classifications = $lexicalChecker->classifyTokens(array_keys($lexicalTokens));

        // CSpell's EN-GB source explicitly uses keep-case. Ask for exact source
        // membership with the original token spelling before declaring an
        // otherwise unknown token invalid. These matches are neutral evidence:
        // they prove the spelling exists but names/acronyms do not force EN.
        $caseSensitiveClassifications = [];
        if ($lexicalChecker instanceof LexicalCaseSensitiveClassifier) {
            $rawTokens = [];
            foreach ($tokens as $token) {
                $raw = LexicalLanguageChecker::normalizeCaseSensitiveToken($token['raw']);
                if ($raw !== '') {
                    $rawTokens[$raw] = true;
                }
            }
            $caseSensitiveClassifications = $lexicalChecker->classifyCaseSensitiveTokens(array_keys($rawTokens));
        }

        foreach ($tokens as $token) {
            $normalized = $token['normalized'];
            $display = $token['raw'];
            $classification = $classifications[$normalized] ?? 'unknown';

            if ($classification === 'shared') {
                $base['sharedWords'][] = $display;
                continue;
            }
            if ($classification === 'unknown') {
                $exact = LexicalLanguageChecker::normalizeCaseSensitiveToken($display);
                if (isset($caseSensitiveClassifications[$exact])) {
                    $base['sharedWords'][] = $display;
                    continue;
                }
                $nearMatch = $lexicalChecker instanceof LexicalNearMatchClassifier
                    ? $lexicalChecker->likelyMisspelling($display)
                    : null;
                if ($nearMatch !== null) {
                    $base['unknownWords'][] = $display;
                    $base['likelyMisspellings'][] = $display;
                    continue;
                }
                if (self::looksTechnicalIdentifier($display)) {
                    $base['technicalWords'][] = $display;
                    continue;
                }
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

        foreach (['expectedWords', 'oppositeWords', 'sharedWords', 'technicalWords', 'unknownWords', 'likelyMisspellings'] as $key) {
            $base[$key] = self::uniqueWords($base[$key]);
        }

        // Decisive opposite-language lexical evidence always wins.
        if ($base['expectedWords'] !== [] && $base['oppositeWords'] !== []) {
            $base['conclusion'] = 'mixed';
            return $base;
        }
        if ($base['oppositeWords'] !== []) {
            $base['conclusion'] = 'wrong';
            return $base;
        }

        // Preserve the useful one/two-edit corruption guard: danosht, YAHOOX…
        if ($base['likelyMisspellings'] !== []) {
            $base['unknownWords'] = $base['likelyMisspellings'];
            $base['conclusion'] = 'unknown';
            return $base;
        }

        if ($base['unknownWords'] !== []) {
            // Once every validated resource is present, a normal token absent
            // from PT, EN, exact-case EN and the explicit technical list is
            // genuinely unresolved and must not inherit surrounding context.
            if ($hasFullCoverage) {
                $base['conclusion'] = 'unknown';
                return $base;
            }

            // Safe recovery fallback for a partial deployment / missing full
            // resources. Short fragments are still blocked because they caused
            // explicit HAR failures such as "danos.s" and "danos.sr".
            $shortUnknown = array_values(array_filter(
                $base['unknownWords'],
                static fn(string $word): bool => mb_strlen($word, 'UTF-8') <= 2
            ));
            if ($shortUnknown !== []) {
                $base['unknownWords'] = $shortUnknown;
                $base['conclusion'] = 'unknown';
                return $base;
            }

            $suspicious = array_values(array_filter(
                $base['unknownWords'],
                static fn(string $word): bool => self::looksGibberishToken($word)
            ));
            if ($suspicious !== []) {
                $base['unknownWords'] = $suspicious;
                $base['conclusion'] = 'unknown';
                return $base;
            }
            if (count($tokens) === 1) {
                $base['conclusion'] = 'unknown';
                return $base;
            }
            if ($sentenceLanguage === $oppositeLanguage) {
                $base['conclusion'] = 'wrong';
                $base['oppositeWords'] = $base['unknownWords'];
                return $base;
            }
            if ($sentenceLanguage === $expectedLanguage || $base['expectedWords'] !== []) {
                $base['technicalWords'] = self::uniqueWords(array_merge(
                    $base['technicalWords'],
                    $base['unknownWords']
                ));
                $base['unknownWords'] = [];
            } else {
                $base['conclusion'] = 'unknown';
                return $base;
            }
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

        // Only complete double-quoted spans are protected. An unmatched quote
        // keeps its text in normal validation.
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

    private static function looksTechnicalIdentifier(string $token): bool
    {
        if (LexicalLanguageChecker::isTechnicalNeutral($token)) {
            return true;
        }
        if (preg_match('/\d/u', $token) === 1) {
            return true;
        }
        // Narrow brand/identifier fallback. ALL-CAPS and arbitrary short
        // fragments are intentionally not accepted implicitly.
        return preg_match('/^(?=.*\p{Ll})(?=.*\p{Lu})[\p{L}\p{N}_-]+$/u', $token) === 1;
    }

    private static function looksGibberishToken(string $token): bool
    {
        $word = mb_strtolower($token, 'UTF-8');
        if (mb_strlen($word, 'UTF-8') < 4) {
            return false;
        }
        if (preg_match('/[^aeiouáàâãéêíóôõúü]{5,}/u', $word) === 1) {
            return true;
        }
        if (preg_match('/(.)\1{3,}/u', $word) === 1) {
            return true;
        }
        $vowels = preg_match_all('/[aeiouáàâãéêíóôõúü]/u', $word);
        $letters = preg_match_all('/\p{L}/u', $word);
        return $letters >= 7 && $vowels <= 1;
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
