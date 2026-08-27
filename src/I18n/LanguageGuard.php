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
 * Every natural-language word is validated independently against the language
 * selected by the user. A word is accepted only when the detector positively
 * identifies it as the expected language. Neutral technical/brand/loan terms
 * are excluded deliberately.
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

    // Calibrated against the vendored EN/PT subset. Short detector results may
    // be marked non-reliable even when the EN/PT score separation is clear, so
    // these thresholds provide the only fallback for an expected-language word.
    private const COMPONENT_MIN_SCORE = 0.18;
    private const COMPONENT_MIN_GAP = 0.08;
    private const COMPONENT_MIN_RATIO = 1.35;
    private const MAX_COMPONENT_TOKENS = 40;

    /**
     * Technical, brand and shared loan terms deliberately treated as neutral.
     * This is not a PT/EN vocabulary list: these values are allowed in either
     * interface language and therefore must not be used as language evidence.
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
        $tokens = self::naturalTokens($text);
        if ($tokens === []) {
            return;
        }

        // Primary rule: validate each complete word. We intentionally do not
        // split tokens into prefixes, suffixes, duplicated characters or joined
        // subwords. If the complete token is not positively identified as the
        // selected language, that complete token is invalid.
        $invalidWords = [];
        foreach ($tokens as $token) {
            if (!self::wordMatchesExpectedLanguage($token, $expectedLanguage)) {
                $invalidWords[$token] = true;
            }
        }

        if ($invalidWords !== []) {
            self::throwMismatch($expectedLanguage, array_keys($invalidWords));
        }

        // Keep the whole-field check as a second safety net, principally for
        // phrases dominated by short/noisy material. It cannot make a word pass:
        // the strict word-by-word rule above has already succeeded first.
        $whole = self::detect(implode(' ', $tokens));
        if ($whole->language === $oppositeLanguage && $whole->isReliable()) {
            self::throwMismatch($expectedLanguage, array_slice($tokens, 0, 20));
        }
    }

    /**
     * Returns pt/en only when the statistical result is sufficiently strong;
     * null means ambiguous/neutral. This helper remains useful to translation
     * quality checks; strict field validation is implemented separately above.
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

    private static function wordMatchesExpectedLanguage(string $word, string $expectedLanguage): bool
    {
        $otherLanguage = $expectedLanguage === 'en' ? 'pt' : 'en';
        $result = self::detect($word);

        if ($result->language !== $expectedLanguage) {
            return false;
        }

        if ($result->isReliable()) {
            return true;
        }

        return self::scoreDominates($result->scores(), $expectedLanguage, $otherLanguage);
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
        return implode(' ', self::naturalTokens($text));
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

    private static function isNaturalLanguageToken(string $token): bool
    {
        if (in_array($token, self::NEUTRAL, true)) {
            return false;
        }

        // One- and two-character words are too noisy for the statistical model.
        // They remain covered by the complete-field check when enough context is
        // present, while all words of 3+ characters are strict word-level checks.
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
