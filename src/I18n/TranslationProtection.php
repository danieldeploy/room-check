<?php
declare(strict_types=1);

/**
 * Project-wide protection for literal technical content inside natural text.
 *
 * Quoted text is an explicit authoring convention: it must be kept exactly as
 * written instead of being translated. Numbers are protected for the same
 * reason (room numbers, dates, times, PINs and version identifiers).
 */
final class TranslationProtection
{
    private const QUOTED_PATTERN_BODY =
        '"[^"\r\n]*"|“[^”\r\n]*”|«[^»\r\n]*»|„[^“\r\n]*“|‘[^’\r\n]*’|‹[^›\r\n]*›|`[^`\r\n]*`'
        . '|(?<![\p{L}\p{N}])\'[^\'\r\n]+\'(?![\p{L}\p{N}])';
    private const MACHINE_PATTERN_BODY =
        'https?:\/\/[^\s<>"“”«»„‘’‹›`]+'
        . '|[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.[\p{L}]{2,}'
        . '|\{\{[^{}\r\n]+\}\}'
        . '|\{[A-Za-z_][A-Za-z0-9_.-]*\}';
    private const NUMBER_PATTERN_BODY = '\p{N}+(?:[.,:\/\-]\p{N}+)*';

    public static function quotedPatternBody(): string
    {
        return self::QUOTED_PATTERN_BODY;
    }

    public static function literalPatternBody(bool $includeNumbers = true): string
    {
        $body = self::QUOTED_PATTERN_BODY . '|' . self::MACHINE_PATTERN_BODY;
        return $includeNumbers ? $body . '|' . self::NUMBER_PATTERN_BODY : $body;
    }

    /** @return string[] */
    public static function fragments(string $text, bool $includeNumbers = true): array
    {
        $matched = preg_match_all('/(?:' . self::literalPatternBody($includeNumbers) . ')/u', $text, $matches);
        return $matched === false ? [] : array_values(array_map('strval', $matches[0] ?? []));
    }

    /** @return array{text:string,protected:array<string,string>} */
    public static function prepare(string $text): array
    {
        $protected = [];
        $counter = 0;
        $pattern = '/(?:' . self::literalPatternBody() . ')/u';
        $working = preg_replace_callback(
            $pattern,
            static function (array $match) use ($text, &$protected, &$counter): string {
                do {
                    $token = 'RoomCheckKeep' . self::alphabeticCounter($counter++) . 'LiteralToken';
                } while (stripos($text, $token) !== false || isset($protected[$token]));
                $protected[$token] = (string) $match[0];
                return $token;
            },
            $text
        );

        return [
            'text' => is_string($working) ? $working : $text,
            'protected' => $protected,
        ];
    }

    /** @param array<string,string> $protected */
    public static function restore(string $translated, array $protected): ?string
    {
        foreach ($protected as $token => $original) {
            if (substr_count(strtolower($translated), strtolower($token)) !== 1) {
                return null;
            }
            $translated = str_ireplace($token, $original, $translated);
        }
        return $translated;
    }

    public static function preserves(string $source, string $translated, bool $includeNumbers = true): bool
    {
        $expected = array_count_values(self::fragments($source, $includeNumbers));
        $actual = array_count_values(self::fragments($translated, $includeNumbers));
        foreach ($expected as $literal => $count) {
            if (($actual[$literal] ?? 0) !== $count) {
                return false;
            }
        }
        return true;
    }

    private static function alphabeticCounter(int $value): string
    {
        $result = '';
        do {
            $result = chr(65 + ($value % 26)) . $result;
            $value = intdiv($value, 26) - 1;
        } while ($value >= 0);
        return $result;
    }
}
