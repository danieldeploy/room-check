<?php
require __DIR__ . '/../deploy/roundcube-pilot/pilot_transport.php';
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function refuses(callable $fn) {
    try { $fn(); } catch (welcome_smtp2go_pilot_error $e) { return; }
    throw new RuntimeException('Expected controlled refusal');
}
$addresses = static function ($value) {
    return $value === '' ? [] : array_values(array_unique(array_map('strtolower', explode(',', $value))));
};
$recipients = ['gmail' => hash('sha256', 'first@example.com'), 'outlook' => hash('sha256', 'second@example.com')];
$headers = ['From' => 'info@welcomehostel.pt', 'To' => 'first@example.com',
    'Bcc' => 'second@example.com', 'Subject' => 'WELCOME-PILOT-20261004-01'];
$validate = static function ($h, $from = 'info@welcomehostel.pt', $to = 'first@example.com') use ($addresses, $recipients) {
    return welcome_smtp2go_pilot_transport::validate_envelope($h, $from, $to, $addresses, $recipients);
};
check($validate($headers) === '01', 'BCC slot mismatch');
$second = ['From' => 'info@welcomehostel.pt', 'To' => 'second@example.com',
    'Cc' => 'first@example.com', 'Subject' => 'WELCOME-PILOT-20261004-02'];
check($validate($second, 'info@welcomehostel.pt', 'second@example.com') === '02', 'CC slot mismatch');
foreach (['To' => 'other@example.com', 'Cc' => 'other@example.com', 'Bcc' => 'other@example.com',
          'From' => 'other@example.com', 'Resent-To' => 'other@example.com',
          'Subject' => 'WELCOME-PILOT-20261004-03'] as $field => $value) {
    $bad = $headers; $bad[$field] = $value;
    refuses(static function () use ($validate, $bad) { $validate($bad); });
}
refuses(static function () use ($validate, $headers) { $validate($headers, 'other@example.com'); });
refuses(static function () use ($validate, $headers) { $validate($headers, 'info@welcomehostel.pt', 'other@example.com'); });
welcome_smtp2go_pilot_transport::check_time(1791133200, 1791133199);
refuses(static function () { welcome_smtp2go_pilot_transport::check_time(1791133200, 1791133200); });
refuses(static function () { welcome_smtp2go_pilot_transport::check_time(1791139999, 1791133199); });
$success = json_encode(['data' => ['email_response' => ['succeeded' => 2, 'failed' => 0, 'email_id' => 'fixture-id']]]);
check(welcome_smtp2go_pilot_transport::classify(200, 0, $success)['state'] === 'accepted', 'API success rejected');
foreach ([[200, 28, $success], [500, 0, $success], [200, 0, '{}'], [200, 0, 'not json'],
          [200, 0, json_encode(['data' => ['succeeded' => 1, 'failed' => 1, 'email_id' => 'fixture-id']])]] as $args) {
    check(welcome_smtp2go_pilot_transport::classify(...$args)['state'] === 'review_required', 'Uncertain result accepted');
}
$dir = sys_get_temp_dir() . '/roundcube-pilot-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
$transport = new welcome_smtp2go_pilot_transport();
$calls = 0;
$request = static function () use (&$calls, $success) {
    $calls++;
    return welcome_smtp2go_pilot_transport::classify(200, 0, $success);
};
try {
    $transport->once($dir, '01', $request);
    refuses(static function () use ($transport, $dir, $request) { $transport->once($dir, '01', $request); });
    check($calls === 1, 'Global subject guard allowed repeat');
    check((fileperms($dir . '/pilot-20261004-01.json') & 0077) === 0, 'Journal exposed');
    $failure = static function () use (&$calls) {
        $calls++;
        return welcome_smtp2go_pilot_transport::classify(0, 28, 'private-response-must-not-be-logged');
    };
    refuses(static function () use ($transport, $dir, $failure) { $transport->once($dir, '02', $failure); });
    refuses(static function () use ($transport, $dir, $request) { $transport->once($dir, '02', $request); });
    check($calls === 2, 'Uncertain attempt was retried');
    check(strpos(file_get_contents($dir . '/pilot-20261004-02.json'), 'private-response') === false, 'Response leaked');
    refuses(static function () use ($transport, $dir, $request) { $transport->once($dir, '../key', $request); });
} finally {
    foreach (glob($dir . '/*') as $file) { unlink($file); }
    rmdir($dir);
}

class rcube_plugin {
    public $hooks = [];
    public function load_config() {}
    public function add_hook($name, $handler) { $this->hooks[$name] = $handler; }
    public function include_script($name) {}
}
class rcmail { public static $instance; public static function get_instance() { return self::$instance; } }
class rcube_mime { public static function decode_header($value, $charset) { return $value; } }
require __DIR__ . '/../deploy/roundcube-pilot/welcome_smtp2go_pilot.php';
$config = new class {
    public function get($key, $default = null) { return $key === 'welcome_smtp2go_pilot_enabled' ? true : $default; }
};
$user = new class { public function get_username() { return 'info@welcomehostel.pt'; } };
rcmail::$instance = (object) ['user' => $user, 'config' => $config];
$plugin = new welcome_smtp2go_pilot(); $plugin->init();
check(isset($plugin->hooks['message_before_send']), 'Missing stale-compose guard');
$ordinary = ['message' => new class { public function headers() { return ['Subject' => 'Ordinary mail']; } }];
check($plugin->send($ordinary) === $ordinary, 'Ordinary mail changed');
define('RCMAIL_VERSION', '1.6.19');
$expired = ['message' => new class { public function headers() { return ['Subject' => 'WELCOME-PILOT-20261004-01']; } }];
$result = $plugin->send($expired);
check($result['abort'] === true && $result['result'] === false, 'Expired pilot fell back to SMTP');
echo "Roundcube pilot envelope, expiry, API classification, duplicate guards and hook isolation passed.\n";
