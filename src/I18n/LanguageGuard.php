<?php
declare(strict_types=1);

/**
 * Conservative, local language guard for user-authored bilingual content.
 *
 * This is intentionally not a general-purpose language detector. It blocks
 * only high-confidence PT/EN mismatches before ContentTranslator translates or
 * persists user-authored natural language. Neutral technical terms, brands and
 * proper names should pass through unchanged.
 */
final class LanguageGuard
{
    private const PORTUGUESE_STRONG = [
        'verificação', 'verificar', 'confirmar', 'limpeza', 'funcionamento',
        'cozinha', 'cozinhas', 'quarto', 'quartos', 'casa', 'casas', 'banho',
        'corredor', 'corredores', 'terraço', 'terraços', 'campainha', 'campainhas',
        'telemóvel', 'telemóveis', 'empregada', 'empregadas', 'governanta',
        'alojamento', 'instrução', 'instruções', 'problema', 'problemas',
        'lâmpada', 'lâmpadas', 'fechadura', 'fechaduras', 'porta', 'portas',
        'janela', 'janelas', 'chave', 'chaves', 'cortina', 'cortinas', 'cama',
        'camas', 'parede', 'paredes', 'cabide', 'cabides', 'cabeceira', 'cabeceiras',
        'ventoinha', 'ventoinhas', 'espelho', 'armário', 'armários', 'ficheiro',
        'ficheiros', 'guardar', 'apagar', 'atribuir', 'atribuído', 'atribuídos',
        'disponível', 'disponíveis', 'danificado', 'danificada', 'fissura', 'fissuras',
    ];

    private const PORTUGUESE_COMMON = [
        'que', 'está', 'estão', 'uma', 'umas', 'todos', 'todas', 'limpo', 'limpa',
        'limpos', 'limpas', 'bem', 'sem', 'danos', 'estado', 'deve', 'devem',
        'para', 'com', 'dos', 'das', 'este', 'esta', 'estes', 'estas', 'não',
        'foi', 'ser', 'são', 'tem', 'têm', 'entre', 'antes', 'depois',
    ];

    private const ENGLISH_STRONG = [
        'verification', 'inspection', 'inspect', 'check', 'confirm', 'cleaning',
        'kitchen', 'kitchens', 'room', 'rooms', 'bathroom', 'bathrooms', 'corridor',
        'corridors', 'terrace', 'terraces', 'bell', 'bells', 'mobile', 'housekeeper',
        'housekeeping', 'property', 'instruction', 'instructions', 'issue', 'issues',
        'lamp', 'lamps', 'light', 'lights', 'lock', 'locks', 'door', 'doors',
        'window', 'windows', 'key', 'keys', 'curtain', 'curtains', 'bed', 'beds',
        'wall', 'walls', 'hanger', 'hangers', 'headboard', 'headboards', 'fan', 'fans',
        'mirror', 'wardrobe', 'wardrobes', 'save', 'delete', 'assign', 'assigned',
        'available', 'damaged', 'crack', 'cracks',
    ];

    private const ENGLISH_COMMON = [
        'the', 'that', 'this', 'these', 'those', 'is', 'are', 'was', 'were', 'with',
        'without', 'and', 'for', 'from', 'before', 'after', 'between', 'all', 'each',
        'must', 'should', 'has', 'have', 'not', 'clean', 'condition', 'working',
    ];

    private const NEUTRAL = [
        'wifi', 'wi-fi', 'sip', 'my2n', 'zkaccess', 'cloudbeds', 'whatsapp', 'api',
        'pin', 'tv', 'usb', 'qr', 'café', 'hotel', 'hostel', 'online', 'offline',
    ];

    public static function assertExpectedLanguage(string $text, string $expectedLanguage): void
    {
        $expectedLanguage = $expectedLanguage === 'en' ? 'en' : 'pt';
        $detected = self::confidentLanguage($text);
        if ($detected === null || $detected === $expectedLanguage) {
            return;
        }

        if ($expectedLanguage === 'en') {
            throw new InvalidArgumentException(
                'This text appears to be written in Portuguese. Please write it in English or switch the interface to Portuguese.'
            );
        }

        throw new InvalidArgumentException(
            'Este texto parece estar escrito em inglês. Escreva-o em português ou mude o idioma da interface para inglês.'
        );
    }

    /**
     * Returns pt/en only when there is strong evidence; null means ambiguous.
     */
    public static function confidentLanguage(string $text): ?string
    {
        $tokens = self::tokens($text);
        if ($tokens === []) {
            return null;
        }

        $ptStrong = self::countMatches($tokens, self::PORTUGUESE_STRONG);
        $enStrong = self::countMatches($tokens, self::ENGLISH_STRONG);
        $ptCommon = self::countMatches($tokens, self::PORTUGUESE_COMMON);
        $enCommon = self::countMatches($tokens, self::ENGLISH_COMMON);

        // A single domain-specific marker is enough only when the opposite
        // language has no evidence. This catches short names such as "Limpeza"
        // or "Kitchen" without guessing neutral/proper-name text.
        if ($ptStrong >= 1 && $enStrong === 0 && $enCommon === 0) {
            return 'pt';
        }
        if ($enStrong >= 1 && $ptStrong === 0 && $ptCommon === 0) {
            return 'en';
        }

        $ptScore = ($ptStrong * 3) + $ptCommon;
        $enScore = ($enStrong * 3) + $enCommon;
        if ($ptScore >= 3 && $ptScore >= $enScore + 2) {
            return 'pt';
        }
        if ($enScore >= 3 && $enScore >= $ptScore + 2) {
            return 'en';
        }

        return null;
    }

    private static function tokens(string $text): array
    {
        $lower = mb_strtolower(trim($text), 'UTF-8');
        if ($lower === '') {
            return [];
        }
        $tokens = preg_split('/[^\p{L}\p{N}_-]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_filter(
            $tokens,
            static fn(string $token): bool => !in_array($token, self::NEUTRAL, true)
        ));
    }

    private static function countMatches(array $tokens, array $dictionary): int
    {
        $set = array_fill_keys($dictionary, true);
        $count = 0;
        foreach ($tokens as $token) {
            if (isset($set[$token])) {
                $count++;
            }
        }
        return $count;
    }
}
