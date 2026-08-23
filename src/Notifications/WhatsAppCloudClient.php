<?php
declare(strict_types=1);

final class WhatsAppCloudClient
{
    public function __construct(private array $config) {}

    public function sendTemplate(string $mobile, array $values): string
    {
        if (!function_exists('curl_init')) throw new RuntimeException('A extensão PHP cURL não está disponível.');
        $secrets = $this->loadSecrets();
        $to = preg_replace('/\D+/', '', $mobile) ?: '';
        if (strlen($to) === 9) $to = (string) ($this->config['default_country_code'] ?? '351') . $to;
        if (strlen($to) < 8 || strlen($to) > 15) throw new RuntimeException('Número de telemóvel inválido.');
        $parameters = array_map(static fn(string $text): array => ['type' => 'text', 'text' => $text], $values);
        $payload = [
            'messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template',
            'template' => [
                'name' => (string) $this->config['template_name'],
                'language' => ['code' => (string) $this->config['template_language']],
                'components' => [['type' => 'body', 'parameters' => $parameters]],
            ],
        ];
        $url = 'https://graph.facebook.com/' . rawurlencode((string) $this->config['graph_version'])
            . '/' . rawurlencode((string) $secrets['phone_number_id']) . '/messages';
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $secrets['access_token'], 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        $body = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl); curl_close($curl);
        $decoded = is_string($body) ? json_decode($body, true) : null;
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
            throw new RuntimeException($message !== '' ? $message : ($error !== '' ? $error : "Meta devolveu HTTP {$status}."));
        }
        $id = is_array($decoded) ? (string) ($decoded['messages'][0]['id'] ?? '') : '';
        if ($id === '') throw new RuntimeException('A Meta não devolveu o identificador da mensagem.');
        return $id;
    }

    private function loadSecrets(): array
    {
        $path = (string) ($this->config['secrets_file'] ?? '');
        $data = $path !== '' && is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($data) || empty($data['phone_number_id']) || empty($data['access_token'])) {
            throw new RuntimeException('Credenciais WhatsApp Cloud API não configuradas.');
        }
        return $data;
    }
}
