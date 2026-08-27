<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/ThirdParty/efficient-language-detector/manual_loader.php';

use Nitotm\Eld\EldMode;
use Nitotm\Eld\EldScheme;
use Nitotm\Eld\LanguageDetector;
use Nitotm\Eld\LanguageResult;

/**
 * Project-wide server-side language guard for user-authored bilingual content.
 *
 * Detection is statistical (Nito-ELD), restricted to Portuguese and English.
 * It validates both the complete text and short components so a mostly-English
 * sentence containing a Portuguese insertion (or the inverse) is not silently
 * accepted. Ambiguous/neutral technical text remains allowed.
 */
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

final class LanguageGuard
{
    private const MODEL = 'large_2_1niz1ni';

    // Calibrated against the vendored EN/PT subset. A short component is only
    // considered opposite-language evidence when all three conditions hold.
    private const COMPONENT_MIN_SCORE = 0.18;
    private const COMPONENT_MIN_GAP = 0.08;
    private const COMPONENT_MIN_RATIO = 1.35;
    private const MAX_COMPONENT_TOKENS = 40;

    /**
     * Technical, brand, proper-name and shared loan terms that are deliberately
     * language-neutral in the app. This is not a language vocabulary list; it
     * prevents machine/product names from being treated as linguistic evidence.
     */
    private const NEUTRAL = [
        'wifi', 'wi-fi', 'sip', 'my2n', 'zkaccess', 'cloudbeds', 'whatsapp', 'api',
        'pin', 'tv', 'usb', 'qr', 'café', 'hotel', 'hostel', 'online', 'offline', 'item',
        'airbnb', 'booking', 'netflix', 'welcome',
    ];

    private static ?LanguageDetector $detector = null;

    public static function assertExpectedLanguage(string $text, string $expectedLanguage): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $expectedLanguage = $expectedLanguage === 'en' ? 'en' : 'pt';
        $oppositeLanguage = $expectedLanguage === 'en' ? 'pt' : 'en';
        $naturalText = self::naturalText($text);
        if ($naturalText === '') {
            return;
        }

        // 1. Validate the complete natural-language text after removing neutral
        // technical/brand/loan tokens so they cannot distort the language score.
        $whole = self::detect($naturalText);
        $invalidWords = self::oppositeWords($text, $expectedLanguage);
        if ($whole->language === $oppositeLanguage && $whole->isReliable()) {
            if ($invalidWords === []) {
                $invalidWords = array_slice(self::naturalTokens($text), 0, 20);
            }
            self::throwMismatch($expectedLanguage, $invalidWords);
        }

        // 2. Validate individual words and short components. Return the actual
        // offending words so the client can highlight them without discarding
        // the user's unsaved edit.
        if ($invalidWords !== []) {
            self::throwMismatch($expectedLanguage, $invalidWords);
        }
    }

    /**
     * Returns pt/en only when the statistical result is sufficiently strong;
     * null means ambiguous/neutral and must not block a save.
     */
    public static function confidentLanguage(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $naturalText = self::naturalText($text);
        if ($naturalText === '') {
            return null;
        }

        $result = self::detect($naturalText);
        if (($result->language === 'pt' || $result->language === 'en') && $result->isReliable()) {
            return $result->language;
        }

        // Short values are often intentionally marked non-reliable by ELD even
        // when the PT/EN score separation is clear. Apply the same conservative
        // component thresholds used by the mixed-language guard.
        $scores = $result->scores();
        foreach (['pt', 'en'] as $language) {
            $other = $language === 'pt' ? 'en' : 'pt';
            if (self::scoreDominates($scores, $language, $other)) {
                return $language;
            }
        }

        return null;
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

    private static function isConfidentOppositeComponent(string $component, string $expectedLanguage): bool
    {
        $oppositeLanguage = $expectedLanguage === 'en' ? 'pt' : 'en';
        $result = self::detect($component);
        if ($result->language !== $oppositeLanguage) {
            return false;
        }

        return self::scoreDominates($result->scores(), $oppositeLanguage, $expectedLanguage);
    }

    /**
     * @param array<string, float> $scores
     */
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

    private static function naturalText(string $text): string
    {
        $lower = mb_strtolower($text, 'UTF-8');
        $tokens = preg_split('/[^\p{L}\p{N}_-]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $natural = array_values(array_filter($tokens, [self::class, 'isNaturalLanguageToken']));
        return implode(' ', $natural);
    }

    /** @return string[] */
    private static function naturalTokens(string $text): array
    {
        $lower = mb_strtolower($text, 'UTF-8');
        $tokens = preg_split('/[^\p{L}\p{N}_-]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_filter(
            array_slice($tokens, 0, self::MAX_COMPONENT_TOKENS),
            [self::class, 'isNaturalLanguageToken']
        ));
    }

    /** @return string[] */
    private static function oppositeWords(string $text, string $expectedLanguage): array
    {
        $tokens = self::naturalTokens($text);
        if ($tokens === []) {
            return [];
        }
        $invalid = [];
        foreach ($tokens as $token) {
            if (self::isConfidentOppositeComponent($token, $expectedLanguage)) {
                $invalid[$token] = true;
            }
        }
        if ($invalid !== []) {
            return array_keys($invalid);
        }
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            for ($window = 2; $window <= 3 && ($i + $window) <= $count; $window++) {
                $slice = array_slice($tokens, $i, $window);
                if (self::isConfidentOppositeComponent(implode(' ', $slice), $expectedLanguage)) {
                    foreach ($slice as $token) {
                        $invalid[$token] = true;
                    }
                }
            }
        }
        return array_keys($invalid);
    }

    /**
     * Build language evidence from individual words plus contiguous 2- and
     * 3-word windows. This is deliberately bounded because validation runs on
     * every changed bilingual field before translation/persistence.
     *
     * @return string[]
     */
    private static function components(string $text): array
    {
        $lower = mb_strtolower($text, 'UTF-8');
        $tokens = preg_split('/[^\p{L}\p{N}_-]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return [];
        }

        $tokens = array_slice($tokens, 0, self::MAX_COMPONENT_TOKENS);
        $components = [];
        $tokenCount = count($tokens);

        for ($i = 0; $i < $tokenCount; $i++) {
            $token = $tokens[$i];
            if (self::isNaturalLanguageToken($token)) {
                $components[$token] = true;
            }

            for ($window = 2; $window <= 3 && ($i + $window) <= $tokenCount; $window++) {
                $slice = array_slice($tokens, $i, $window);
                $natural = array_values(array_filter($slice, [self::class, 'isNaturalLanguageToken']));
                if ($natural === []) {
                    continue;
                }
                // Neutral/technical tokens are deliberately removed from the
                // detector input so they cannot distort a natural-language score.
                $component = implode(' ', $natural);
                $components[$component] = true;
            }
        }

        return array_keys($components);
    }

    private static function isNaturalLanguageToken(string $token): bool
    {
        if (in_array($token, self::NEUTRAL, true)) {
            return false;
        }

        // One- and two-character tokens are too noisy for word-level language
        // decisions; they still contribute through neighboring natural tokens.
        return mb_strlen($token, 'UTF-8') >= 3;
    }

    private static function throwMismatch(string $expectedLanguage, array $invalidWords = []): never
    {
        $invalidWords = array_values(array_unique(array_filter(array_map('strval', $invalidWords))));
        if ($expectedLanguage === 'en') {
            throw new LanguageValidationException(
                'This text mixes Portuguese and English. Please write it in English only.',
                $invalidWords
            );
        }
        throw new LanguageValidationException(
            'Este texto mistura português e inglês. Escreva-o apenas em português.',
            $invalidWords
        );
    }
}
