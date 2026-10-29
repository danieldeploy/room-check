<?php
declare(strict_types=1);
require_once __DIR__.'/InvoicePdfText.php';
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
        elseif ($format === 'pdf') $text = InvoicePdfText::read($path);
        return self::matches($text) ? ['validated', 'Active Lines'] : ['review', null];
    }
}
