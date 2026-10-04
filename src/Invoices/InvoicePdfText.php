<?php
declare(strict_types=1);

final class InvoicePdfText
{
    public static function read(string $file, bool $useSystem=true): string
    {
        if (!is_file($file) || filesize($file)>20*1024*1024) return '';
        $text=$useSystem ? self::systemText($file) : '';
        if ($text!=='') return $text;
        // Keep a bounded PHP fallback for shared hosting without pdftotext.
        // Large, encrypted and unusually long files require review.
        if (filesize($file)>2*1024*1024 || !function_exists('mb_convert_encoding')
            || !function_exists('gzuncompress') || !function_exists('iconv')) return '';
        require_once __DIR__.'/../ThirdParty/PdfParser/autoload.php';
        try {
            $config=new \Smalot\PdfParser\Config();
            $config->setRetainImageContent(false);
            $config->setDecodeMemoryLimit(8*1024*1024);
            $pdf=(new \Smalot\PdfParser\Parser([],$config))->parseFile($file);
            if (count($pdf->getPages())>10) return '';
            $text=$pdf->getText();
            return strlen($text)<=5*1024*1024 ? $text : '';
        } catch (Throwable) { return ''; }
    }

    private static function systemText(string $file): string
    {
        if (!function_exists('proc_open')) return '';
        $process=@proc_open(['pdftotext','-layout','-enc','UTF-8',$file,'-'],
            [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','a']],$pipes);
        if (!is_resource($process)) return '';
        stream_set_blocking($pipes[1],false); $text=''; $deadline=microtime(true)+10; $status=null;
        do {
            $text.=stream_get_contents($pipes[1]); $status=proc_get_status($process);
            if (!$status['running']) break;
            usleep(10000);
        } while (microtime(true)<$deadline && strlen($text)<5*1024*1024);
        if ($status['running']) { proc_terminate($process,9); $text=''; }
        else $text.=stream_get_contents($pipes[1]);
        fclose($pipes[1]); proc_close($process);
        return !$status['running'] && $status['exitcode']===0 && strlen($text)<5*1024*1024 ? $text : '';
    }
}
