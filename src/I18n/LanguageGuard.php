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

    private const SHORT_MIN_SCORE = 0.24;
    private const SHORT_MIN_GAP = 0.12;
    private const SHORT_MIN_RATIO = 1.65;

    private const TOKEN_MIN_SCORE = 0.18;
    private const TOKEN_MIN_GAP = 0.08;
    private const TOKEN_MIN_RATIO = 1.35;

    private const MIXED_MIN_SCORE = 0.26;
    private const MIXED_MIN_GAP = 0.13;
    private const MIXED_MIN_RATIO = 1.75;

    private static ?LanguageDetector $detector = null;

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

    /**
     * A two-word phrase is classified only when the phrase itself is strong and
     * both component tokens independently support the same language. This keeps
     * clear phrases such as "new house" / "casa grande" useful while shared or
     * technical combinations such as "WiFi Café" / "fire extinguisher" stay
     * ambiguous instead of becoming false errors.
     */
    private static function confidentShortLanguage(string $text): ?string
    {
        $tokens = self::tokens($text);
        if (count($tokens) !== 2) {
            return null;
        }

        $phraseLanguage = self::resultLanguage(
            self::detect($text),
            self::SHORT_MIN_SCORE,
            self::SHORT_MIN_GAP,
            self::SHORT_MIN_RATIO,
            false
        );
        if ($phraseLanguage === null) {
            return null;
        }

        foreach ($tokens as $token) {
            $tokenLanguage = self::resultLanguage(
                self::detect($token),
                self::TOKEN_MIN_SCORE,
                self::TOKEN_MIN_GAP,
                self::TOKEN_MIN_RATIO,
                false
            );
            if ($tokenLanguage !== $phraseLanguage) {
                return null;
            }
        }

        return $phraseLanguage;
    }

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
                $language = self::resultLanguage(
                    self::detect($text),
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
