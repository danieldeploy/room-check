<?php
declare(strict_types=1);
require_once __DIR__ . '/InvoiceDriveClient.php';
require_once __DIR__ . '/InvoiceCompany.php';

/** Caller holds room_check_invoices advisory lock, including manual retries. */
final class InvoiceDrive
{
    public const MAX_ATTEMPTS = 9; // Initial upload plus eight retries.
    public const INTERVAL = 10800;
    public function __construct(private readonly PDO $pdo, private readonly InvoiceVault $vault, private readonly object $client) {}
    public static function verify(array $meta, array $d, string $path): bool
    {
        return ($meta['id'] ?? '') === $d['drive_id'] && ($meta['trashed'] ?? true) === false
            && in_array($d['drive_parent'], $meta['parents'] ?? [], true)
            && (int) ($meta['size'] ?? -1) === (int) $d['size_bytes']
            && is_file($path) && hash_equals($d['sha256'], (string) hash_file('sha256', $path))
            && hash_equals((string) hash_file('md5', $path), (string) ($meta['md5Checksum'] ?? ''));
    }
    public function retry(int $id): void
    {
        $s = $this->pdo->prepare("UPDATE invoice_document_delivery SET drive_state = 'retry', attempts = 0,
            next_attempt_at = NULL, last_error = NULL WHERE document_id = ? AND drive_state IN ('failed','retry')");
        $s->execute([$id]);
        if ($s->rowCount() !== 1) throw new RuntimeException('invalid_request');
    }
    private function folder(string $parent, string $name): string
    {
        $key = hash('sha256', $parent . "\0" . $name);
        $s = $this->pdo->prepare('SELECT drive_id FROM invoice_drive_folders WHERE path_key = ?'); $s->execute([$key]);
        $id = $s->fetchColumn();
        if (!$id) {
            $id = $this->client->newId();
            $this->pdo->prepare('INSERT INTO invoice_drive_folders (path_key, drive_id, parent_id, name) VALUES (?, ?, ?, ?)')->execute([$key, $id, $parent, $name]);
        }
        $this->client->folder($id, $parent, $name); return $id;
    }
    public function run(?int $clock = null): void
    {
        $now = $clock ?? time(); $date = gmdate('Y-m-d H:i:s', $now);
        // Adopt interrupted uploads without consuming an additional retry early.
        $stale = $this->pdo->query("SELECT d.*, x.attempts, x.last_attempt_at FROM invoice_document_delivery x JOIN invoice_documents d ON d.id=x.document_id WHERE x.drive_state='uploading'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($stale as $d) $this->fail($d, 'drive_interrupted', strtotime($d['last_attempt_at'] . ' UTC') ?: $now);
        $this->pdo->exec('INSERT IGNORE INTO invoice_document_delivery (document_id) SELECT id FROM invoice_documents');
        $s = $this->pdo->prepare("SELECT d.*, x.*, a.portal, a.label AS account_label, p.label AS property_label
            FROM invoice_document_delivery x JOIN invoice_documents d ON d.id=x.document_id JOIN invoice_accounts a ON a.id=d.account_id
            LEFT JOIN invoice_account_properties p ON p.account_id=d.account_id AND p.property_id=d.property_id
            WHERE x.drive_state IN ('pending','retry') AND (x.next_attempt_at IS NULL OR x.next_attempt_at <= ?) ORDER BY d.id LIMIT 20");
        $s->execute([$date]);
        $connected = false;
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $d['attempts'] = (int) $d['attempts'] + 1;
            $this->pdo->prepare("UPDATE invoice_document_delivery SET drive_state='uploading', attempts=?, last_attempt_at=? WHERE document_id=?")
                ->execute([$d['attempts'], $date, $d['id']]);
            try {
                $path = $this->vault->path($d['sha256'] . '.' . $d['format']);
                if (!is_file($path) || !hash_equals($d['sha256'], (string) hash_file('sha256', $path))) throw new RuntimeException('drive_local_missing');
                [$company, $name] = InvoiceCompany::inspect($path, $d['format']);
                $this->pdo->prepare('UPDATE invoice_document_delivery SET company_state=?, company_name=? WHERE document_id=?')->execute([$company, $name, $d['id']]);
                if (!$connected) { $this->client->connect(); $connected = true; }
                $settings = $this->pdo->query('SELECT * FROM invoice_drive_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
                $parent = (string) ($settings['folder_id'] ?? '');
                InvoiceDriveClient::assertId($parent);
                $root = $this->client->metadata($parent);
                if (!$root || ($root['trashed'] ?? true) || ($root['mimeType'] ?? '') !== 'application/vnd.google-apps.folder'
                    || !in_array('daniel.ciorcas@welcomehostel.pt', array_column($root['owners'] ?? [], 'emailAddress'), true)) throw new RuntimeException('drive_account_mismatch');
                if (!$d['drive_parent']) {
                    foreach ([$d['portal'], $d['account_label'] . ' [' . $d['account_id'] . ']',
                        ($d['property_label'] ?? $d['property_id']) . ' [' . $d['property_id'] . ']', substr($d['period'],0,4), substr($d['period'],5,2)] as $folder) $parent = $this->folder($parent, $folder);
                    $d['drive_parent'] = $parent;
                    $this->pdo->prepare('UPDATE invoice_document_delivery SET drive_parent=? WHERE document_id=?')->execute([$parent, $d['id']]);
                }
                if (!$d['drive_id']) {
                    $d['drive_id'] = $this->client->newId();
                    $this->pdo->prepare('UPDATE invoice_document_delivery SET drive_id=? WHERE document_id=?')->execute([$d['drive_id'], $d['id']]);
                }
                $filename = preg_replace('/[^\pL\pN._-]+/u', '-', $d['invoice_number']) . '.' . $d['format'];
                $meta = $this->client->upload($d['drive_id'], $d['drive_parent'], $filename, $path, $d['format']);
                if (!self::verify($meta, $d, $path)) throw new RuntimeException('drive_verify');
                $this->pdo->prepare("UPDATE invoice_document_delivery SET drive_state='verified', verified_at=?, next_attempt_at=NULL, last_error=NULL WHERE document_id=?")
                    ->execute([$date, $d['id']]);
                $this->pdo->prepare("UPDATE invoice_drive_settings SET state='ready', checked_at=? WHERE id=1")->execute([$date]);
            } catch (Throwable $e) {
                $allowed = ['drive_not_configured','drive_auth','drive_account_mismatch','drive_invalid_id','drive_not_found','drive_permission',
                    'drive_quota','drive_network','drive_upload','drive_verify','drive_transport','drive_local_missing','drive_conflict'];
                $this->fail($d, in_array($e->getMessage(), $allowed, true) ? $e->getMessage() : 'drive_upload', $now);
            }
        }
        $this->cleanup($date);
    }
    private function fail(array $d, string $code, int $now): void
    {
        $exhausted = (int) $d['attempts'] >= self::MAX_ATTEMPTS;
        $this->pdo->prepare('UPDATE invoice_document_delivery SET drive_state=?, next_attempt_at=?, last_error=? WHERE document_id=?')
            ->execute([$exhausted ? 'failed' : 'retry', $exhausted ? null : gmdate('Y-m-d H:i:s', $now + self::INTERVAL), $code, $d['id']]);
        if ($exhausted) $this->pdo->prepare('INSERT IGNORE INTO invoice_drive_alerts (account_id, period, error_code, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$d['account_id'], $d['period'], $code, gmdate('Y-m-d H:i:s', $now)]);
    }
    private function cleanup(string $now): void
    {
        $rows = $this->pdo->query("SELECT d.* FROM invoice_documents d JOIN invoice_document_delivery x ON x.document_id=d.id
            WHERE x.drive_state='verified' AND x.local_deleted_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $d) {
            // Content-addressed files may belong to multiple accounts/documents. Preserve until ALL uploads verify.
            $s = $this->pdo->prepare("SELECT COUNT(*) FROM invoice_documents d LEFT JOIN invoice_document_delivery x ON x.document_id=d.id
                WHERE d.sha256=? AND d.format=? AND (x.drive_state IS NULL OR x.drive_state<>'verified')");
            $s->execute([$d['sha256'], $d['format']]); if ((int) $s->fetchColumn() !== 0) continue;
            $path = $this->vault->path($d['sha256'] . '.' . $d['format']);
            if (is_file($path) && !unlink($path)) continue;
            $this->pdo->prepare('UPDATE invoice_document_delivery SET local_deleted_at=? WHERE document_id=?')->execute([$now, $d['id']]);
        }
    }
}
