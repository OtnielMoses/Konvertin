<?php

namespace App\Conversion;

/**
 * Batas unggah yang benar-benar berlaku: nilai terkecil antara konfigurasi
 * Konvertin dan pengaturan PHP server (upload_max_filesize, post_max_size,
 * max_file_uploads). Halaman alat memakai nilai ini agar batas yang
 * ditampilkan dan diperiksa peramban sama dengan yang diterima server.
 */
class UploadLimits
{
    public function configuredFileBytes(): int
    {
        return (int) config('konvertin.max_file_kb') * 1024;
    }

    public function fileBytes(): int
    {
        return min($this->configuredFileBytes(), $this->iniBytes('upload_max_filesize'));
    }

    /**
     * Batas per foto (JPG/PNG), tidak pernah melebihi batas per berkas.
     */
    public function imageBytes(): int
    {
        return min((int) config('konvertin.max_image_kb') * 1024, $this->fileBytes());
    }

    /**
     * Jumlah berkas utama per proses. Satu slot disisakan untuk berkas pendamping
     * (misalnya gambar watermark).
     */
    public function maxFiles(): int
    {
        $configured = (int) config('konvertin.max_files');
        $uploads = (int) ini_get('max_file_uploads');

        return $uploads > 1 ? min($configured, $uploads - 1) : $configured;
    }

    /**
     * Ukuran total satu kali kirim (seluruh berkas sekaligus).
     */
    public function requestBytes(): int
    {
        return $this->iniBytes('post_max_size');
    }

    /**
     * Nilai ini PHP seperti "2M" atau "1G" dalam byte; 0 atau kosong berarti tanpa batas.
     */
    private function iniBytes(string $key): int
    {
        $raw = trim((string) ini_get($key));

        if ($raw === '' || $raw === '0' || $raw === '-1') {
            return PHP_INT_MAX;
        }

        $number = (float) $raw;

        return (int) match (strtoupper(substr($raw, -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
