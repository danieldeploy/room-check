<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/I18n/LexicalLanguageChecker.php';

final class FakeLexicalLanguageClassifier implements LexicalLanguageClassifier
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
        'undamaged', 'windows', 'extinguisher', 'inspect', 'fitted',
    ];
    $shared = ['detector', 'visible'];

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
