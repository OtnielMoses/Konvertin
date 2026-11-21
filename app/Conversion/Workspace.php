<?php

namespace App\Conversion;

use Illuminate\Support\Carbon;

/**
 * Direktori sementara milik satu proses konversi.
 *
 * Seluruh berkas masukan, berkas hasil, dan status proses berada di dalam
 * direktori ini sehingga penghapusannya cukup dengan menghapus direktori.
 */
final class Workspace
{
    /**
     * @param  array{owner: string, tool: string, status: string, created_at: int, result?: array{file: string, name: string, mime: string}}  $meta
     */
    public function __construct(
        public readonly string $id,
        public readonly string $path,
        private array $meta,
    ) {}

    public function inputPath(int $index, string $extension): string
    {
        return $this->path.DIRECTORY_SEPARATOR.sprintf('input-%02d.%s', $index, $extension);
    }

    public function outputDirectory(): string
    {
        return $this->path.DIRECTORY_SEPARATOR.'output';
    }

    public function ownerHash(): string
    {
        return $this->meta['owner'];
    }

    public function status(): string
    {
        return $this->meta['status'];
    }

    public function createdAt(): Carbon
    {
        return Carbon::createFromTimestamp($this->meta['created_at']);
    }

    /**
     * @return array{file: string, name: string, mime: string}|null
     */
    public function result(): ?array
    {
        return $this->meta['result'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        return is_array($this->meta['report'] ?? null) ? $this->meta['report'] : [];
    }

    public function markFinished(ConversionResult $result): void
    {
        $this->meta['status'] = 'finished';
        $this->meta['result'] = [
            'file' => $result->path,
            'name' => $result->downloadName,
            'mime' => $result->mimeType,
        ];
        $this->meta['report'] = $result->report;

        $this->persist();
    }

    /**
     * Menandai bahwa hasil sedang dikirim, sehingga permintaan unduh kedua
     * mendapat MSG-07 (berkas hanya dapat diunduh sekali).
     */
    public function markDownloading(): void
    {
        $this->meta['status'] = 'downloading';

        $this->persist();
    }

    /**
     * @return array{owner: string, tool: string, status: string, created_at: int, result?: array{file: string, name: string, mime: string}}
     */
    public function meta(): array
    {
        return $this->meta;
    }

    public function persist(): void
    {
        file_put_contents($this->path.DIRECTORY_SEPARATOR.'meta.json', json_encode($this->meta), LOCK_EX);
    }
}
