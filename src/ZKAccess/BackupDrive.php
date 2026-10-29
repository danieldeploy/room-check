<?php
declare(strict_types=1);

require_once __DIR__ . '/BackupStore.php';
require_once __DIR__ . '/BackupDestination.php';
require_once __DIR__ . '/../Invoices/InvoiceDriveClient.php';

/** Called by the existing private invoice cron after its original work. */
final class ZKAccessBackupDrive
{
    public function __construct(
        private readonly ZKAccessBackupStore $store,
        private readonly InvoiceVault $vault,
        private readonly InvoiceDriveClient $drive
    ) {}

    public function run(int $now = 0): array
    {
        $now = $now ?: time();
        $entries = $this->store->entries();
        $pending = array_filter($entries, static fn(array $e): bool => $e['status'] === 'ready');
        $expired = array_filter($entries, static function (array $e) use ($now): bool {
            return $e['status'] === 'uploaded'
                && strtotime($e['created_at'] . ' UTC') < $now - (30 * 86400);
        });
        $result = ['uploaded' => 0, 'expired' => 0];
        if (!$pending && !$expired) return $result;

        // Persist the attempt *before* contacting OAuth / Drive. A failed
        // connection is otherwise invisible in the backup history.
        $attemptId = array_key_first($pending);
        if ($attemptId !== null) {
            $this->store->recordUploadAttempt((string)$attemptId, time());
        }

        try {
            // Reuse the configured accounting Drive OAuth account, independently
            // of invoice jobs, archive queues or their advisory lock.
            $this->drive->connect();
            $folder = $this->drive->metadata(ZKAccessBackupDestination::BACKUP_FOLDER_ID);
            if (!$folder || ($folder['trashed'] ?? true)
                || ($folder['mimeType'] ?? '') !== 'application/vnd.google-apps.folder'
                || !in_array(ZKAccessBackupDestination::ROOT_FOLDER_ID, $folder['parents'] ?? [], true)) {
                throw new RuntimeException('backup_drive_folder_unavailable');
            }

            // One archive per cron run. All retries keep the same Drive ID.
            foreach ($pending as $id => $entry) {
                $e = $this->store->entries()[$id] ?? null;
                if (!$e || $e['status'] !== 'ready') throw new RuntimeException('backup_not_ready');
                $path = $this->vault->path(ZKAccessBackupStore::dataName($id));
                $this->store->verify($e);
                $fileId = (string)($e['drive_id'] ?? '');
                if ($fileId === '') {
                    $fileId = $this->drive->newId();
                    $latest = $this->store->entries();
                    $latest[$id]['drive_id'] = $fileId;
                    $this->store->saveEntries($latest);
                }
                $name = 'zkeco_db_' . str_replace([' ', ':'], ['T', ''], $e['created_at'])
                    . '_' . $id . '.sql.gz.zkenc';
                $meta = $this->drive->uploadEncrypted(
                    $fileId, ZKAccessBackupDestination::BACKUP_FOLDER_ID, $name, $path
                );

                // A successful upload is NOT enough. Read back from Drive and
                // verify checksum + authenticated decryption before success.
                $roundtrip = $this->vault->path('zkverify-' . $id . '.tmp');
                if (is_file($roundtrip) || is_link($roundtrip)) @unlink($roundtrip);
                try {
                    $this->drive->downloadEncrypted($fileId, $roundtrip, (int)$meta['size']);
                    if (!hash_equals((string)$meta['md5Checksum'],
                                     (string)hash_file('md5', $roundtrip))) {
                        throw new RuntimeException('backup_drive_roundtrip_failed');
                    }
                    $this->store->verify($e, $roundtrip);
                } finally {
                    @unlink($roundtrip);
                }
                $this->store->recordUploadSuccess($id, $meta, time());
                $result['uploaded']++;
                break;
            }

            // A fresh snapshot prevents timestamp writes from being overwritten
            // by retention bookkeeping in this same cron invocation.
            $entries = $this->store->entries();
        } catch (Throwable $error) {
            if ($attemptId !== null) {
                $this->store->recordUploadFailure((string)$attemptId, $error->getMessage(), time());
            }
            throw $error;
        }

        // No old backups are removed unless at least two verified newer
        // copies survive, and the remote parent, checksum and size match.
        $uploaded = array_filter($entries, static fn(array $e): bool => $e['status'] === 'uploaded');
        $survivingCopies = count($uploaded);
        if ($survivingCopies > 2) {
            foreach ($expired as $id => $e) {
                if ($survivingCopies <= 2) break;
                if (($entries[$id]['status'] ?? '') !== 'uploaded') continue;
                $remote = $this->drive->metadata((string)$e['drive_id']);
                if (!$remote || ($remote['trashed'] ?? true)
                    || !in_array(ZKAccessBackupDestination::BACKUP_FOLDER_ID, $remote['parents'] ?? [], true)
                    || (int)($remote['size'] ?? -1) !== (int)$e['drive_size']
                    || !hash_equals((string)$e['drive_md5'], (string)($remote['md5Checksum'] ?? ''))) {
                    throw new RuntimeException('backup_retention_verification_failed');
                }
                $this->drive->deleteEncrypted((string)$e['drive_id']);
                @unlink($this->vault->path(ZKAccessBackupStore::dataName($id)));
                @unlink($this->vault->path(ZKAccessBackupStore::keyName($id)));
                unset($entries[$id]);
                $this->store->saveEntries($entries);
                $result['expired']++;
                $survivingCopies--;
            }
        }

        // Keep one week of encrypted on-host copies for diagnostics,
        // without extending the 30-day Drive retention.
        foreach ($entries as $id => $e) {
            if ($e['status'] === 'uploaded' && strtotime($e['uploaded_at'] . ' UTC') < $now - 7 * 86400) {
                @unlink($this->vault->path(ZKAccessBackupStore::dataName($id)));
            }
        }
        return $result;
    }
}
