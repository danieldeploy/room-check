<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/I18n/LexicalLanguageChecker.php';

final class FakeLexicalLanguageClassifier implements LexicalLanguageClassifier, LexicalNearMatchClassifier
{
    /** @param array<string, string> $map */
    public function __construct(private array $map)
    {
    }

    public function classifyTokens(array $tokens): array
    {
        $results = [];
        foreach ($tokens as $token) {
            $normalized = LexicalLanguageChecker::normalizeToken((string) $token);
            $results[$normalized] = $this->map[$normalized] ?? 'unknown';
        }
        return $results;
    }

    public function likelyMisspelling(string $token): ?array
    {
        $token = LexicalLanguageChecker::normalizeToken($token);
        $length = mb_strlen($token, 'UTF-8');
        if ($token === '' || $length < 4 || isset($this->map[$token])) {
            return null;
        }
        $maxDistance = $length >= 5 ? 2 : 1;
        $best = null;
        foreach ($this->map as $candidate => $classification) {
            if (abs(mb_strlen($candidate, 'UTF-8') - $length) > $maxDistance) {
                continue;
            }
            $distance = levenshtein($token, $candidate);
            if ($distance < 1 || $distance > $maxDistance) {
                continue;
            }
            if ($best === null || $distance < $best['distance']) {
                $best = [
                    'candidate' => $candidate,
                    'distance' => $distance,
                    'classification' => $classification,
                ];
            }
        }
        return $best;
    }
}

/** @return array<string, string> */
function translationRegressionLexicon(): array
{
    $ptOnly = [
        'a', 'abertura', 'as', 'bem', 'casa', 'cozinha', 'cozinha', 'cortinas', 'danos',
        'das', 'de', 'dos', 'e', 'em', 'está', 'estão', 'fecho', 'fixas', 'grande',
        'janelas', 'limpas', 'limpeza', 'limpo', 'na', 'no', 'nossa', 'o', 'quarto',
        'rua', 'se', 'sem', 'verificação', 'verificar', 'vidros',
    ];
    $enOnly = [
        'all', 'and', 'are', 'check', 'clean', 'common', 'curtains', 'domestic', 'fire',
        'gauge', 'home', 'house', 'in', 'inside', 'is', 'it', 'kitchen', 'new', 'news',
        'pressure', 'room', 'securely', 'smoke', 'status', 'that', 'the', 'thermostat',
        'undamaged', 'well', 'windows', 'extinguisher', 'inspect', 'fitted',
    ];
    $shared = ['detector', 'visible', 'yahoo'];

    $map = [];
    foreach ($ptOnly as $word) {
        $map[$word] = 'pt_only';
    }
    foreach ($enOnly as $word) {
        $map[$word] = 'en_only';
    }
    foreach ($shared as $word) {
        $map[$word] = 'shared';
    }
    return $map;
}

function translationRegressionClassifier(): FakeLexicalLanguageClassifier
{
    return new FakeLexicalLanguageClassifier(translationRegressionLexicon());
}
