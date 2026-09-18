<?php
declare(strict_types=1);
final class InvoiceCompany
{
    public static function matches(string $text): bool
    {
        return preg_match('/active\s+lines/iu', $text) === 1;
    }
    public static function inspect(string $path, string $format): array
    {
        $text = '';
        if ($format === 'csv') $text = (string) file_get_contents($path);
        elseif ($format === 'pdf' && function_exists('proc_open')) {
            // No shell. Extract document text, never use binary PDF bytes as evidence.
            $process = @proc_open(['pdftotext', '-enc', 'UTF-8', $path, '-'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $pipes);
            if (is_resource($process)) {
                stream_set_blocking($pipes[1], false);
                $deadline = microtime(true) + 10;
                do {
                    $text .= stream_get_contents($pipes[1]);
                    $running = proc_get_status($process)['running'];
                    if (!$running) break;
                    usleep(10000);
                } while (microtime(true) < $deadline && strlen($text) < 5 * 1024 * 1024);
                if ($running) { proc_terminate($process, 9); $text = ''; }
                else $text .= stream_get_contents($pipes[1]);
                fclose($pipes[1]); proc_close($process);
            }
        }
        return self::matches($text) ? ['validated', 'Active Lines'] : ['review', null];
    }
}
