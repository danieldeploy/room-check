<?php
/** Live MIME transport for the verified Welcome mailbox only. */
class welcome_smtp2go_api extends rcube_plugin
{
    public $task = 'mail';
    private $rc;

    public function init()
    {
        $this->rc = rcmail::get_instance();
        if (strtolower((string) $this->rc->user->get_username()) !== 'info@welcomehostel.pt') {
            return;
        }
        $this->load_config();
        // Keep the hook when disabled: never fall back silently to the old SMTP route.
        $this->add_hook('message_before_send', [$this, 'send']);
    }

    protected function transport()
    {
        return new welcome_smtp2go_api_transport();
    }

    public function send($args)
    {
        $args['abort'] = true;
        $args['result'] = false;
        $accepted = false;
        try {
            require_once __DIR__ . '/api_transport.php';
            if (!defined('RCMAIL_VERSION') || RCMAIL_VERSION !== '1.6.19'
                || strtolower((string) $this->rc->user->get_username()) !== 'info@welcomehostel.pt'
                || !$this->rc->config->get('welcome_smtp2go_api_enabled', false)) {
                throw new welcome_smtp2go_api_error('Envio temporariamente indisponível nesta configuração.');
            }
            if (!empty($args['options']['dsn'])) {
                throw new welcome_smtp2go_api_error('Desativa o pedido de confirmação de entrega para enviar.');
            }
            $addresses = static function ($value) {
                $result = [];
                foreach ((array) $value as $part) {
                    foreach (rcube_mime::decode_address_list($part, null, false, null, true) as $email) {
                        if ($email !== '') { $result[] = strtolower($email); }
                    }
                }
                return array_values(array_unique($result));
            };
            $headers = $args['message']->headers();
            $headers['Subject'] = rcube_mime::decode_header($headers['Subject'] ?? '', 'UTF-8');
            $messageId = welcome_smtp2go_api_transport::validate_envelope(
                $headers, $args['from'], $args['mailto'], $addresses
            );
            $mime = $args['message']->getMessage();
            if (!is_string($mime) || strpos($mime, "\r\n\r\n") === false) {
                throw new welcome_smtp2go_api_error('Não foi possível preparar a mensagem.');
            }
            // SMTP2GO extracts To/Cc/Bcc from this MIME and removes Bcc on delivery.
            // Keep the original Mail_mime object for Roundcube's Sent-folder save.
            $this->transport()->send($mime, $messageId);
            $accepted = true;
            $args['result'] = true;
            $body = substr($mime, strpos($mime, "\r\n\r\n") + 4);
            $this->rc->plugins->exec_hook('message_sent', [
                'headers' => $headers, 'body' => $body, 'message' => $args['message'],
            ]);
        } catch (Throwable $error) {
            if ($accepted) {
                // A later hook error must not turn a successful send into a retry.
                rcube::write_log('errors', 'welcome_smtp2go_api: message_sent hook failed after acceptance');
                $args['result'] = true;
            } else {
                $args['error'] = $error instanceof welcome_smtp2go_api_error
                    ? $error->getMessage() : 'Envio interrompido. Pede a verificação do registo antes de repetir.';
            }
        }
        return $args;
    }
}
