<?php
declare(strict_types=1);

require_once __DIR__ . '/LanguageGuard.php';
require_once __DIR__ . '/LexicalLanguageChecker.php';

final class ContentTranslator
{
    private const MYMEMORY_MAX_QUERY_BYTES = 500;

    private LexicalLanguageClassifier $lexicalChecker;

    /** @var array<int, array<string, string>> */
    private static array $responseMetadata = [];

    public function __construct(
        private PDO $pdo,
        private array $config,
        ?LexicalLanguageClassifier $lexicalChecker = null
    ) {
        $this->lexicalChecker = $lexicalChecker
            ?? new LexicalLanguageChecker($pdo, is_array($config['lexical'] ?? null) ? $config['lexical'] : []);
        // A request may have created a maintenance translator while database()
        // initialized. The application translator starts a clean response set.
        self::$responseMetadata = [];
    }

    /** @return array<int, array<string, string>> */
    public static function responseMetadata(): array
    {
        return self::$responseMetadata;
    }

    public static function clearResponseMetadata(): void
    {
        self::$responseMetadata = [];
    }

    public function versions(string $text, string $sourceLanguage, string $existingPt = '', string $existingEn = ''): array
    {
        $text = trim($text);
        $sourceLanguage = $sourceLanguage === 'en' ? 'en' : 'pt';
        $targetLanguage = $sourceLanguage === 'en' ? 'pt' : 'en';
        $existingPt = trim($existingPt);
        $existingEn = trim($existingEn);

        if ($text === '') {
            return $this->result(
                '',
                '',
                'empty',
                'empty',
                $sourceLanguage
            );
        }

        // Persisted bilingual pairs are authoritative when the active-language
        // value is unchanged. Revisiting a page must not call any external
        // verifier or translator again.
        if ($sourceLanguage === 'en'
            && $existingEn === $text
            && $existingPt !== ''
            && $existingPt !== $existingEn) {
            return $this->result(
                $existingPt,
                $text,
                'reused',
                'reused',
                $sourceLanguage
            );
        }
        if ($sourceLanguage === 'pt'
            && $existingPt === $text
            && $existingEn !== ''
            && $existingEn !== $existingPt) {
            return $this->result(
                $text,
                $existingEn,
                'reused',
                'reused',
                $sourceLanguage
            );
        }

        // The original text is verified before MyMemory is called. MyMemory is
        // only a translator and can never prove the source language.
        $sourceAnalysis = $this->validateSource($text, $sourceLanguage);
        $translated = $this->translateValidated($text, $sourceLanguage, $targetLanguage);

        return $sourceLanguage === 'en'
            ? $this->result(
                $translated['text'],
                $text,
                (string) $sourceAnalysis['conclusion'],
                $translated['conclusion'],
                'en'
            )
            : $this->result(
                $text,
                $translated['text'],
                (string) $sourceAnalysis['conclusion'],
                $translated['conclusion'],
                'pt'
            );
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

        $this->validateSource($text, $source);
        return $this->translateValidated($text, $source, $target)['text'];
    }

    /**
     * Static compatibility helper. Production calls pass the lexical checker;
     * without one it falls back to sentence-level context only.
     */
    public static function isPlausibleTargetText(
        string $text,
        string $targetLanguage,
        ?LexicalLanguageClassifier $lexicalChecker = null
    ): bool {
        return self::targetConclusion($text, $targetLanguage, $lexicalChecker) !== 'wrong';
    }

    /** Returns correct, ambiguous or wrong for the complete translated phrase. */
    public static function targetConclusion(
        string $text,
        string $targetLanguage,
        ?LexicalLanguageClassifier $lexicalChecker = null
    ): string {
        $text = trim($text);
        if ($text === '') {
            return 'wrong';
        }
        $targetLanguage = $targetLanguage === 'pt' ? 'pt' : 'en';

        if ($lexicalChecker !== null) {
            $analysis = LanguageGuard::sourceAnalysis($text, $targetLanguage, $lexicalChecker);
            return match ($analysis['conclusion']) {
                'correct' => 'correct',
                'ambiguous' => 'ambiguous',
                default => 'wrong',
            };
        }

        $detected = LanguageGuard::confidentSentenceLanguage($text);
        if ($detected === null) {
            return 'ambiguous';
        }
        return $detected === $targetLanguage ? 'correct' : 'wrong';
    }

    /**
     * Green feedback reports only what was actually established by the server.
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
            return $sourceLanguage === 'en' ? 'Saved: empty field.' : 'Guardado: campo vazio.';
        }
        if ($sourceConclusion === 'reused') {
            return $sourceLanguage === 'en'
                ? 'Saved: existing bilingual translation reused.'
                : 'Guardado: tradução bilingue existente reutilizada.';
        }

        if ($sourceConclusion === 'correct' && $translationConclusion === 'correct') {
            return $sourceLanguage === 'en'
                ? "Saved: {$sourceLabel} text confirmed; {$targetLabel} translation confirmed."
                : "Guardado: texto {$sourceLabel} confirmado; tradução {$targetLabel} confirmada.";
        }
        if ($sourceConclusion === 'correct') {
            return $sourceLanguage === 'en'
                ? "Saved: {$sourceLabel} text confirmed; ambiguous/technical {$targetLabel} translation accepted."
                : "Guardado: texto {$sourceLabel} confirmado; tradução {$targetLabel} ambígua/técnica aceite.";
        }
        if ($translationConclusion === 'correct') {
            return $sourceLanguage === 'en'
                ? "Saved: shared/technical term accepted; {$targetLabel} translation confirmed."
                : "Guardado: termo técnico/partilhado aceite; tradução {$targetLabel} confirmada.";
        }
        return $sourceLanguage === 'en'
            ? "Saved: shared/technical term accepted; ambiguous/technical {$targetLabel} translation accepted."
            : "Guardado: termo técnico/partilhado aceite; tradução {$targetLabel} ambígua/técnica aceite.";
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSource(string $text, string $sourceLanguage): array
    {
        try {
            return LanguageGuard::validateSource($text, $sourceLanguage, $this->lexicalChecker);
        } catch (LexicalLookupException) {
            throw new InvalidArgumentException(
                $sourceLanguage === 'en'
                    ? 'Not saved: language verification service is unavailable. Please try again.'
                    : 'Não guardado: serviço de verificação linguística indisponível. Tente novamente.'
            );
        }
    }

    /** @return array{text:string, conclusion:string} */
    private function translateValidated(string $text, string $source, string $target): array
    {
        $cached = $this->cachedTranslation($text, $source, $target);
        if ($cached !== null) {
            return $cached;
        }

        if (($this->config['enabled'] ?? true) !== true || !function_exists('curl_init')) {
            throw new InvalidArgumentException(
                $source === 'en'
                    ? 'Not saved: translation API is unavailable.'
                    : 'Não guardado: API de tradução indisponível.'
            );
        }

        $translated = $this->translateFresh($text, $source, $target);
        if ($translated === null || trim($translated) === '') {
            throw new InvalidArgumentException(
                $source === 'en'
                    ? 'Not saved: automatic translation returned no text.'
                    : 'Não guardado: a tradução automática não devolveu texto.'
            );
        }
        if (!self::hasPlausibleLength($text, $translated)) {
            throw new InvalidArgumentException(
                $source === 'en'
                    ? 'Not saved: automatic translation appears incomplete.'
                    : 'Não guardado: a tradução automática parece incompleta.'
            );
        }

        $analysis = $this->targetAnalysis($translated, $target, $source);
        if (!in_array($analysis['conclusion'], ['correct', 'ambiguous'], true)) {
            // Provider language/content errors get one fresh retry. Network and
            // timeout exceptions are thrown by translateChunk and are not retried.
            $retry = $this->translateFresh($text, $source, $target);
            if ($retry !== null && trim($retry) !== '' && self::hasPlausibleLength($text, $retry)) {
                $retryAnalysis = $this->targetAnalysis($retry, $target, $source);
                if (in_array($retryAnalysis['conclusion'], ['correct', 'ambiguous'], true)) {
                    $translated = trim($retry);
                    $analysis = $retryAnalysis;
                }
            }
        }

        if (!in_array($analysis['conclusion'], ['correct', 'ambiguous'], true)) {
            $this->throwTargetValidationError($analysis, $source, $target);
        }

        $translated = trim($translated);
        $this->storeTranslation($text, $translated, $source, $target);
        return [
            'text' => $translated,
            'conclusion' => (string) $analysis['conclusion'],
        ];
    }

    /** @return array<string, mixed> */
    private function targetAnalysis(string $translated, string $target, string $source): array
    {
        try {
            return LanguageGuard::sourceAnalysis($translated, $target, $this->lexicalChecker);
        } catch (LexicalLookupException) {
            throw new InvalidArgumentException(
                $source === 'en'
                    ? 'Not saved: could not verify the translated text. Please try again.'
                    : 'Não guardado: não foi possível verificar a tradução. Tente novamente.'
            );
        }
    }

    /** @param array<string, mixed> $analysis */
    private function throwTargetValidationError(array $analysis, string $source, string $target): never
    {
        $targetLabel = strtoupper($target);
        $oppositeLabel = strtoupper($target === 'pt' ? 'en' : 'pt');
        $isEnglishUi = $source === 'en';

        if (($analysis['conclusion'] ?? '') === 'unknown') {
            $words = is_array($analysis['unknownWords'] ?? null) ? $analysis['unknownWords'] : [];
            $quoted = self::quotedWords($words);
            throw new LanguageValidationException(
                $isEnglishUi
                    ? "Not saved: translation contains unrecognized word(s)" . ($quoted !== '' ? " — {$quoted}." : '.')
                    : "Não guardado: a tradução contém palavra(s) não reconhecida(s)" . ($quoted !== '' ? " — {$quoted}." : '.'),
                $words
            );
        }
        if (($analysis['conclusion'] ?? '') === 'mixed') {
            throw new LanguageValidationException(
                $isEnglishUi
                    ? 'Not saved: translation mixes EN and PT.'
                    : 'Não guardado: a tradução mistura PT e EN.'
            );
        }
        throw new LanguageValidationException(
            $isEnglishUi
                ? "Not saved: translation was returned in {$oppositeLabel} instead of {$targetLabel}."
                : "Não guardado: tradução devolvida em {$oppositeLabel} em vez de {$targetLabel}."
        );
    }

    /** @return array{text:string, conclusion:string}|null */
    private function cachedTranslation(string $text, string $source, string $target): ?array
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
                if (self::hasPlausibleLength($text, $translated)) {
                    $analysis = $this->targetAnalysis($translated, $target, $source);
                    if (in_array($analysis['conclusion'], ['correct', 'ambiguous'], true)) {
                        return [
                            'text' => $translated,
                            'conclusion' => (string) $analysis['conclusion'],
                        ];
                    }
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
                    // A stale cache row must not prevent a fresh translation.
                }
            }
        } catch (InvalidArgumentException | LexicalLookupException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Translation still works when the translation cache is unavailable.
        }
        return null;
    }

    private function storeTranslation(string $text, string $translated, string $source, string $target): void
    {
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
            // A cache failure must never prevent a valid save operation.
        }
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
                    ? 'Not saved: translation API timed out.'
                    : 'Não guardado: API de tradução excedeu o tempo limite.'
            );
        }
        if (!is_string($response) || $errno !== 0 || $status < 200 || $status >= 300) {
            throw new InvalidArgumentException(
                $source === 'en'
                    ? 'Not saved: translation API is temporarily unavailable.'
                    : 'Não guardado: API de tradução temporariamente indisponível.'
            );
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new InvalidArgumentException(
                $source === 'en'
                    ? 'Not saved: translation API returned an invalid response.'
                    : 'Não guardado: API de tradução devolveu uma resposta inválida.'
            );
        }
        $responseStatus = (int) ($data['responseStatus'] ?? 200);
        if ($responseStatus < 200 || $responseStatus >= 300) {
            throw new InvalidArgumentException(
                $source === 'en'
                    ? 'Not saved: translation API rejected the request.'
                    : 'Não guardado: API de tradução rejeitou o pedido.'
            );
        }
        $translated = $data['responseData']['translatedText'] ?? null;
        if (!is_string($translated)) {
            throw new InvalidArgumentException(
                $source === 'en'
                    ? 'Not saved: translation API returned no translated text.'
                    : 'Não guardado: API de tradução não devolveu texto traduzido.'
            );
        }
        $translated = html_entity_decode(trim($translated), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $translated !== '' ? $translated : null;
    }

    private function result(
        string $pt,
        string $en,
        string $sourceConclusion,
        string $translationConclusion,
        string $sourceLanguage
    ): array {
        $message = self::successMessage($sourceConclusion, $translationConclusion, $sourceLanguage);
        $targetLanguage = $sourceLanguage === 'en' ? 'pt' : 'en';
        self::$responseMetadata[] = [
            'sourceLanguage' => $sourceLanguage,
            'targetLanguage' => $targetLanguage,
            'sourceConclusion' => $sourceConclusion,
            'translationConclusion' => $translationConclusion,
            'message' => $message,
        ];
        return [
            'pt' => $pt,
            'en' => $en,
            'sourceConclusion' => $sourceConclusion,
            'translationConclusion' => $translationConclusion,
            'validationMessage' => $message,
        ];
    }

    /** @param string[] $words */
    private static function quotedWords(array $words): string
    {
        return implode(', ', array_map(
            static fn(string $word): string => '“' . $word . '”',
            $words
        ));
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
