<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/ThirdParty/efficient-language-detector/manual_loader.php';

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

final class LanguageGuard
{
    private const MODEL = 'large_2_1niz1ni';

    // General complete-text confidence thresholds.
    private const COMPONENT_MIN_SCORE = 0.18;
    private const COMPONENT_MIN_GAP = 0.08;
    private const COMPONENT_MIN_RATIO = 1.35;

    // Short phrases need stronger evidence than normal sentences. This allows
    // genuinely clear two-word input such as "new house" or "casa grande" to
    // be classified while keeping shared/technical short phrases conservative.
    private const SHORT_MIN_SCORE = 0.24;
    private const SHORT_MIN_GAP = 0.12;
    private const SHORT_MIN_RATIO = 1.65;

    // Mixed-language detection only uses multi-word segments and intentionally
    // avoids single-token voting. That prevents a technical word such as
    // "extinguisher", "detector" or "HVAC" from becoming a false language error.
    private const MIXED_MIN_SCORE = 0.26;
    private const MIXED_MIN_GAP = 0.13;
    private const MIXED_MIN_RATIO = 1.75;

    private static ?LanguageDetector $detector = null;

    /**
     * Returns correct, ambiguous, wrong or mixed for text entered in the active
     * interface language. Empty text is treated as ambiguous/neutral.
     */
    public static function sourceConclusion(string $text, string $expectedLanguage): string
    {
        $text = trim($text);
        if ($text === '') {
            return 'ambiguous';
        }

        $expectedLanguage = $expectedLanguage === 'en' ? 'en' : 'pt';
        $oppositeLanguage = $expectedLanguage === 'en' ? 'pt' : 'en';
        $tokens = self::tokens($text);

        if (self::hasMixedLanguageEvidence($tokens)) {
            return 'mixed';
        }

        // A single isolated token remains deliberately ambiguous. This is the
        // key protection for technical vocabulary and shared words.
        if (count($tokens) < 2) {
            return 'ambiguous';
        }

        $detected = count($tokens) === 2
            ? self::confidentShortLanguage($text)
            : self::confidentLanguage($text);

        if ($detected === null) {
            return 'ambiguous';
        }
        if ($detected === $expectedLanguage) {
            return 'correct';
        }
        if ($detected === $oppositeLanguage) {
            return 'wrong';
        }
        return 'ambiguous';
    }

    /**
     * Reject only when the complete source is clearly in the opposite language
     * or when distinct multi-word sections provide strong EN and PT evidence.
     */
    public static function assertExpectedLanguage(string $text, string $expectedLanguage): void
    {
        $expectedLanguage = $expectedLanguage === 'en' ? 'en' : 'pt';
        $conclusion = self::sourceConclusion($text, $expectedLanguage);

        if ($conclusion === 'mixed') {
            if ($expectedLanguage === 'en') {
                throw new LanguageValidationException('Error: text mixes EN and PT.');
            }
            throw new LanguageValidationException('Erro: o texto mistura PT e EN.');
        }

        if ($conclusion !== 'wrong') {
            return;
        }

        $oppositeLanguage = $expectedLanguage === 'en' ? 'pt' : 'en';
        $label = strtoupper($oppositeLanguage);
        if ($expectedLanguage === 'en') {
            throw new LanguageValidationException("Error: text is clearly {$label}.");
        }
        throw new LanguageValidationException("Erro: texto claramente {$label}.");
    }

    /**
     * Returns pt/en only when the complete text is sufficiently strong.
     * null means ambiguous/neutral and must not be treated as an error.
     */
    public static function confidentLanguage(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        $result = self::detect($text);
        return self::resultLanguage(
            $result,
            self::COMPONENT_MIN_SCORE,
            self::COMPONENT_MIN_GAP,
            self::COMPONENT_MIN_RATIO,
            true
        );
    }

    /**
     * Sentence helper retained for translation-output checks. Single-token text
     * remains ambiguous, but a clear two-word phrase can now be classified.
     */
    public static function confidentSentenceLanguage(string $text): ?string
    {
        $tokens = self::tokens($text);
        if (count($tokens) < 2) {
            return null;
        }
        if (count($tokens) === 2) {
            return self::confidentShortLanguage($text);
        }
        return self::confidentLanguage($text);
    }

    private static function confidentShortLanguage(string $text): ?string
    {
        $result = self::detect($text);
        return self::resultLanguage(
            $result,
            self::SHORT_MIN_SCORE,
            self::SHORT_MIN_GAP,
            self::SHORT_MIN_RATIO,
            false
        );
    }

    /**
     * A mixed value needs strong evidence for both languages in separate,
     * non-overlapping multi-word segments. No single word can trigger this.
     */
    private static function hasMixedLanguageEvidence(array $tokens): bool
    {
        $count = count($tokens);
        if ($count < 4) {
            return false;
        }

        $segments = [];
        $maxLength = min(5, $count - 1);
        for ($length = 2; $length <= $maxLength; $length++) {
            for ($start = 0; $start + $length <= $count; $start++) {
                $text = implode(' ', array_slice($tokens, $start, $length));
                $result = self::detect($text);
                $language = self::resultLanguage(
                    $result,
                    self::MIXED_MIN_SCORE,
                    self::MIXED_MIN_GAP,
                    self::MIXED_MIN_RATIO,
                    false
                );
                if ($language === null) {
                    continue;
                }
                $segments[] = [
                    'language' => $language,
                    'start' => $start,
                    'end' => $start + $length - 1,
                ];
            }
        }

        foreach ($segments as $first) {
            foreach ($segments as $second) {
                if ($first['language'] === $second['language']) {
                    continue;
                }
                if ($first['end'] < $second['start'] || $second['end'] < $first['start']) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return string[] */
    private static function tokens(string $text): array
    {
        return preg_split(
            '/[^\p{L}\p{N}_-]+/u',
            mb_strtolower(trim($text), 'UTF-8'),
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [];
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

    private static function resultLanguage(
        LanguageResult $result,
        float $minScore,
        float $minGap,
        float $minRatio,
        bool $allowReliableShortcut
    ): ?string {
        if ($allowReliableShortcut
            && ($result->language === 'pt' || $result->language === 'en')
            && $result->isReliable()) {
            return $result->language;
        }

        $scores = $result->scores();
        foreach (['pt', 'en'] as $language) {
            $other = $language === 'pt' ? 'en' : 'pt';
            if (self::scoreDominates($scores, $language, $other, $minScore, $minGap, $minRatio)) {
                return $language;
            }
        }
        return null;
    }

    /** @param array<string, float> $scores */
    private static function scoreDominates(
        array $scores,
        string $language,
        string $otherLanguage,
        float $minScore,
        float $minGap,
        float $minRatio
    ): bool {
        $score = (float) ($scores[$language] ?? 0.0);
        $otherScore = (float) ($scores[$otherLanguage] ?? 0.0);
        if ($score < $minScore) {
            return false;
        }
        if (($score - $otherScore) < $minGap) {
            return false;
        }
        if ($otherScore > 0.0 && ($score / $otherScore) < $minRatio) {
            return false;
        }
        return true;
    }
}
