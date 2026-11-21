<?php

namespace App\Conversion;

/**
 * Masukan yang sudah tervalidasi dan tersimpan di workspace.
 */
final readonly class ConversionInput
{
    /**
     * @param  list<string>  $files  Path absolut berkas masukan sesuai urutan Pengguna.
     * @param  string  $baseName  Nama dasar untuk berkas hasil (TBD-25).
     * @param  array<string, mixed>  $options  Opsi alat yang sudah tervalidasi.
     * @param  array<string, string>  $aux  Berkas pendamping (misalnya gambar watermark), path absolut.
     * @param  list<string>  $names  Nama asli berkas tanpa ekstensi (sudah dibersihkan), sejajar dengan $files.
     */
    public function __construct(
        public array $files,
        public string $baseName,
        public ?string $url = null,
        public array $options = [],
        public array $aux = [],
        public array $names = [],
    ) {}

    public function firstFile(): string
    {
        return $this->files[0];
    }
}
