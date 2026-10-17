<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
ini_set('display_errors','0'); ini_set('log_errors','0');
require_once __DIR__.'/../src/Invoices/CloudbedsBridge.php';
try {
    $raw=CloudbedsMailMessage::wire(stream_get_contents(STDIN,4194305));
    $auth=CloudbedsMailAuthentication::verify($raw);
    $code=$auth['code']??'unverified';
    if (!in_array($code,['verified','unsigned','unsupported','unaligned','expired','unsigned_fields','dns_unavailable','body_mismatch','signature_mismatch','unverified'],true)) $code='unverified';
    echo json_encode(['auth'=>$code,'welcome_recipient'=>($auth['recipient']??null)===CloudbedsBridge::RECIPIENTS['welcome'],
        'city_recipient'=>($auth['recipient']??null)===CloudbedsBridge::RECIPIENTS['city'],
        'pdf_count'=>count(CloudbedsMailMessage::attachments($raw))],JSON_THROW_ON_ERROR);
} catch (Throwable) { echo '{"auth":"parse_failed"}'; }
