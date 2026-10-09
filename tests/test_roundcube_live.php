<?php
require __DIR__ . '/../deploy/roundcube-live/api_transport.php';
function check($value, $message) { if (!$value) { throw new RuntimeException($message); } }
function refuses(callable $call) {
    try { $call(); } catch (welcome_smtp2go_api_error $e) { return; }
    throw new RuntimeException('Expected controlled refusal');
}
$addresses = static function ($value) {
    return $value === '' || $value === 'undisclosed-recipients:;' ? [] : explode(',', strtolower($value));
};
$headers = ['From' => 'info@welcomehostel.pt', 'To' => 'first@example.com',
    'Cc' => 'second@example.com', 'Bcc' => 'third@example.com', 'Subject' => 'Reserva – informação',
    'Message-ID' => '<normal-1@welcomehostel.pt>'];
$validate = static function ($h, $from = 'info@welcomehostel.pt', $to = 'first@example.com') use ($addresses) {
    return welcome_smtp2go_api_transport::validate_envelope($h, $from, $to, $addresses);
};
check($validate($headers) === $headers['Message-ID'], 'Normal arbitrary recipients rejected');
$bcc = $headers; $bcc['To'] = 'undisclosed-recipients:;'; unset($bcc['Cc']);
check($validate($bcc, 'info@welcomehostel.pt', 'undisclosed-recipients:;') === $headers['Message-ID'], 'Bcc-only rejected');
foreach (['From' => 'other@example.com', 'Resent-To' => 'other@example.com',
          'Subject' => 'WELCOME-PILOT-20261004-02', 'Message-ID' => "<bad id@example.com>",
          'Bcc' => implode(',', array_fill(0, 101, 'a@example.com'))] as $key => $value) {
    $bad = $headers; $bad[$key] = $value;
    refuses(static function () use ($validate, $bad) { $validate($bad); });
}
refuses(static function () use ($validate, $headers) { $validate($headers, 'other@example.com'); });
refuses(static function () use ($validate, $headers) { $validate($headers, 'info@welcomehostel.pt', 'other@example.com'); });
foreach (["<a\r\nb@example.com>", '<missing-at>', '', [], '<a@b@c>'] as $id) {
    refuses(static function () use ($id) { welcome_smtp2go_api_transport::check_message_id($id); });
}
$ok = json_encode(['data' => ['email_response' => ['succeeded' => 3, 'failed' => 0, 'email_id' => 'fixture-id']]]);
foreach ([[200, 28, $ok], [503, 0, $ok], [200, 0, '{}'], [200, 0, 'bad-json'],
    [200, 0, '{"data":{"succeeded":2,"failed":1,"email_id":"x"}}']] as $args) {
    check(welcome_smtp2go_api_transport::classify(...$args)['state'] === 'review_required', 'Uncertain send accepted');
}
$dir = sys_get_temp_dir() . '/roundcube-live-' . bin2hex(random_bytes(8)); mkdir($dir, 0700);
$transport = new welcome_smtp2go_api_transport(); $calls = 0;
$request = static function () use (&$calls, $ok) { $calls++; return welcome_smtp2go_api_transport::classify(200, 0, $ok); };
try {
    $transport->once($dir, '<same@id.test>', $request);
    refuses(static function () use ($transport, $dir, $request) { $transport->once($dir, '<same@id.test>', $request); });
    check($calls === 1, 'Duplicate submitted');
    $failure = static function () use (&$calls) { $calls++; return welcome_smtp2go_api_transport::classify(0, 28, 'private-response'); };
    refuses(static function () use ($transport, $dir, $failure) { $transport->once($dir, '<timeout@id.test>', $failure); });
    refuses(static function () use ($transport, $dir, $request) { $transport->once($dir, '<timeout@id.test>', $request); });
    check($calls === 2, 'Timeout retried');
    $transport->once($dir, '<new@id.test>', $request); check($calls === 3, 'New ordinary mail refused');
    foreach (glob($dir . '/*') as $file) {
        check((fileperms($file) & 0077) === 0, 'Journal exposed');
        check(strpos(file_get_contents($file), 'private-response') === false, 'Response leaked');
    }
} finally { foreach (glob($dir . '/*') as $file) { unlink($file); } rmdir($dir); }

class rcube_plugin {
    public $hooks = [];
    public function load_config() {}
    public function add_hook($name, $handler) { $this->hooks[$name] = $handler; }
}
class rcmail { public static $instance; public static function get_instance() { return self::$instance; } }
class rcube { public static function write_log($name, $message) {} }
class rcube_mime {
    public static function decode_header($value, $charset) { return $value; }
    public static function decode_address_list($value, ...$unused) {
        return $value === '' || $value === 'undisclosed-recipients:;' ? [] : explode(',', $value);
    }
}
define('RCMAIL_VERSION', '1.6.19');
require __DIR__ . '/../deploy/roundcube-live/welcome_smtp2go_api.php';
$config = new class {
    public $enabled = true;
    public function get($name, $default = null) { return $this->enabled; }
};
$user = new class {
    public $name = 'info@welcomehostel.pt'; public function get_username() { return $this->name; }
};
$hooks = new class {
    public $throw = false; public $called = 0;
    public function exec_hook($name, $args) { $this->called++; if ($this->throw) { throw new RuntimeException('fixture'); } }
};
rcmail::$instance = (object) ['user' => $user, 'config' => $config, 'plugins' => $hooks];
$stub = new class {
    public $mime; public $id; public $calls = 0;
    public function send($mime, $id) { $this->calls++; $this->mime = $mime; $this->id = $id; return ['state' => 'accepted']; }
};
$plugin = new class extends welcome_smtp2go_api {
    public $stub; protected function transport() { return $this->stub; }
};
$plugin->stub = $stub; $plugin->init();
check(isset($plugin->hooks['message_before_send']), 'Missing production hook');
$mime = "From: info@welcomehostel.pt\r\nTo: first@example.com\r\nBcc: third@example.com\r\n\r\n<html>ação <a href=\"https://welcomehostel.pt/\">teste</a></html>";
$message = new class($headers, $mime) {
    private $h; private $m;
    public function __construct($h, $m) { $this->h = $h; $this->m = $m; }
    public function headers() { return $this->h; }
    public function getMessage() { return $this->m; }
};
$args = ['message' => $message, 'from' => 'info@welcomehostel.pt', 'mailto' => 'first@example.com', 'options' => []];
$result = $plugin->send($args);
check($result['abort'] && $result['result'] && $result['message'] === $message, 'Sent-folder object changed');
check($stub->mime === $mime && $stub->id === $headers['Message-ID'] && $hooks->called === 1, 'MIME or sent hook changed');
$hooks->throw = true; check($plugin->send($args)['result'], 'Post-acceptance hook caused resend risk');
$calls = $stub->calls;
$dsn = $args; $dsn['options']['dsn'] = true; check(!$plugin->send($dsn)['result'], 'Unsupported DSN accepted');
$config->enabled = false; $result = $plugin->send($args);
check($result['abort'] && !$result['result'] && $stub->calls === $calls, 'Disabled transport fell back');
$user->name = 'other@example.com'; $other = new welcome_smtp2go_api(); $other->init();
check(!$other->hooks, 'Other mailbox changed');
echo "Production envelope, MIME/Sent preservation, private duplicate guards and failures passed.\n";
