<?php
declare(strict_types=1);

require_once __DIR__ . '/LanguageGuard.php';

final class ContentTranslator
{
    private const MYMEMORY_MAX_QUERY_BYTES = 500;

    public function __construct(private PDO $pdo, private array $config)
    {
    }

    public function versions(string $text, string $sourceLanguage, string $existingPt = '', string $existingEn = ''): array
    {
        $text = trim($text);
        $sourceLanguage = $sourceLanguage === 'en' ? 'en' : 'pt';
        $existingPt = trim($existingPt);
        $existingEn = trim($existingEn);

        if ($text === '') {
            return ['pt' => '', 'en' => ''];
        }

        // A value already stored for the active language is not new user input.
        // Reuse the existing bilingual pair before language validation so an
        // unrelated edit cannot be blocked by legacy/ambiguous unchanged text.
        if ($sourceLanguage === 'en'
            && $existingEn === $text
            && $existingPt !== ''
            && $existingPt !== $existingEn
            && self::isPlausibleTargetText($existingPt, 'pt')) {
            return ['pt' => $existingPt, 'en' => $text];
        }
        if ($sourceLanguage === 'pt'
            && $existingPt === $text
            && $existingEn !== ''
            && $existingEn !== $existingPt
            && self::isPlausibleTargetText($existingEn, 'en')) {
            return ['pt' => $text, 'en' => $existingEn];
        }

        // Single project-wide server boundary for newly entered/changed
        // user-authored bilingual text. If the text is clearly in the opposite
        // language, stop here: do not call the provider or persist anything.
        LanguageGuard::assertExpectedLanguage($text, $sourceLanguage);

        if ($sourceLanguage === 'en') {
            $translatedPt = $this->translate($text, 'en', 'pt');
            if ($translatedPt === null || trim($translatedPt) === '') {
                throw new InvalidArgumentException(
                    'Automatic translation to Portuguese failed. Please try again.'
                );
            }
            return ['pt' => trim($translatedPt), 'en' => $text];
        }

        $translatedEn = $this->translate($text, 'pt', 'en');
        if ($translatedEn === null || trim($translatedEn) === '') {
            throw new InvalidArgumentException(
                'Não foi possível traduzir automaticamente o conteúdo para inglês. Tente novamente.'
            );
        }
        return ['pt' => $text, 'en' => trim($translatedEn)];
    }

    /**
     * Translate without falling back to the source text.
     *
     * This is used by legacy-content backfills: a failed provider request must
     * leave the target column empty so it can be retried later instead of
     * incorrectly persisting Portuguese as if it were an English translation.
     */
    public function translateStrict(string $text, string $sourceLanguage, string $targetLanguage): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $source = $sourceLanguage === 'en' ? 'en' : 'pt';
        $target = $targetLanguage === 'pt' ? 'pt' : 'en';
        if ($source === $target) {
            return $text;
        }

        return $this->translate($text, $source, $target);
    }

    /**
     * Conservative language-quality guard for provider/cache output.
     *
     * It deliberately rejects only strong signs that the target text is still
     * written in the opposite language. Neutral terms and brands (WiFi, SIP,
     * My2N, TV, Café, etc.) remain valid. This is not intended to be a general
     * language detector; it prevents the concrete hybrid failure mode observed
     * in production such as "Confirm que estão limpas e bem fixas.".
     */
    public static function isPlausibleTargetText(string $text, string $targetLanguage): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        $targetLanguage = $targetLanguage === 'pt' ? 'pt' : 'en';
        $lower = mb_strtolower($text, 'UTF-8');
        $tokens = preg_split('/[^\p{L}\p{N}_]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return true;
        }
        $tokenSet = array_fill_keys($tokens, true);

        if ($targetLanguage === 'en') {
            $strongPortuguese = [
                'verificar', 'confirmar', 'limpeza', 'funcionamento', 'lâmpada', 'lâmpadas',
                'acendem', 'fechadura', 'fechaduras', 'disponível', 'disponíveis', 'fissuras',
                'moldura', 'danificada', 'danificado', 'cabides', 'quarto', 'quartos',
                'corredor', 'corredores', 'portas', 'janelas', 'chaves', 'cortinas', 'camas',
                'vidros', 'manchas', 'ventoinhas', 'armários', 'armarios', 'cabeceiras',
            ];
            foreach ($strongPortuguese as $token) {
                if (isset($tokenSet[$token])) {
                    return false;
                }
            }

            $commonPortuguese = [
                'que', 'está', 'estão', 'todas', 'todos', 'limpa', 'limpo', 'limpas', 'limpos',
                'bem', 'fixa', 'fixas', 'fixo', 'fixos', 'funcionam', 'sem', 'danos',
                'visível', 'visíveis', 'estado', 'quantidade', 'abrem', 'fecham', 'corretamente',
            ];
            $matches = 0;
            foreach ($commonPortuguese as $token) {
                if (isset($tokenSet[$token]) && ++$matches >= 2) {
                    return false;
                }
            }
            return true;
        }

        $strongEnglish = [
            'undamaged', 'wardrobes', 'headboards', 'curtains', 'sockets', 'securely', 'fitted',
            'hangers', 'cracks', 'damaged', 'september', 'corridors', 'doors', 'windows', 'keys',
            'lights', 'beds', 'fans', 'locks', 'walls', 'mirror',
        ];
        foreach ($strongEnglish as $token) {
            if (isset($tokenSet[$token])) {
                return false;
            }
        }

        $commonEnglish = [
            'check', 'confirm', 'clean', 'turn', 'available', 'working', 'damage', 'visible',
            'stains', 'condition', 'number', 'open', 'close', 'correctly',
        ];
        $matches = 0;
        foreach ($commonEnglish as $token) {
            if (isset($tokenSet[$token]) && ++$matches >= 2) {
                return false;
            }
        }
        return true;
    }

    private function translate(string $text, string $source, string $target): ?string
    {
        if ($text === '') {
            return '';
        }

        $cached = $this->cachedTranslation($text, $source, $target);
        if ($cached !== null) {
            return $cached;
        }

        if (($this->config['enabled'] ?? true) !== true || !function_exists('curl_init')) {
            return null;
        }

        $translatedChunks = [];
        foreach ($this->chunks($text) as $chunk) {
            $translation = $this->translateChunk($chunk, $source, $target);
            if ($translation === null || $translation === ''
                || !self::isPlausibleTargetText($translation, $target)) {
                return null;
            }
            $translatedChunks[] = $translation;
        }

        $translated = trim(implode("\n", $translatedChunks));
        if ($translated === '' || !self::isPlausibleTargetText($translated, $target)) {
            return null;
        }

        $this->storeTranslation($text, $translated, $source, $target);
        return $translated;
    }

    private function cachedTranslation(string $text, string $source, string $target): ?string
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT translated_text
                 FROM translation_cache
                 WHERE source_language = :source_language
                   AND target_language = :target_language
                   AND source_hash = :source_hash
                   AND source_text = :source_text
                 LIMIT 1'
            );
            $parameters = [
                'source_language' => $source,
                'target_language' => $target,
                'source_hash' => hash('sha256', $text),
                'source_text' => $text,
            ];
            $statement->execute($parameters);
            $translated = $statement->fetchColumn();
            if (is_string($translated) && trim($translated) !== '') {
                $translated = trim($translated);
                if (self::isPlausibleTargetText($translated, $target)) {
                    return $translated;
                }

                // An invalid legacy cache entry must not keep poisoning future saves.
                try {
                    $delete = $this->pdo->prepare(
                        'DELETE FROM translation_cache
                         WHERE source_language = :source_language
                           AND target_language = :target_language
                           AND source_hash = :source_hash
                           AND source_text = :source_text'
                    );
                    $delete->execute($parameters);
                } catch (Throwable) {
                    // A cache cleanup failure must not block a fresh provider request.
                }
            }
        } catch (Throwable) {
            // The migration may not have been applied yet. Translation still works without cache.
        }

        return null;
    }

    private function storeTranslation(string $text, string $translated, string $source, string $target): void
    {
        if (!self::isPlausibleTargetText($translated, $target)) {
            return;
        }

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO translation_cache
                    (source_language, target_language, source_hash, source_text, translated_text)
                 VALUES
                    (:source_language, :target_language, :source_hash, :source_text, :translated_text)
                 ON DUPLICATE KEY UPDATE
                    source_text = VALUES(source_text),
                    translated_text = VALUES(translated_text),
                    updated_at = CURRENT_TIMESTAMP'
            );
            $statement->execute([
                'source_language' => $source,
                'target_language' => $target,
                'source_hash' => hash('sha256', $text),
                'source_text' => $text,
                'translated_text' => $translated,
            ]);
        } catch (Throwable) {
            // A cache failure must never prevent the original save operation.
        }
    }

    private function translateChunk(string $text, string $source, string $target): ?string
    {
        $endpoint = rtrim((string) ($this->config['endpoint'] ?? 'https://api.mymemory.translated.net/get'), '?');
        $query = ['q' => $text, 'langpair' => $source . '|' . $target, 'mt' => '1'];
        $contactEmail = trim((string) ($this->config['contact_email'] ?? ''));
        if ($contactEmail !== '') {
            $query['de'] = $contactEmail;
        }

        $curl = curl_init($endpoint . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(3, (int) ($this->config['timeout_seconds'] ?? 12)),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'RoomCheck/1.0 translation',
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if (!is_string($response) || $status < 200 || $status >= 300) {
            return null;
        }
        $data = json_decode($response, true);
        if (!is_array($data)) {
            return null;
        }
        $responseStatus = (int) ($data['responseStatus'] ?? 200);
        if ($responseStatus < 200 || $responseStatus >= 300) {
            return null;
        }
        $translated = $data['responseData']['translatedText'] ?? null;
        if (!is_string($translated)) {
            return null;
        }
        $translated = html_entity_decode(trim($translated), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $translated !== '' ? $translated : null;
    }

    private function chunks(string $text): array
    {
        if (strlen($text) <= self::MYMEMORY_MAX_QUERY_BYTES) {
            return [$text];
        }
        $parts = preg_split('/(?<=[.!?;:])\s+|\R+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
        $chunks = [];
        $current = '';
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $candidate = $current === '' ? $part : $current . ' ' . $part;
            if (strlen($candidate) <= self::MYMEMORY_MAX_QUERY_BYTES) {
                $current = $candidate;
                continue;
            }
            if ($current !== '') {
                $chunks[] = $current;
                $current = '';
            }
            foreach ($this->splitOversizedPart($part) as $piece) {
                if ($current === '') {
                    $current = $piece;
                } elseif (strlen($current . ' ' . $piece) <= self::MYMEMORY_MAX_QUERY_BYTES) {
                    $current .= ' ' . $piece;
                } else {
                    $chunks[] = $current;
                    $current = $piece;
                }
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }
        return $chunks;
    }

    private function splitOversizedPart(string $text): array
    {
        $pieces = [];
        while (strlen($text) > self::MYMEMORY_MAX_QUERY_BYTES) {
            $piece = function_exists('mb_strcut')
                ? mb_strcut($text, 0, self::MYMEMORY_MAX_QUERY_BYTES, 'UTF-8')
                : substr($text, 0, self::MYMEMORY_MAX_QUERY_BYTES);
            $lastSpace = strrpos($piece, ' ');
            if ($lastSpace !== false && $lastSpace > 250) {
                $piece = substr($piece, 0, $lastSpace);
            }
            $pieces[] = trim($piece);
            $text = ltrim(substr($text, strlen($piece)));
        }
        if ($text !== '') {
            $pieces[] = trim($text);
        }
        return $pieces;
    }
}
