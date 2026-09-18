<?php
declare(strict_types=1);

final class My2NRedactor
{
    public static function sanitize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $clean = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                continue;
            }
            $clean[$key] = self::sanitize($item);
        }

        return $clean;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $key) ?? $key);
        foreach (['sippassword', 'password', 'sessiontoken', 'accesstoken', 'authorization', 'secret'] as $blocked) {
            if (str_contains($normalized, $blocked)) {
                return true;
            }
        }
        return false;
    }
}
