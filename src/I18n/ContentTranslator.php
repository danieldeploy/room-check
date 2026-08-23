<?php
declare(strict_types=1);

final class ContentTranslator
{
    private const MYMEMORY_MAX_QUERY_BYTES = 500;

    public function __construct(private array $config)
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

        if ($sourceLanguage === 'en') {
            if ($existingEn === $text && $existingPt !== '') {
                return ['pt' => $existingPt, 'en' => $text];
            }
            return [
                'pt' => $this->translate($text, 'en', 'pt') ?? $text,
                'en' => $text,
            ];
        }

        if ($existingPt === $text && $existingEn !== '') {
            return ['pt' => $text, 'en' => $existingEn];
        }
        return [
            'pt' => $text,
            'en' => $this->translate($text, 'pt', 'en') ?? $text,
        ];
    }

    private function translate(string $text, string $source, string $target): ?string
    {
        if ($text === '') {
            return '';
        }
        if (($this->config['enabled'] ?? true) !== true || !function_exists('curl_init')) {
            return null;
        }

        $translatedChunks = [];
        foreach ($this->chunks($text) as $chunk) {
            $translation = $this->translateChunk($chunk, $source, $target);
            if ($translation === null || $translation === '') {
                return null;
            }
            $translatedChunks[] = $translation;
        }

        return trim(implode("\n", $translatedChunks));
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
