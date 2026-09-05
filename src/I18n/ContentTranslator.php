<?php
declare(strict_types=1);

final class ContentTranslator
{
    private const MAX_TEXT_CHARACTERS = 5000;
    private const MAX_TEXTS_PER_REQUEST = 100;
    private const DEFAULT_ENDPOINT = 'https://translation.googleapis.com/language/translate/v2';
    private const DEFAULT_ENGINE_KEY = 'google-basic-nmt-v2';

    /** @var array<int, array<string, string>> */
    private static array $responseMetadata = [];
    private ?string $resolvedApiKey = null;

    public function __construct(private PDO $pdo, private array $config)
    {
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

    /** @return array{pt:string,en:string,status:string,message:string} */
    public function versions(string $text, string $sourceLanguage, string $existingPt = '', string $existingEn = ''): array
    {
        $text = trim($text);
        $sourceLanguage = $sourceLanguage === 'en' ? 'en' : 'pt';
        $targetLanguage = $sourceLanguage === 'en' ? 'pt' : 'en';
        $existingPt = trim($existingPt);
        $existingEn = trim($existingEn);

        if ($text === '') {
            return $this->result('', '', 'empty', $sourceLanguage);
        }
        if (mb_strlen($text, 'UTF-8') > self::MAX_TEXT_CHARACTERS) {
            throw new InvalidArgumentException(
                $sourceLanguage === 'en'
                    ? 'Not saved: text is longer than 5000 characters.'
                    : 'Não guardado: o texto ultrapassa 5000 caracteres.'
            );
        }

        // An unchanged persisted pair is authoritative and must not call Google.
        if ($sourceLanguage === 'en'
            && $existingEn === $text
            && $existingPt !== ''
            && $existingPt !== $existingEn) {
            return $this->result($existingPt, $text, 'reused', $sourceLanguage);
        }
        if ($sourceLanguage === 'pt'
            && $existingPt === $text
            && $existingEn !== ''
            && $existingEn !== $existingPt) {
            return $this->result($text, $existingEn, 'reused', $sourceLanguage);
        }

        $translation = $this->translatedText($text, $sourceLanguage, $targetLanguage);
        return $sourceLanguage === 'en'
            ? $this->result($translation['text'], $text, $translation['status'], 'en')
            : $this->result($text, $translation['text'], $translation['status'], 'pt');
    }

    public function translateStrict(string $text, string $sourceLanguage, string $targetLanguage): string
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
        if (mb_strlen($text, 'UTF-8') > self::MAX_TEXT_CHARACTERS) {
            throw new InvalidArgumentException('Translation text is longer than 5000 characters.');
        }

        return $this->translatedText($text, $source, $target)['text'];
    }

    /** @return array{text:string,status:string} */
    private function translatedText(string $text, string $source, string $target): array
    {
        $cached = $this->cachedTranslation($text, $source, $target);
        if ($cached !== null) {
            return ['text' => $cached, 'status' => 'cached'];
        }

        if (($this->config['enabled'] ?? true) !== true || !function_exists('curl_init')) {
            throw new InvalidArgumentException(
                $source === 'en'
                    ? 'Not saved: translation service is unavailable.'
                    : 'Não guardado: serviço de tradução indisponível.'
            );
        }
        if ($this->apiKey() === '') {
            throw new InvalidArgumentException(
                $source === 'en'
                    ? 'Not saved: Google translation is not configured.'
                    : 'Não guardado: a tradução Google não está configurada.'
            );
        }

        $prepared = $this->prepareTranslationInput($text);
        $translated = $this->translatePrepared($prepared, $source, $target);
        if ($translated === '') {
            throw new InvalidArgumentException(
                $source === 'en'
                    ? 'Not saved: automatic translation appears incomplete.'
                    : 'Não guardado: a tradução automática parece incompleta.'
            );
        }

        $this->storeTranslation($text, $translated, $source, $target);
        return ['text' => $translated, 'status' => 'translated'];
    }

    private function cachedTranslation(string $text, string $source, string $target): ?string
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT translated_text
                 FROM translation_cache
                 WHERE engine_key = :engine_key
                   AND source_language = :source_language
                   AND target_language = :target_language
                   AND source_hash = :source_hash
                   AND source_text = :source_text
                 LIMIT 1'
            );
            $parameters = $this->cacheParameters($text, $source, $target);
            $statement->execute($parameters);
            $translated = $statement->fetchColumn();
            if (!is_string($translated) || trim($translated) === '') {
                return null;
            }

            $translated = trim($translated);
            if ($this->preservesProtectedSource($text, $translated)) {
                return $translated;
            }

            $delete = $this->pdo->prepare(
                'DELETE FROM translation_cache
                 WHERE engine_key = :engine_key
                   AND source_language = :source_language
                   AND target_language = :target_language
                   AND source_hash = :source_hash
                   AND source_text = :source_text'
            );
            $delete->execute($parameters);
        } catch (Throwable) {
            // Translation remains available if the cache or its migration is unavailable.
        }
        return null;
    }

    private function storeTranslation(string $text, string $translated, string $source, string $target): void
    {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO translation_cache
                    (engine_key, source_language, target_language, source_hash, source_text, translated_text)
                 VALUES
                    (:engine_key, :source_language, :target_language, :source_hash, :source_text, :translated_text)
                 ON DUPLICATE KEY UPDATE
                    source_text = VALUES(source_text),
                    translated_text = VALUES(translated_text),
                    updated_at = CURRENT_TIMESTAMP'
            );
            $statement->execute($this->cacheParameters($text, $source, $target) + [
                'translated_text' => $translated,
            ]);
        } catch (Throwable) {
            // A cache failure must never prevent a valid bilingual save.
        }
    }

    /** @return array<string,string> */
    private function cacheParameters(string $text, string $source, string $target): array
    {
        $engineKey = trim((string) ($this->config['engine_key'] ?? self::DEFAULT_ENGINE_KEY));
        return [
            'engine_key' => $engineKey !== '' ? $engineKey : self::DEFAULT_ENGINE_KEY,
            'source_language' => $source,
            'target_language' => $target,
            'source_hash' => hash('sha256', $text),
            'source_text' => $text,
        ];
    }

    /** @return array{text:string,protected:array<string,string>} */
    private function prepareTranslationInput(string $text): array
    {
        $protected = [];
        $counter = 0;
        $placeholder = static function (string $value) use (&$protected, &$counter): string {
            $token = 'RoomCheckKeep' . self::alphabeticCounter($counter++) . 'Token';
            $protected[$token] = $value;
            return $token;
        };

        $working = preg_replace_callback(
            '/"[^"\r\n]*"|“[^”\r\n]*”/u',
            static fn(array $match): string => $placeholder((string) $match[0]),
            $text
        );
        if (!is_string($working)) {
            $working = $text;
        }

        $withNumbers = preg_replace_callback(
            '/\p{N}+(?:[.,:\/\-]\p{N}+)*/u',
            static fn(array $match): string => $placeholder((string) $match[0]),
            $working
        );
        if (is_string($withNumbers)) {
            $working = $withNumbers;
        }

        return ['text' => $working, 'protected' => $protected];
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

    /** @param array{text:string,protected:array<string,string>} $prepared */
    private function translatePrepared(array $prepared, string $source, string $target): string
    {
        $translated = $this->translateKeepingLineBreaks($prepared['text'], $source, $target);
        foreach ($prepared['protected'] as $token => $original) {
            $count = 0;
            $translated = str_ireplace($token, $original, $translated, $count);
            if ($count < 1) {
                throw new InvalidArgumentException(
                    $source === 'en'
                        ? 'Not saved: automatic translation changed protected content.'
                        : 'Não guardado: a tradução automática alterou conteúdo protegido.'
                );
            }
        }
        return trim($translated);
    }

    private function translateKeepingLineBreaks(string $text, string $source, string $target): string
    {
        $parts = preg_split('/(\R)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            $parts = [$text];
        }

        $requests = [];
        $requestIndexes = [];
        $spacing = [];
        foreach ($parts as $index => $part) {
            if ($part === '' || preg_match('/^\R$/u', $part) === 1 || trim($part) === '') {
                continue;
            }
            preg_match('/^(\s*)(.*?)(\s*)$/us', $part, $match);
            $spacing[$index] = [(string) ($match[1] ?? ''), (string) ($match[3] ?? '')];
            $requests[] = (string) ($match[2] ?? $part);
            $requestIndexes[] = $index;
        }

        $translatedParts = [];
        foreach (array_chunk($requests, self::MAX_TEXTS_PER_REQUEST) as $batch) {
            array_push($translatedParts, ...$this->translateBatch($batch, $source, $target));
        }
        if (count($translatedParts) !== count($requestIndexes)) {
            throw new InvalidArgumentException(
                $source === 'en'
                    ? 'Not saved: translation service returned an invalid response.'
                    : 'Não guardado: serviço de tradução devolveu uma resposta inválida.'
            );
        }

        foreach ($requestIndexes as $position => $partIndex) {
            [$prefix, $suffix] = $spacing[$partIndex];
            $parts[$partIndex] = $prefix . $translatedParts[$position] . $suffix;
        }
        return implode('', $parts);
    }

    /** @param string[] $texts @return string[] */
    private function translateBatch(array $texts, string $source, string $target): array
    {
        if ($texts === []) {
            return [];
        }

        $endpoint = trim((string) ($this->config['endpoint'] ?? self::DEFAULT_ENDPOINT));
        if ($endpoint === '') {
            $endpoint = self::DEFAULT_ENDPOINT;
        }
        $endpoint = rtrim($endpoint, '?&');
        $apiKey = $this->apiKey();
        $url = $endpoint . (str_contains($endpoint, '?') ? '&' : '?')
            . http_build_query(['key' => $apiKey], '', '&', PHP_QUERY_RFC3986);
        $payload = json_encode([
            'q' => array_values($texts),
            'source' => self::providerLanguage($source),
            'target' => self::providerLanguage($target),
            'format' => 'text',
            'model' => 'nmt',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $curl = curl_init($url);
        if ($curl === false) {
            throw new InvalidArgumentException(
                $source === 'en'
                    ? 'Not saved: translation service is unavailable.'
                    : 'Não guardado: serviço de tradução indisponível.'
            );
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => max(3, (int) ($this->config['timeout_seconds'] ?? 12)),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json; charset=utf-8',
            ],
            CURLOPT_USERAGENT => 'RoomCheck/1.0 translation',
        ]);
        $response = curl_exec($curl);
        $errno = curl_errno($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            throw new InvalidArgumentException(
                $source === 'en'
                    ? 'Not saved: translation service timed out.'
                    : 'Não guardado: serviço de tradução excedeu o tempo limite.'
            );
        }
        if (!is_string($response) || $errno !== 0 || $status < 200 || $status >= 300) {
            throw new InvalidArgumentException(
                $source === 'en'
                    ? 'Not saved: translation service is temporarily unavailable.'
                    : 'Não guardado: serviço de tradução temporariamente indisponível.'
            );
        }

        $data = json_decode($response, true);
        $translations = is_array($data) ? ($data['data']['translations'] ?? null) : null;
        if (!is_array($translations) || count($translations) !== count($texts)) {
            throw new InvalidArgumentException(
                $source === 'en'
                    ? 'Not saved: translation service returned an invalid response.'
                    : 'Não guardado: serviço de tradução devolveu uma resposta inválida.'
            );
        }

        $results = [];
        foreach ($translations as $translation) {
            $translated = is_array($translation) ? ($translation['translatedText'] ?? null) : null;
            if (!is_string($translated) || trim($translated) === '') {
                throw new InvalidArgumentException(
                    $source === 'en'
                        ? 'Not saved: automatic translation returned no text.'
                        : 'Não guardado: a tradução automática não devolveu texto.'
                );
            }
            $results[] = html_entity_decode(trim($translated), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $results;
    }

    private static function providerLanguage(string $language): string
    {
        return $language === 'pt' ? 'pt-PT' : 'en';
    }

    private function apiKey(): string
    {
        if ($this->resolvedApiKey !== null) {
            return $this->resolvedApiKey;
        }

        $configured = trim((string) ($this->config['api_key'] ?? ''));
        if ($configured !== '') {
            return $this->resolvedApiKey = $configured;
        }

        $path = trim((string) ($this->config['secrets_file'] ?? ''));
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return $this->resolvedApiKey = '';
        }

        try {
            $contents = file_get_contents($path);
            $secret = is_string($contents) ? json_decode($contents, true, 8, JSON_THROW_ON_ERROR) : null;
            $apiKey = is_array($secret) ? trim((string) ($secret['api_key'] ?? '')) : '';
            return $this->resolvedApiKey = $apiKey;
        } catch (Throwable) {
            return $this->resolvedApiKey = '';
        }
    }

    private function preservesProtectedSource(string $source, string $translated): bool
    {
        preg_match_all('/"[^"\r\n]*"|“[^”\r\n]*”|\p{N}+(?:[.,:\/\-]\p{N}+)*/u', $source, $matches);
        foreach (($matches[0] ?? []) as $protected) {
            if (!str_contains($translated, (string) $protected)) {
                return false;
            }
        }
        return true;
    }

    /** @return array{pt:string,en:string,status:string,message:string} */
    private function result(string $pt, string $en, string $status, string $sourceLanguage): array
    {
        $message = self::successMessage($status, $sourceLanguage);
        self::$responseMetadata[] = [
            'sourceLanguage' => $sourceLanguage,
            'targetLanguage' => $sourceLanguage === 'en' ? 'pt' : 'en',
            'status' => $status,
            'message' => $message,
        ];
        self::publishResponseMetadataHeader();
        return ['pt' => $pt, 'en' => $en, 'status' => $status, 'message' => $message];
    }

    private static function successMessage(string $status, string $sourceLanguage): string
    {
        $english = $sourceLanguage === 'en';
        return match ($status) {
            'empty' => $english ? 'Saved: empty field.' : 'Guardado: campo vazio.',
            'reused' => $english
                ? 'Saved: existing translation reused.'
                : 'Guardado: tradução existente reutilizada.',
            'cached' => $english
                ? 'Saved: cached translation reused.'
                : 'Guardado: tradução em cache reutilizada.',
            default => $english ? 'Saved and translated.' : 'Guardado e traduzido.',
        };
    }

    private static function publishResponseMetadataHeader(): void
    {
        if (headers_sent() || self::$responseMetadata === []) {
            return;
        }
        $json = json_encode(self::$responseMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }
        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        header('X-Room-Translation-Results: ' . $encoded, true);
    }
}
