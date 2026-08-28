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
    private const COMPONENT_MIN_SCORE = 0.18;
    private const COMPONENT_MIN_GAP = 0.08;
    private const COMPONENT_MIN_RATIO = 1.35;

    private static ?LanguageDetector $detector = null;

    /**
     * Reject only when the complete phrase is confidently in the opposite
     * language. Ambiguous text and technical vocabulary are deliberately
     * allowed to continue to contextual translation.
     */
    public static function assertExpectedLanguage(string $text, string $expectedLanguage): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $expectedLanguage = $expectedLanguage === 'en' ? 'en' : 'pt';
        $oppositeLanguage = $expectedLanguage === 'en' ? 'pt' : 'en';
        $detected = self::confidentSentenceLanguage($text);
        if ($detected === $oppositeLanguage) {
            $label = strtoupper($oppositeLanguage);
            if ($expectedLanguage === 'en') {
                throw new LanguageValidationException("Error: text is clearly {$label}.");
            }
            throw new LanguageValidationException("Erro: texto claramente {$label}.");
        }
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
     * Source-language checks need enough sentence context to avoid treating a
     * technical term such as extinguisher, detector or HVAC as a language vote.
     */
    public static function confidentSentenceLanguage(string $text): ?string
    {
        $tokens = preg_split('/[^\p{L}\p{N}_-]+/u', mb_strtolower(trim($text), 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) < 3) {
            return null;
        }
        return self::confidentLanguage($text);
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
