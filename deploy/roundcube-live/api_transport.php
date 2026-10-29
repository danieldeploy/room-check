<?php
class welcome_smtp2go_api_error extends RuntimeException {}

class welcome_smtp2go_api_transport
{
    const PRIVATE_DIR = '/home/welcome/roundcube-smtp2go-private';
    const JOURNAL_DIR = self::PRIVATE_DIR . '/live';
    const MAX_MIME_BYTES = 10 * 1024 * 1024;

    public static function validate_envelope(array $headers, $from, $mailto, callable $addresses)
    {
        $h = array_change_key_case($headers, CASE_LOWER);
        if (strtolower((string) $from) !== 'info@welcomehostel.pt'
            || $addresses($h['from'] ?? '') !== ['info@welcomehostel.pt']) {
            throw new welcome_smtp2go_api_error('Usa a identidade info@welcomehostel.pt para enviar.');
        }
        foreach (array_keys($h) as $name) {
            if (strpos($name, 'resent-') === 0) {
                throw new welcome_smtp2go_api_error('Reenvio com cabeçalhos Resent não suportado. Usa Encaminhar.');
            }
        }
        $subject = (string) ($h['subject'] ?? '');
        if (preg_match('/^WELCOME-(PILOT|SANDBOX)-/D', $subject)) {
            throw new welcome_smtp2go_api_error('Este assunto pertence a um teste encerrado. Não reenvies o teste.');
        }
        $count = 0;
        foreach (['to', 'cc', 'bcc'] as $field) {
            $list = $addresses($h[$field] ?? '');
            if (count($list) > 100) {
                throw new welcome_smtp2go_api_error('Máximo de 100 destinatários por campo Para, Cc ou Bcc.');
            }
            $count += count($list);
        }
        if (!$count || $addresses($mailto) !== $addresses($h['to'] ?? '')) {
            throw new welcome_smtp2go_api_error('Destinatários da mensagem inválidos ou inconsistentes.');
        }
        $id = $h['message-id'] ?? '';
        self::check_message_id($id);
        return $id;
    }

    public static function check_message_id($id)
    {
        if (!is_string($id) || !preg_match('/^<[^<>\\s@]{1,200}@[^<>\\s@]{1,200}>$/D', $id)) {
            throw new welcome_smtp2go_api_error('Identificador da mensagem inválido. Cria uma nova mensagem.');
        }
    }

    public function send($mime, $messageId)
    {
        self::check_message_id($messageId);
        if (!is_string($mime) || strlen($mime) > self::MAX_MIME_BYTES) {
            throw new welcome_smtp2go_api_error('A mensagem completa, incluindo anexos, pode ter até 10 MiB.');
        }
        $dir = self::PRIVATE_DIR;
        if (realpath($dir) !== $dir || is_link($dir) || (fileperms($dir) & 0077)) {
            throw new welcome_smtp2go_api_error('Pasta privada ausente ou com permissões inseguras.');
        }
        // Reuse the verified private key without exporting it or changing its filename.
        $journalDir = self::JOURNAL_DIR;
        if (realpath($journalDir) !== $journalDir || is_link($journalDir) || (fileperms($journalDir) & 0077)) {
            throw new welcome_smtp2go_api_error('Registo privado de envio indisponível.');
        }
        $keyfile = $dir . '/sandbox-key.txt';
        if (!is_file($keyfile) || is_link($keyfile) || (fileperms($keyfile) & 0077)) {
            throw new welcome_smtp2go_api_error('Chave privada ausente ou com permissões inseguras.');
        }
        $key = trim((string) file_get_contents($keyfile));
        if (!preg_match('/^api-[A-Za-z0-9]{32}$/D', $key) || !function_exists('curl_init')) {
            throw new welcome_smtp2go_api_error('Chave privada ou extensão cURL indisponível.');
        }
        $payload = json_encode(['mime_email' => base64_encode($mime), 'fastaccept' => false]);
        return $this->once($journalDir, $messageId, static function () use ($payload, $key) {
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

    public function once($dir, $messageId, callable $request)
    {
        self::check_message_id($messageId);
        $path = $dir . '/' . hash('sha256', $messageId) . '.json';
        if (is_link($path)) { throw new welcome_smtp2go_api_error('Registo privado inválido.'); }
        $mask = umask(0077);
        $fp = fopen($path, 'c+');
        umask($mask);
        if (!$fp) { throw new welcome_smtp2go_api_error('Não foi possível abrir o registo privado.'); }
        try {
            if (!flock($fp, LOCK_EX | LOCK_NB)) {
                throw new welcome_smtp2go_api_error('Esta mensagem já está em processamento.');
            }
            $stat = fstat($fp);
            if (($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0077)) {
                throw new welcome_smtp2go_api_error('Permissões inseguras no registo privado.');
            }
            if ($stat['size'] > 0) {
                throw new welcome_smtp2go_api_error('Esta mensagem já teve uma tentativa de envio. Verifica Enviados e o estado no fornecedor antes de criar outra.');
            }
            $this->record($fp, ['state' => 'attempt_started', 'time_utc' => gmdate('c')]);
            $result = $request();
            $this->record($fp, $result);
            if ($result['state'] !== 'accepted') {
                throw new welcome_smtp2go_api_error('Envio sem confirmação. Não repitas a mensagem; pede a verificação do registo de envio.');
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
            throw new welcome_smtp2go_api_error('Não foi possível guardar o registo; verifica antes de reenviar.');
        }
    }
}
