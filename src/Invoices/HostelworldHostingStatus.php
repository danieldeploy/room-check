<?php
declare(strict_types=1);

final class HostelworldHostingStatus
{
    public static function read(InvoiceVault $vault, bool $manager, int $accountId): ?array
    {
        if (!$manager || $accountId !== 3) return null;
        try {
            $path = $vault->path('hostelworld-city-hosting-status.json');
            if (!is_file($path) || (fileperms($path) & 0077) !== 0 || filesize($path) > 1024) return null;
            $data = json_decode((string)file_get_contents($path), true, 4, JSON_THROW_ON_ERROR);
            if (!is_array($data) || ($data['version'] ?? null) !== 1 || ($data['account_id'] ?? null) !== 3
                || !is_int($data['at'] ?? null) || $data['at'] <= 0
                || !is_bool($data['tested_filter_identified'] ?? null)
                || !is_bool($data['delivery_uses_tested_domain'] ?? null)) return null;
            return array_intersect_key($data, array_flip(['version','account_id','at',
                'tested_filter_identified','delivery_uses_tested_domain']));
        } catch (Throwable) { return null; }
    }
}
