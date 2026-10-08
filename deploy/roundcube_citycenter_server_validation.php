<?php
// One server-side send to the City Center mailbox; no guests or private contacts.
if (PHP_SAPI !== 'cli') { exit; }
$private = '/home/city/roundcube-smtp2go-citycenter-private';
if (realpath($private) !== $private || is_link($private) || (fileperms($private) & 0077)) { exit(2); }
$mask = umask(0077);
$marker = fopen($private . '/server-validation-20261008.json', 'x');
umask($mask);
if (!$marker) { exit; }
$report = ['state' => 'started', 'email_sent' => false];
fwrite($marker, json_encode($report) . "\n"); fflush($marker);
try {
    if (!function_exists('curl_init')) { throw new RuntimeException('curl_missing'); }
    require '/home/city/public_html/roundcube/plugins/citycenter_smtp2go_api/api_transport.php';
    $id = '<citycenter-server-validation-20261008-01@citycenterhostel.pt>';
    $headers = ['From' => 'info@citycenterhostel.pt', 'To' => 'info@citycenterhostel.pt',
        'Subject' => 'Arrival Details - City Center SMTP2GO validation', 'Message-ID' => $id];
    citycenter_smtp2go_api_transport::validate_envelope($headers, 'info@citycenterhostel.pt', 'info@citycenterhostel.pt',
        static function ($value) { return $value === '' ? [] : explode(',', strtolower($value)); });
    $mime = "From: City Center Guest House <info@citycenterhostel.pt>\r\n"
        . "To: info@citycenterhostel.pt\r\n"
        . "Subject: Arrival Details - City Center SMTP2GO validation\r\n"
        . "Message-ID: " . $id . "\r\nDate: " . gmdate(DATE_RFC2822) . "\r\n"
        . "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
        . "<html><body><p>City Center Guest House</p><p>Arrival Details</p>"
        . "<p>This message checks the new SMTP2GO email configuration for City Center. No guest action is required.</p>"
        . "<p>Our reception is open from 08:00 to 15:00. For later arrivals, we provide self-check-in instructions.</p>"
        . "<p>City Center Guest House<br>Rua Augusta 188, Lisbon</p></body></html>";
    $result = (new citycenter_smtp2go_api_transport())->send($mime, $id);
    $report = ['state' => 'complete', 'ok' => true, 'server_send_accepted' => $result['state'] === 'accepted',
        'email_sent' => true, 'curl_available' => true, 'credential_published' => false];
} catch (Throwable $error) {
    $report = ['state' => 'complete', 'ok' => false, 'email_sent' => false,
        'delivery_state' => 'requires_review', 'credential_published' => false];
}
fwrite($marker, json_encode($report) . "\n"); fflush($marker); fclose($marker);
