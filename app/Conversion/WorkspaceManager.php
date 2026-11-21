<?php

namespace App\Conversion;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Mengelola penyimpanan sementara (fitur 4.14, BR-03).
 */
class WorkspaceManager
{
    public const ID_PATTERN = '[A-Za-z0-9]{40}';

    public function __construct(
        private Filesystem $files,
        private string $root,
        private int $ttlMinutes,
    ) {}

    public function create(string $ownerToken, string $toolSlug): Workspace
    {
        $this->files->ensureDirectoryExists($this->root);

        // Nama acak yang tidak dapat ditebak dan tidak memuat nama asli berkas (NFR-SEC-3).
        $id = Str::random(40);
        $path = $this->root.DIRECTORY_SEPARATOR.$id;

        $this->files->makeDirectory($path.DIRECTORY_SEPARATOR.'output', 0700, true);

        $workspace = new Workspace($id, $path, [
            'owner' => $this->hashOwner($ownerToken),
            'tool' => $toolSlug,
            'status' => 'processing',
            'created_at' => now()->getTimestamp(),
        ]);

        $workspace->persist();

        return $workspace;
    }

    public function find(string $id): ?Workspace
    {
        if (preg_match('/^'.self::ID_PATTERN.'$/', $id) !== 1) {
            return null;
        }

        $path = $this->root.DIRECTORY_SEPARATOR.$id;
        $metaPath = $path.DIRECTORY_SEPARATOR.'meta.json';

        if (! $this->files->isFile($metaPath)) {
            return null;
        }

        $meta = json_decode((string) $this->files->get($metaPath), true);

        if (! is_array($meta) || ! isset($meta['owner'], $meta['status'], $meta['created_at'])) {
            return null;
        }

        return new Workspace($id, $path, $meta);
    }

    public function belongsTo(Workspace $workspace, string $ownerToken): bool
    {
        return hash_equals($workspace->ownerHash(), $this->hashOwner($ownerToken));
    }

    public function isExpired(Workspace $workspace): bool
    {
        return $workspace->createdAt()->addMinutes($this->ttlMinutes)->isPast();
    }

    /**
     * Menghapus seluruh isi workspace. Kegagalan dicatat agar dapat diulang
     * pada siklus pembersihan berikutnya (NFR-SAF-4). Nama dan isi berkas
     * Pengguna tidak ikut dicatat (NFR-SEC-7).
     */
    public function destroy(Workspace|string $workspace): bool
    {
        $id = $workspace instanceof Workspace ? $workspace->id : $workspace;
        $path = $this->root.DIRECTORY_SEPARATOR.$id;

        if (! $this->files->isDirectory($path)) {
            return true;
        }

        $deleted = $this->files->deleteDirectory($path);

        if (! $deleted || $this->files->isDirectory($path)) {
            Log::warning('konvertin.cleanup_failed', ['workspace' => $id]);

            return false;
        }

        return true;
    }

    /**
     * Menghapus workspace yang melewati batas waktu penyimpanan (UC-14 AP-2).
     *
     * @return array{deleted: int, failed: int}
     */
    public function purgeExpired(): array
    {
        $deleted = 0;
        $failed = 0;

        if (! $this->files->isDirectory($this->root)) {
            return ['deleted' => 0, 'failed' => 0];
        }

        $cutoff = now()->subMinutes($this->ttlMinutes)->getTimestamp();

        foreach ($this->files->directories($this->root) as $directory) {
            $id = basename($directory);
            $workspace = $this->find($id);

            // Direktori tanpa status proses yang sah dinilai dari waktu modifikasinya.
            $createdAt = $workspace?->createdAt()->getTimestamp() ?? (int) filemtime($directory);

            if ($createdAt > $cutoff) {
                continue;
            }

            $this->destroy($id) ? $deleted++ : $failed++;
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    public function ttlMinutes(): int
    {
        return $this->ttlMinutes;
    }

    private function hashOwner(string $ownerToken): string
    {
        return hash_hmac('sha256', $ownerToken, (string) config('app.key'));
    }
}
