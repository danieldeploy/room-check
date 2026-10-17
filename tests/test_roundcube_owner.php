<?php
require $argv[1] . '/pilot_transport.php';
function check($ok) { if (!$ok) { throw new RuntimeException('Owner pilot assertion failed'); } }
function refuses(callable $fn) {
    try { $fn(); } catch (welcome_smtp2go_pilot_error $e) { return; }
    throw new RuntimeException('Expected controlled refusal');
}
$addresses = static function ($v) { return $v === '' ? [] : explode(',', strtolower($v)); };
$recipients = ['gmail'=>hash('sha256','a@example.com'), 'outlook'=>hash('sha256','b@example.com'),
    'hotmail'=>hash('sha256','c@example.com')];
$headers = ['From'=>'info@welcomehostel.pt', 'To'=>'b@example.com', 'Cc'=>'a@example.com,c@example.com',
    'Subject'=>'WELCOME-PILOT-20261004-02'];
$validate = static function ($h) use ($addresses, $recipients) {
    return welcome_smtp2go_pilot_transport::validate_envelope($h, 'info@welcomehostel.pt', 'b@example.com', $addresses, $recipients);
};
check($validate($headers) === '02');
foreach (['To'=>'old@example.com','Cc'=>'a@example.com','Bcc'=>'c@example.com',
    'Subject'=>'WELCOME-PILOT-20261004-01','Resent-To'=>'d@example.com'] as $name=>$value) {
    $bad = $headers; $bad[$name] = $value;
    refuses(static function () use ($validate,$bad) { $validate($bad); });
}
require $argv[1] . '/config.inc.php';
check($config['welcome_smtp2go_pilot_expires'] === welcome_smtp2go_pilot_transport::DEADLINE);
welcome_smtp2go_pilot_transport::check_time(1791140400,1791140399);
refuses(static function () { welcome_smtp2go_pilot_transport::check_time(1791140400,1791140400); });
refuses(static function () { welcome_smtp2go_pilot_transport::check_time(1791133200,1791133199); });
$dir = sys_get_temp_dir().'/owner-pilot-'.bin2hex(random_bytes(8)); mkdir($dir,0700);
$t = new welcome_smtp2go_pilot_transport(); $calls=0;
$request = static function () use (&$calls) { $calls++; return ['state'=>'accepted','email_id'=>'fixture']; };
try {
    $t->once($dir,'02',$request);
    refuses(static function () use ($t,$dir,$request) { $t->once($dir,'02',$request); });
    check($calls===1);
} finally { unlink($dir.'/pilot-20261004-02.json'); rmdir($dir); }
echo "Owner Microsoft pilot envelope, expiry and duplicate guard passed.\n";
