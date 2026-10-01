<?php
declare(strict_types=1);

final class InvoiceDriveLayout
{
    public const MONTHS = [1=>'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
        'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    public static function folders(string $period): array
    {
        if (!preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $period)) throw new RuntimeException('invalid_request');
        $year = substr($period, 0, 4);
        $month = self::MONTHS[(int) substr($period, 5, 2)];
        return [[$year], [$month.'_'.$year, $month.' '.$year], ['online']];
    }

    public static function filename(array $document, bool $suffix = false): string
    {
        self::folders($document['period']);
        $label = strtolower((string) ($document['property_label'] ?? $document['account_label'] ?? $document['property_id']));
        $property = str_contains($label, 'city center') ? 'city_center'
            : (str_contains($label, 'welcome') ? 'welcome' : self::slug($label));
        $portal = $document['portal'] === 'booking.com' ? 'booking' : self::slug($document['portal']);
        $month = self::slug(self::MONTHS[(int) substr($document['period'], 5, 2)]);
        $extra = $suffix ? '_'.substr(self::slug($document['invoice_number']), 0, 70).'_'.$document['id'] : '';
        return $portal.'_'.$property.'_'.$month.$extra.'.'.$document['format'];
    }

    private static function slug(string $text): string
    {
        $text = strtr(strtolower($text), ['ç'=>'c', 'á'=>'a', 'ã'=>'a', 'à'=>'a', 'é'=>'e', 'ê'=>'e', 'ó'=>'o', 'õ'=>'o', 'í'=>'i', 'ú'=>'u']);
        return trim(preg_replace('/[^a-z0-9]+/', '_', $text) ?? '', '_') ?: 'documento';
    }
}
