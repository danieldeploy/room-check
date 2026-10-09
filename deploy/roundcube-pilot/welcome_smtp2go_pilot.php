<?php
/** Two-message live pilot. Ordinary messages retain their existing transport. */
class welcome_smtp2go_pilot extends rcube_plugin
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
        // Retain the hook after expiry so a stale pilot compose never falls back to SMTP.
        $this->add_hook('message_before_send', [$this, 'send']);
        if ($this->rc->config->get('welcome_smtp2go_pilot_enabled', false)
            && time() < (int) $this->rc->config->get('welcome_smtp2go_pilot_expires', 0)) {
            $this->include_script('pilot.js');
        }
    }

    public function send($args)
    {
        require_once __DIR__ . '/pilot_transport.php';
        try {
            $headers = $args['message']->headers();
            $subject = rcube_mime::decode_header($headers['Subject'] ?? '', 'UTF-8');
            if (strpos($subject, 'WELCOME-PILOT-') !== 0) {
                return $args;
            }
            $args['abort'] = true;
            $args['result'] = false;
            if (!defined('RCMAIL_VERSION') || RCMAIL_VERSION !== '1.6.19'
                || !$this->rc->config->get('welcome_smtp2go_pilot_enabled', false)) {
                throw new welcome_smtp2go_pilot_error('Piloto indisponível nesta configuração.');
            }
            $expires = (int) $this->rc->config->get('welcome_smtp2go_pilot_expires', 0);
            welcome_smtp2go_pilot_transport::check_time($expires);
            if (!empty($args['options']['dsn'])) {
                throw new welcome_smtp2go_pilot_error('Desativa o pedido de confirmação de entrega neste piloto.');
            }
            $addresses = static function ($value) {
                $result = [];
                foreach ((array) $value as $part) {
                    foreach (rcube_mime::decode_address_list($part, null, false, null, true) as $email) {
                        $result[] = strtolower($email);
                    }
                }
                return array_values(array_unique($result));
            };
            $headers['Subject'] = $subject;
            $slot = welcome_smtp2go_pilot_transport::validate_envelope(
                $headers, $args['from'], $args['mailto'], $addresses,
                (array) $this->rc->config->get('welcome_smtp2go_pilot_recipients', [])
            );
            $mime = $args['message']->getMessage();
            if (!is_string($mime) || strpos($mime, "\r\n\r\n") === false) {
                throw new welcome_smtp2go_pilot_error('Mensagem MIME inválida.');
            }
            $transport = new welcome_smtp2go_pilot_transport();
            // Preserve Bcc for the SMTP2GO MIME envelope parser. Recipient copies
            // must be inspected before promoting this pilot to normal sending.
            $transport->send($mime, $slot, $expires);
            $args['result'] = true;
            $body = substr($mime, strpos($mime, "\r\n\r\n") + 4);
            $this->rc->plugins->exec_hook('message_sent', [
                'headers' => $headers, 'body' => $body, 'message' => $args['message'],
            ]);
        } catch (Throwable $error) {
            $args['abort'] = true;
            $args['result'] = false;
            $args['error'] = $error instanceof welcome_smtp2go_pilot_error
                ? $error->getMessage() : 'Piloto interrompido. Verifica o registo privado antes de reenviar.';
        }
        return $args;
    }
}
