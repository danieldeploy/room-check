#!/usr/local/bin/php -q
<?php
declare(strict_types=1);
// Exim invokes this private executable via STDIN. Never output mail, errors or secrets.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(0); umask(0077);
// One replaceable private status file. Never include paths, input or exception text.
$facts = [];
$diagnostic = static function (string $stage, string $code) use (&$facts): void {
    $temporary = false;
    try {
        $directory = dirname(__DIR__);
        clearstatcache();
        if (is_link($directory) || !is_dir($directory)
            || (fileperms($directory) & 0077) !== 0) return;
        $target = $directory.'/toconline-pipe-diagnostic.json';
        if (is_link($target) || (file_exists($target) && !is_file($target))) return;
        $home = getenv('HOME');
        $payload = json_encode([
            'schema' => 2, 'at' => gmdate('c'), 'stage' => $stage, 'code' => $code,
            'php' => PHP_VERSION, 'sapi' => PHP_SAPI,
            'home_present' => is_string($home) && $home !== '',
            'home_matches_account' => is_string($home)
                && rtrim($home, DIRECTORY_SEPARATOR) === dirname(__DIR__, 2),
            'mbstring' => extension_loaded('mbstring'),
            'mb_convert_encoding' => function_exists('mb_convert_encoding'),
            'mb_strtolower' => function_exists('mb_strtolower'),
            'openssl_encrypt' => function_exists('openssl_encrypt'),
            'facts' => $facts,
        ], JSON_THROW_ON_ERROR);
        $temporary = @tempnam($directory, '.toc-diagnostic-');
        if ($temporary === false) return;
        // tempnam may fall back to a different directory; never write status there.
        if (realpath(dirname($temporary)) !== realpath($directory)) return;
        if (@chmod($temporary, 0600)
            && @file_put_contents($temporary, $payload, LOCK_EX) === strlen($payload)) {
            @rename($temporary, $target);
        }
    } catch (Throwable) {
        // Diagnostics must not change delivery or emit any output.
    } finally {
        if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
    }
};
$stage = 'bootstrap';
$diagnostic($stage, 'started');
try {
    $appRoot=dirname(__DIR__,2).'/public_html/check';
    require_once $appRoot.'/src/Invoices/InvoiceTocPipe.php';
    $stage='configuration';
    $config=require $appRoot.'/config.php';
    $privateDir=(string)($config['invoices']['private_dir']??'');
    $facts['private_dir_is_default']=$privateDir===dirname(__DIR__).'/invoices';
    $facts['private_dir_exists']=is_dir($privateDir);
    $stage='input';
    $raw=stream_get_contents(STDIN,InvoiceTocPipe::MAX_BYTES+1);
    if (!is_string($raw)) { $diagnostic($stage, 'stdin_unavailable'); exit(75); }
    $stage='vault';
    $pipe=new InvoiceTocPipe(new InvoiceVault($privateDir));
    $stage='queue';
    $queued=$pipe->enqueue($raw);
    $diagnostic('complete', $queued ? 'queued' : 'ignored');
    exit(0);
} catch (Throwable $failure) {
    $known = ['private_storage_unavailable', 'private_storage_permissions',
        'invalid_private_name', 'vault_key_unavailable', 'vault_write_failed',
        'private_write_failed', 'toc_pipe_invalid', 'toc_pipe_queue'];
    $code = in_array($failure->getMessage(), $known, true)
        ? $failure->getMessage() : 'runtime_failure';
    $diagnostic($stage, $code);
    // EX_TEMPFAIL asks the mail transport to retry; the mailbox copy must be retained by its filter.
    exit(75);
}
