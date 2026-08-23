<?php
declare(strict_types=1);

final class ContentTranslator
{
    public function __construct(private array $config)
    {
    }

    public function versions(string $text, string $sourceLanguage, string $existingPt = '', string $existingEn = ''): array
    {
        $text = trim($text);
        if ($sourceLanguage === 'en') {
            return [
                'pt' => $this->translate($text, 'en', 'pt') ?? ($existingPt !== '' ? $existingPt : $text),
                'en' => $text,
            ];
        }
        return [
            'pt' => $text,
            'en' => $this->translate($text, 'pt', 'en') ?? ($existingEn !== '' ? $existingEn : $text),
        ];
    }

    private function translate(string $text, string $source, string $target): ?string
    {
        if ($text === '') {
            return '';
        }
        $apiKey = trim((string) ($this->config['api_key'] ?? ''));
        if ($apiKey === '' || !function_exists('curl_init')) {
            return null;
        }
        $language = $target === 'en' ? 'English' : 'European Portuguese';
        $payload = json_encode([
            'model' => (string) ($this->config['model'] ?? 'gpt-5.6'),
            'instructions' => "Translate operational hotel verification instructions into {$language}. Preserve meaning, dates, names, line breaks and concise imperative style. Return only the translation.",
            'input' => $text,
            'max_output_tokens' => 1000,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return null;
        }
        $curl = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ($this->config['timeout_seconds'] ?? 25),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
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
        if (isset($data['output_text']) && is_string($data['output_text'])) {
            return trim($data['output_text']);
        }
        foreach (($data['output'] ?? []) as $output) {
            foreach (($output['content'] ?? []) as $content) {
                if (isset($content['text']) && is_string($content['text'])) {
                    return trim($content['text']);
                }
            }
        }
        return null;
    }
}
