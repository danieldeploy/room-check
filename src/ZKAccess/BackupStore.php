<?php
declare(strict_types=1);

require_once __DIR__ . '/../Invoices/InvoiceVault.php';

/**
 * Receives compressed MySQL dumps from the paired Windows agent.
 * Each chunk is authenticated and encrypted before being written to private disk.
 * No database records, PINs, passwords or plaintext dumps are exposed over HTTP.
 */
final class ZKAccessBackupStore
{
    public const MAX_BYTES = 536870912; // 512 MiB compressed, fail closed
    public const CHUNK_BYTES = 131072;
    public const STATE_FILE = 'zkaccess-backups.enc';
    public const SETTINGS_FILE = 'zkaccess-backup-settings.enc';

    public function __construct(private readonly InvoiceVault $vault) {}

    public static function validateTime(string $time): string
    {
        if (!preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D', $time)) {
            throw new InvalidArgumentException('invalid_backup_time');
        }
        return $time;
    }

    public function settings(): array
    {
        $stored = $this->vault->has(self::SETTINGS_FILE) ? $this->vault->read(self::SETTINGS_FILE) : [];
        return ['enabled' => ($stored['enabled'] ?? true) === true,
            'time' => self::validateTime((string)($stored['time'] ?? '04:00')),
            'timezone' => 'Europe/Lisbon', 'retention_days' => 30];
    }

    public function saveSettings(bool $enabled, string $time): void
    {
        $this->vault->save(self::SETTINGS_FILE,
            ['enabled' => $enabled, 'time' => self::validateTime($time)]);
    }

    public function entries(): array
    {
        $data = $this->vault->has(self::STATE_FILE) ? $this->vault->read(self::STATE_FILE) : [];
        return is_array($data['entries'] ?? null) ? $data['entries'] : [];
    }

    public function saveEntries(array $entries): void
    {
        $this->vault->save(self::STATE_FILE, ['entries' => $entries]);
    }

    public function status(): array
    {
        $list = [];
        foreach ($this->entries() as $entry) {
            $list[] = ['id' => $entry['id'], 'created_at' => $entry['created_at'],
                'status' => $entry['status'], 'size' => $entry['size'],
                'received' => $entry['received'], 'drive_id' => $entry['drive_id'] ?? null,
                'uploaded_at' => $entry['uploaded_at'] ?? null];
        }
        usort($list, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
        return array_slice($list, 0, 40);
    }

    public function begin(array $request): array
    {
        $id = (string)($request['id'] ?? '');
        $sha = (string)($request['sha256'] ?? '');
        $size = (int)($request['size'] ?? 0);
        self::assertId($id);
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $sha) || $size < 100 || $size > self::MAX_BYTES) {
            throw new RuntimeException('invalid_backup');
        }
        $entries = $this->entries();
        if (isset($entries[$id])) {
            $e = $entries[$id];
            if ($e['sha256'] !== $sha || (int)$e['size'] !== $size) throw new RuntimeException('backup_conflict');
            return ['id' => $id, 'received' => $e['received'], 'status' => $e['status']];
        }
        foreach ($entries as $e) {
            if ($e['status'] === 'receiving' && time() - strtotime($e['created_at'] . ' UTC') < 86400) {
                throw new RuntimeException('backup_busy');
            }
        }
        if (count($entries) >= 120) throw new RuntimeException('backup_storage_limit');
        if (disk_free_space($this->vault->root) < $size + 64 * 1024 * 1024) {
            throw new RuntimeException('backup_storage_limit');
        }
        $key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $prefix = random_bytes(16);
        $this->vault->save(self::keyName($id), ['key' => base64_encode($key)]);
        $path = $this->vault->path(self::dataName($id));
        InvoiceVault::atomicWrite($path, "ZKBAK1\n");
        $entries[$id] = [
            'id' => $id, 'created_at' => gmdate('Y-m-d H:i:s'), 'status' => 'receiving',
            'sha256' => $sha, 'size' => $size, 'received' => 0, 'parts' => 0,
            'cipher_bytes' => 7, 'prefix' => base64_encode($prefix), 'lengths' => [],
            'last_sha256' => null, 'last_length' => 0, 'drive_id' => null
        ];
        $this->saveEntries($entries);
        return ['id' => $id, 'received' => 0, 'status' => 'receiving'];
    }

    public function chunk(array $request): array
    {
        $id = (string)($request['id'] ?? '');
        self::assertId($id);
        $entries = $this->entries();
        $e = $entries[$id] ?? null;
        if (!$e || $e['status'] !== 'receiving') throw new RuntimeException('backup_not_receiving');
        $offset = $request['offset'] ?? null;
        $bytes = base64_decode((string)($request['data'] ?? ''), true);
        if (!is_int($offset) || $bytes === false || strlen($bytes) < 1 || strlen($bytes) > self::CHUNK_BYTES) {
            throw new RuntimeException('invalid_backup_chunk');
        }
        $length = strlen($bytes);
        $digest = hash('sha256', $bytes);
        if ($offset < $e['received'] && $offset + $length === $e['received']
            && $length === $e['last_length'] && hash_equals((string)$e['last_sha256'], $digest)) {
            return ['received' => $e['received'], 'duplicate' => true];
        }
        if ($offset !== $e['received'] || $offset + $length > $e['size']) {
            throw new RuntimeException('backup_conflict');
        }
        $key = base64_decode((string)$this->vault->read(self::keyName($id))['key'], true);
        $prefix = base64_decode((string)$e['prefix'], true);
        if (!$key || strlen($key) !== 32 || !$prefix || strlen($prefix) !== 16) {
            throw new RuntimeException('backup_unavailable');
        }
        $nonce = $prefix . pack('J', $e['parts']);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $bytes, hex2bin($id) . pack('J', $e['parts']), $nonce, $key);
        $record = pack('N', strlen($cipher)) . $cipher;
        $path = $this->vault->path(self::dataName($id));
        $file = fopen($path, 'c+b');
        if (!$file) throw new RuntimeException('backup_storage_limit');
        try {
            if (fseek($file, 0, SEEK_END) !== 0) throw new RuntimeException('backup_storage_limit');
            if (ftell($file) < $e['cipher_bytes']) throw new RuntimeException('backup_storage_limit');
            if (ftruncate($file, $e['cipher_bytes']) === false
                || fseek($file, $e['cipher_bytes']) !== 0
                || fwrite($file, $record) !== strlen($record) || !fflush($file)) {
                throw new RuntimeException('backup_storage_limit');
            }
        } finally { fclose($file); }
        $e['cipher_bytes'] += strlen($record);
        $e['received'] += $length;
        $e['parts']++;
        $e['lengths'][] = $length;
        $e['last_sha256'] = $digest;
        $e['last_length'] = $length;
        $entries[$id] = $e;
        $this->saveEntries($entries);
        return ['received' => $e['received'], 'duplicate' => false];
    }

    public function finish(array $request): array
    {
        $id = (string)($request['id'] ?? '');
        self::assertId($id);
        $entries = $this->entries();
        $e = $entries[$id] ?? null;
        if (!$e) throw new RuntimeException('backup_unknown');
        if (in_array($e['status'], ['ready','uploaded'], true)) return ['verified' => true, 'id' => $id];
        if ($e['status'] !== 'receiving' || $e['received'] !== $e['size']) {
            throw new RuntimeException('backup_incomplete');
        }
        $this->verify($e);
        $e['status'] = 'ready';
        $e['verified_at'] = gmdate('Y-m-d H:i:s');
        $entries[$id] = $e;
        $this->saveEntries($entries);
        return ['verified' => true, 'id' => $id];
    }

    /** Verify encryption integrity and plaintext gzip SHA256 without saving plaintext. */
    public function verify(array $entry, ?string $alternateArchive = null): void
    {
        $key = base64_decode((string)$this->vault->read(self::keyName($entry['id']))['key'], true);
        $prefix = base64_decode((string)$entry['prefix'], true);
        if (!$key || strlen($key) !== 32 || !$prefix || strlen($prefix) !== 16) throw new RuntimeException('backup_unavailable');
        $handle = fopen($alternateArchive ?? $this->vault->path(self::dataName($entry['id'])), 'rb');
        if (!$handle) throw new RuntimeException('backup_missing');
        $hash = hash_init('sha256');
        $readBytes = 0;
        try {
            if (fread($handle, 7) !== "ZKBAK1\n") throw new RuntimeException('backup_corrupt');
            foreach ($entry['lengths'] as $index => $length) {
                $len = fread($handle, 4);
                if (strlen($len) !== 4) throw new RuntimeException('backup_corrupt');
                $cipherLen = unpack('N', $len)[1];
                if ($cipherLen !== $length + 16) throw new RuntimeException('backup_corrupt');
                $cipher = self::readExactly($handle, $cipherLen);
                $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                    $cipher, hex2bin($entry['id']) . pack('J', $index),
                    $prefix . pack('J', $index), $key);
                if ($plain === false || strlen($plain) !== $length) throw new RuntimeException('backup_corrupt');
                if ($index === 0 && !str_starts_with($plain, "\x1f\x8b")) throw new RuntimeException('backup_not_gzip');
                hash_update($hash, $plain);
                $readBytes += strlen($plain);
            }
            if (fread($handle, 1) !== '' || $readBytes !== $entry['size']
                || !hash_equals($entry['sha256'], hash_final($hash))) throw new RuntimeException('backup_corrupt');
        } finally { fclose($handle); }
    }

    private static function readExactly($handle, int $size): string
    {
        $result = '';
        while (strlen($result) < $size) {
            $part = fread($handle, $size - strlen($result));
            if ($part === false || $part === '') throw new RuntimeException('backup_corrupt');
            $result .= $part;
        }
        return $result;
    }

    public static function assertId(string $id): void
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) throw new RuntimeException('invalid_backup_id');
    }

    public static function dataName(string $id): string { self::assertId($id); return 'zkbackup-' . $id . '.encrypted'; }
    public static function keyName(string $id): string { self::assertId($id); return 'zkbackup-' . $id . '.key.enc'; }
}
