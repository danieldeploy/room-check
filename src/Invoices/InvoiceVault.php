<?php
declare(strict_types=1);

/** AES-256-GCM envelopes. The key and all documents live outside the web root. */
final class InvoiceVault
{
    public readonly string $root;

    public function __construct(string $root)
    {
        if ($root === '' || $root[0] !== '/' || str_contains($root, "\0")
            || preg_match('~/(?:public_html|\.\.?)(?:/|$)~', $root)) {
            throw new RuntimeException('private_storage_unavailable');
        }
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved) || is_link($root)
            || preg_match('~/public_html(?:/|$)~', $resolved)) {
            throw new RuntimeException('private_storage_unavailable');
        }
        $web = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($web && ($resolved === $web || str_starts_with($resolved, $web . '/'))) {
            throw new RuntimeException('private_storage_unavailable');
        }
        if ((fileperms($resolved) & 0077) !== 0) {
            throw new RuntimeException('private_storage_permissions');
        }
        $this->root = $resolved;
    }

    public function path(string $name): string
    {
        if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]*\z/', $name)) {
            throw new RuntimeException('invalid_private_name');
        }
        $path = $this->root . '/' . $name;
        if (is_link($path)) {
            throw new RuntimeException('invalid_private_name');
        }
        return $path;
    }

    private function key(): string
    {
        $path = $this->path('master.key');
        if (!is_file($path) || (fileperms($path) & 0077) !== 0) {
            throw new RuntimeException('vault_key_unavailable');
        }
        $key = file_get_contents($path);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('vault_key_unavailable');
        }
        return $key;
    }

    public function has(string $name): bool
    {
        return is_file($this->path($name));
    }

    public function save(string $name, array $value): void
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(json_encode($value, JSON_THROW_ON_ERROR), 'aes-256-gcm',
            $this->key(), OPENSSL_RAW_DATA, $iv, $tag, $name, 16);
        if ($cipher === false) {
            throw new RuntimeException('vault_write_failed');
        }
        $payload = json_encode(['v' => 1, 'iv' => base64_encode($iv), 'tag' => base64_encode($tag),
            'data' => base64_encode($cipher)], JSON_THROW_ON_ERROR);
        self::atomicWrite($this->path($name), $payload);
    }

    public function read(string $name): array
    {
        $path = $this->path($name);
        if (!is_file($path) || (fileperms($path) & 0077) !== 0) {
            throw new RuntimeException('vault_read_failed');
        }
        $value = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $iv = base64_decode((string) ($value['iv'] ?? ''), true);
        $tag = base64_decode((string) ($value['tag'] ?? ''), true);
        $data = base64_decode((string) ($value['data'] ?? ''), true);
        if (($value['v'] ?? null) !== 1 || $iv === false || strlen($iv) !== 12
            || $tag === false || strlen($tag) !== 16 || $data === false) {
            throw new RuntimeException('vault_read_failed');
        }
        $plain = openssl_decrypt($data, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag, $name);
        if ($plain === false) {
            throw new RuntimeException('vault_read_failed');
        }
        $result = json_decode($plain, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($result)) {
            throw new RuntimeException('vault_read_failed');
        }
        return $result;
    }

    public static function atomicWrite(string $path, string $data): void
    {
        $temporary = tempnam(dirname($path), '.invoice-');
        if ($temporary === false) {
            throw new RuntimeException('private_write_failed');
        }
        try {
            if (!chmod($temporary, 0600) || file_put_contents($temporary, $data, LOCK_EX) !== strlen($data)
                || !rename($temporary, $path)) {
                throw new RuntimeException('private_write_failed');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
