<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/BookingLoginStatus.php';

$waiting = BookingLoginStatus::waiting();
if ($waiting['phase'] !== 'unknown' || $waiting['title'] !== 'booking_login_no_diagnostic') {
    throw new RuntimeException('A missing diagnostic must be shown as waiting for a login test');
}

$cases = [
    [['authenticated_session'=>true,'login_attempted'=>true,'sms_prompted'=>false,'captcha_status'=>'not_needed'], 'authenticated'],
    [['authenticated_session'=>false,'sms_prompted'=>true,'sms_submitted'=>false], 'sms_waiting'],
    [['authenticated_session'=>false,'sms_prompted'=>true,'sms_submitted'=>true], 'sms_submitted'],
    [['authenticated_session'=>false,'sms_prompted'=>true,'failure_code'=>'auth_timeout'], 'sms_timeout'],
    [['authenticated_session'=>false,'failure_code'=>'human_verification'], 'captcha'],
    [['authenticated_session'=>false,'failure_code'=>'auth_invalid'], 'credentials_rejected'],
    [['authenticated_session'=>false,'failure_stage'=>'password'], 'credentials'],
    [[], 'unknown'],
];
foreach ($cases as [$input, $phase]) {
    $actual = BookingLoginStatus::summarize($input);
    if ($actual['phase'] !== $phase) {
        throw new RuntimeException('Expected '.$phase.', got '.$actual['phase']);
    }
}
$session = BookingLoginStatus::summarize(['authenticated_session'=>true,'login_attempted'=>false,'sms_prompted'=>false]);
if ($session['phase'] !== 'authenticated' || $session['detail'] !== 'booking_login_authenticated_session') {
    throw new RuntimeException('An existing authenticated session must not be presented as pending 2FA');
}
echo "Booking login status checks passed.\n";
