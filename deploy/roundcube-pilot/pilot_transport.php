<?php
class welcome_smtp2go_pilot_error extends RuntimeException {}

class welcome_smtp2go_pilot_transport
{
    const PRIVATE_DIR = '/home/welcome/roundcube-smtp2go-private';
    const DEADLINE = 1791133200; // 2026-10-04 17:00 UTC / 18:00 Lisbon

    public static function check_time($expires, $now = null)
    {
        if ($expires !== self::DEADLINE || ($now ?? time()) >= $expires) {
            throw new welcome_smtp2go_pilot_error('A janela deste piloto terminou. Não reenvies esta mensagem.');
        }
    }

    public static function validate_envelope(array $headers, $from, $mailto, callable $addresses, array $recipients)
    {
        $h = array_change_key_case($headers, CASE_LOWER);
        if (strtolower((string) $from) !== 'info@welcomehostel.pt'
            || $addresses($h['from'] ?? '') !== ['info@welcomehostel.pt']) {
            throw new welcome_smtp2go_pilot_error('Este piloto permite apenas info@welcomehostel.pt.');
        }
        foreach (['gmail', 'outlook'] as $name) {
            if (!isset($recipients[$name]) || !preg_match('/^[a-f0-9]{64}$/D', $recipients[$name])) {
                throw new welcome_smtp2go_pilot_error('Destinatários do piloto não configurados.');
            }
        }
        if ($recipients['gmail'] === $recipients['outlook']) {
            throw new welcome_smtp2go_pilot_error('Destinatários do piloto inválidos.');
        }
        foreach (array_keys($h) as $name) {
            if (strpos($name, 'resent-') === 0) {
                throw new welcome_smtp2go_pilot_error('Cabeçalhos Resent não suportados no piloto.');
            }
        }
        $subject = $h['subject'] ?? '';
        $slots = ['WELCOME-PILOT-20261004-01' => '01', 'WELCOME-PILOT-20261004-02' => '02'];
        if (!is_string($subject) || !isset($slots[$subject])) {
            throw new welcome_smtp2go_pilot_error('Usa o assunto exato WELCOME-PILOT-20261004-01 ou WELCOME-PILOT-20261004-02.');
        }
        $slot = $slots[$subject];
        $expected = $slot === '01'
            ? ['to' => [$recipients['gmail']], 'cc' => [], 'bcc' => [$recipients['outlook']]]
            : ['to' => [$recipients['outlook']], 'cc' => [$recipients['gmail']], 'bcc' => []];
        foreach ($expected as $field => $hashes) {
            $actual = $addresses($h[$field] ?? '');
            $actualHashes = array_map(static function ($email) { return hash('sha256', $email); }, $actual);
            if ($actualHashes !== $hashes) {
                throw new welcome_smtp2go_pilot_error('Destinatários diferentes do plano: teste 01 Para Gmail + BCC Outlook; teste 02 Para Outlook + CC Gmail.');
            }
        }
        if ($addresses($mailto) !== $addresses($h['to'] ?? '')) {
            throw new welcome_smtp2go_pilot_error('Destinatários MIME diferentes do envelope Roundcube.');
        }
        return $slot;
    }

    public function send($mime, $slot, $expires)
    {
        self::check_time($expires);
        if (!is_string($mime) || strlen($mime) > 5 * 1024 * 1024) {
            throw new welcome_smtp2go_pilot_error('Este piloto permite mensagens até 5 MiB.');
        }
        $dir = self::PRIVATE_DIR;
        if (realpath($dir) !== $dir || is_link($dir) || (fileperms($dir) & 0077)) {
            throw new welcome_smtp2go_pilot_error('Pasta privada ausente ou com permissões inseguras.');
        }
        // Reuse the already verified key without exporting it. The legacy filename
        // stays unchanged. The operator must set this key to Allowed for live delivery.
        $keyfile = $dir . '/sandbox-key.txt';
        if (!is_file($keyfile) || is_link($keyfile) || (fileperms($keyfile) & 0077)) {
            throw new welcome_smtp2go_pilot_error('Chave privada ausente ou com permissões inseguras.');
        }
        $key = trim((string) file_get_contents($keyfile));
        if (!preg_match('/^api-[A-Za-z0-9]{32}$/D', $key) || !function_exists('curl_init')) {
            throw new welcome_smtp2go_pilot_error('Chave privada ou extensão cURL indisponível.');
        }
        $payload = json_encode(['mime_email' => base64_encode($mime), 'fastaccept' => false]);
        return $this->once($dir, $slot, static function () use ($payload, $key, $expires) {
            self::check_time($expires);
            $ch = curl_init('https://api.smtp2go.com/v3/email/mime');
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Smtp2go-Api-Key: ' . $key],
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            ]);
            $response = curl_exec($ch);
            $errno = curl_errno($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return self::classify($status, $errno, is_string($response) ? $response : '');
        });
    }

    public static function classify($http, $errno, $body)
    {
        $json = json_decode($body, true);
        $data = is_array($json) ? ($json['data']['email_response'] ?? $json['email_response'] ?? $json['data'] ?? []) : [];
        $ok = !$errno && $http === 200 && is_array($data)
            && isset($data['succeeded'], $data['failed']) && $data['succeeded'] >= 1
            && $data['failed'] == 0 && empty($data['failures']) && empty($data['error_code'])
            && !empty($data['email_id']);
        return ['state' => $ok ? 'accepted' : 'review_required', 'http' => $http,
            'curl_errno' => $errno, 'time_utc' => gmdate('c'),
            'email_id' => $ok ? (string) $data['email_id'] : null];
    }

    public function once($dir, $slot, callable $request)
    {
        if (!in_array($slot, ['01', '02'], true)) {
            throw new welcome_smtp2go_pilot_error('Identificador do piloto inválido.');
        }
        $path = $dir . '/pilot-20261004-' . $slot . '.json';
        if (is_link($path)) { throw new welcome_smtp2go_pilot_error('Registo privado inválido.'); }
        $mask = umask(0077);
        $fp = fopen($path, 'c+');
        umask($mask);
        if (!$fp) { throw new welcome_smtp2go_pilot_error('Não foi possível abrir o registo privado.'); }
        try {
            if (!flock($fp, LOCK_EX | LOCK_NB)) {
                throw new welcome_smtp2go_pilot_error('Este teste já está em processamento.');
            }
            $stat = fstat($fp);
            if (($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0077)) {
                throw new welcome_smtp2go_pilot_error('Permissões inseguras no registo privado.');
            }
            if ($stat['size'] > 0) {
                throw new welcome_smtp2go_pilot_error('Este teste já teve uma tentativa. Verifica Activity e as caixas de destino antes de repetir.');
            }
            $this->record($fp, ['state' => 'attempt_started', 'time_utc' => gmdate('c')]);
            $result = $request();
            $this->record($fp, $result);
            if ($result['state'] !== 'accepted') {
                throw new welcome_smtp2go_pilot_error('API sem sucesso confirmado. Não reenvies; verifica o registo privado.');
            }
            return $result;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private function record($fp, array $data)
    {
        fseek($fp, 0, SEEK_END);
        $line = json_encode($data) . "\n";
        if (fwrite($fp, $line) !== strlen($line) || !fflush($fp)) {
            throw new welcome_smtp2go_pilot_error('Não foi possível guardar o registo; verifica antes de reenviar.');
        }
    }
}
