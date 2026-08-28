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
        $targetLanguage = $sourceLanguage === 'en' ? 'pt' : 'en';
        $existingPt = trim($existingPt);
        $existingEn = trim($existingEn);

        if ($text === '') {
            return [
                'pt' => '',
                'en' => '',
                'sourceConclusion' => 'empty',
                'translationConclusion' => 'empty',
                'validationMessage' => self::successMessage('empty', 'empty', $sourceLanguage),
            ];
        }

        // Unchanged values reuse the already persisted bilingual pair. This
        // prevents revisiting a page from triggering another provider request.
        if ($sourceLanguage === 'en'
            && $existingEn === $text
            && $existingPt !== ''
            && $existingPt !== $existingEn
            && self::isPlausibleTargetText($existingPt, 'pt')) {
            $translationConclusion = self::targetConclusion($existingPt, 'pt');
            return [
                'pt' => $existingPt,
                'en' => $text,
                'sourceConclusion' => 'reused',
                'translationConclusion' => $translationConclusion,
                'validationMessage' => self::successMessage('reused', $translationConclusion, $sourceLanguage),
            ];
        }
        if ($sourceLanguage === 'pt'
            && $existingPt === $text
            && $existingEn !== ''
            && $existingEn !== $existingPt
            && self::isPlausibleTargetText($existingEn, 'en')) {
            $translationConclusion = self::targetConclusion($existingEn, 'en');
            return [
                'pt' => $text,
                'en' => $existingEn,
                'sourceConclusion' => 'reused',
                'translationConclusion' => $translationConclusion,
                'validationMessage' => self::successMessage('reused', $translationConclusion, $sourceLanguage),
            ];
        }

        // Source validation is independent from translation quality. A provider
        // being able to normalize mixed input must never prove that the original
        // text was written in the selected interface language.
        $sourceConclusion = LanguageGuard::sourceConclusion($text, $sourceLanguage);
        LanguageGuard::assertExpectedLanguage($text, $sourceLanguage);

        if ($sourceLanguage === 'en') {
            $translatedPt = $this->translate($text, 'en', 'pt');
            if ($translatedPt === null || trim($translatedPt) === '') {
                throw new InvalidArgumentException('Error: automatic translation to Portuguese failed.');
            }
            $translationConclusion = self::targetConclusion($translatedPt, 'pt');
            return [
                'pt' => trim($translatedPt),
                'en' => $text,
                'sourceConclusion' => $sourceConclusion,
                'translationConclusion' => $translationConclusion,
                'validationMessage' => self::successMessage($sourceConclusion, $translationConclusion, 'en'),
            ];
        }

        $translatedEn = $this->translate($text, 'pt', 'en');
        if ($translatedEn === null || trim($translatedEn) === '') {
            throw new InvalidArgumentException('Erro: não foi possível traduzir automaticamente o conteúdo para inglês.');
        }
        $translationConclusion = self::targetConclusion($translatedEn, 'en');
        return [
            'pt' => $text,
            'en' => trim($translatedEn),
            'sourceConclusion' => $sourceConclusion,
            'translationConclusion' => $translationConclusion,
            'validationMessage' => self::successMessage($sourceConclusion, $translationConclusion, 'pt'),
        ];
    }

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

        LanguageGuard::assertExpectedLanguage($text, $source);
        return $this->translate($text, $source, $target);
    }

    /**
     * A provider/cache result is plausible unless the complete translated phrase
     * is confidently in the opposite language. Ambiguous output is accepted.
     */
    public static function isPlausibleTargetText(string $text, string $targetLanguage): bool
    {
        return self::targetConclusion($text, $targetLanguage) !== 'wrong';
    }

    /** Returns correct, ambiguous or wrong for the complete translated phrase. */
    public static function targetConclusion(string $text, string $targetLanguage): string
    {
        $text = trim($text);
        if ($text === '') {
            return 'wrong';
        }

        $targetLanguage = $targetLanguage === 'pt' ? 'pt' : 'en';
        $detected = LanguageGuard::confidentSentenceLanguage($text);
        if ($detected === null) {
            return 'ambiguous';
        }
        return $detected === $targetLanguage ? 'correct' : 'wrong';
    }

    /**
     * Human-readable green success message. It reports the real conclusions
     * returned by the algorithm instead of a generic success label.
     */
    public static function successMessage(
        string $sourceConclusion,
        string $translationConclusion,
        string $sourceLanguage
    ): string {
        $sourceLanguage = $sourceLanguage === 'en' ? 'en' : 'pt';
        $sourceLabel = strtoupper($sourceLanguage);
        $targetLabel = strtoupper($sourceLanguage === 'en' ? 'pt' : 'en');

        if ($sourceConclusion === 'empty') {
            return $sourceLanguage === 'en' ? 'Saved: empty text.' : 'Guardado: texto vazio.';
        }
        if ($sourceConclusion === 'reused') {
            return $sourceLanguage === 'en'
                ? 'Saved: existing bilingual translation reused.'
                : 'Guardado: tradução bilingue existente reutilizada.';
        }

        $sourcePart = $sourceConclusion === 'correct'
            ? ($sourceLanguage === 'en' ? "{$sourceLabel} text confirmed" : "texto {$sourceLabel} confirmado")
            : ($sourceLanguage === 'en' ? "{$sourceLabel} text ambiguous/technical" : "texto {$sourceLabel} ambíguo/técnico");
        $translationPart = $translationConclusion === 'correct'
            ? ($sourceLanguage === 'en' ? "{$targetLabel} translation confirmed" : "tradução {$targetLabel} confirmada")
            : ($sourceLanguage === 'en' ? "{$targetLabel} translation ambiguous/technical" : "tradução {$targetLabel} ambígua/técnica");

        return $sourceLanguage === 'en'
            ? "Saved: {$sourcePart}; {$translationPart}."
            : "Guardado: {$sourcePart}; {$translationPart}.";
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
            throw new InvalidArgumentException(
                $source === 'en'
                    ? 'Error: translation API is unavailable.'
                    : 'Erro: API de tradução indisponível.'
            );
        }

        $translated = $this->translateFresh($text, $source, $target);
        if ($translated === null || trim($translated) === '') {
            return null;
        }

        if (!self::hasPlausibleLength($text, $translated)) {
            throw new InvalidArgumentException(
                $source === 'en'
                    ? 'Error: automatic translation appears incomplete.'
                    : 'Erro: a tradução automática parece incompleta.'
            );
        }

        $conclusion = self::targetConclusion($translated, $target);
        if ($conclusion === 'wrong') {
            // MyMemory can occasionally return the source language unchanged.
            // Retry once, but never retry network timeouts because that could
            // double a 12-second wait.
            $retry = $this->translateFresh($text, $source, $target);
            if ($retry !== null && trim($retry) !== ''
                && self::hasPlausibleLength($text, $retry)
                && self::targetConclusion($retry, $target) !== 'wrong') {
                $translated = trim($retry);
                $conclusion = self::targetConclusion($translated, $target);
            }
        }

        if ($conclusion === 'wrong') {
            $detected = strtoupper($target === 'pt' ? 'en' : 'pt');
            throw new LanguageValidationException(
                $source === 'en'
                    ? "Error: translation is clearly {$detected}."
                    : "Erro: tradução claramente {$detected}."
            );
        }

        $this->storeTranslation($text, $translated, $source, $target);
        return $translated;
    }

    private function translateFresh(string $text, string $source, string $target): ?string
    {
        $translatedChunks = [];
        foreach ($this->chunks($text) as $chunk) {
            $translation = $this->translateChunk($chunk, $source, $target);
            if ($translation === null || trim($translation) === '') {
                return null;
            }
            $translatedChunks[] = trim($translation);
        }
        $translated = trim(implode("\n", $translatedChunks));
        return $translated !== '' ? $translated : null;
    }

    private static function hasPlausibleLength(string $source, string $translated): bool
    {
        $sourceLength = mb_strlen(trim($source), 'UTF-8');
        $targetLength = mb_strlen(trim($translated), 'UTF-8');
        if ($sourceLength <= 20 || $targetLength <= 20) {
            return $targetLength > 0;
        }
        $ratio = $targetLength / max(1, $sourceLength);
        return $ratio >= 0.20 && $ratio <= 5.0;
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
                if (self::isPlausibleTargetText($translated, $target)
                    && self::hasPlausibleLength($text, $translated)) {
                    return $translated;
                }

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
                    // Cache cleanup failure must not block a fresh provider request.
                }
            }
        } catch (Throwable) {
            // Translation still works when the cache migration is unavailable.
        }

        return null;
    }

    private function storeTranslation(string $text, string $translated, string $source, string $target): void
    {
        if (!self::isPlausibleTargetText($translated, $target)
            || !self::hasPlausibleLength($text, $translated)) {
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
        $errno = curl_errno($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            throw new InvalidArgumentException(
                $source === 'en'
                    ? 'Error: translation API timed out.'
                    : 'Erro: API de tradução excedeu o tempo limite.'
            );
        }
        if (!is_string($response) || $errno !== 0 || $status < 200 || $status >= 300) {
            throw new InvalidArgumentException(
                $source === 'en'
                    ? 'Error: translation API is temporarily unavailable.'
                    : 'Erro: API de tradução temporariamente indisponível.'
            );
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new InvalidArgumentException(
                $source === 'en'
                    ? 'Error: translation API returned an invalid response.'
                    : 'Erro: API de tradução devolveu uma resposta inválida.'
            );
        }
        $responseStatus = (int) ($data['responseStatus'] ?? 200);
        if ($responseStatus < 200 || $responseStatus >= 300) {
            throw new InvalidArgumentException(
                $source === 'en'
                    ? 'Error: translation API rejected the request.'
                    : 'Erro: API de tradução rejeitou o pedido.'
            );
        }
        $translated = $data['responseData']['translatedText'] ?? null;
        if (!is_string($translated)) {
            throw new InvalidArgumentException(
                $source === 'en'
                    ? 'Error: translation API returned no translated text.'
                    : 'Erro: API de tradução não devolveu texto traduzido.'
            );
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
