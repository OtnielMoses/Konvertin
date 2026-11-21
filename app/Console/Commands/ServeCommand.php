<?php

namespace App\Console\Commands;

use Illuminate\Foundation\Console\ServeCommand as BaseServeCommand;

/**
 * `php artisan serve` dengan batas unggah yang sesuai konfigurasi Konvertin.
 *
 * Server bawaan PHP memakai batas php.ini (biasanya 2 MB per berkas dan 20
 * berkas), sehingga unggahan yang sah ditolak sebelum mencapai aplikasi.
 * Pengaturan ditambahkan sebagai argumen -d hanya untuk proses server ini;
 * php.ini di komputer tidak diubah.
 */
class ServeCommand extends BaseServeCommand
{
    /**
     * @return list<string>
     */
    protected function serverCommand()
    {
        $command = parent::serverCommand();
        $binary = array_shift($command);

        return [$binary, ...$this->uploadSettings(), ...$command];
    }

    /**
     * @return list<string>
     */
    private function uploadSettings(): array
    {
        $fileMb = (int) ceil((int) config('konvertin.max_file_kb') / 1024);
        $files = (int) config('konvertin.max_files');
        // Semua berkas utama ditambah satu berkas pendamping, plus ruang untuk field formulir.
        $postMb = $fileMb * ($files + 1) + 16;

        return [
            '-d', "upload_max_filesize={$fileMb}M",
            '-d', "post_max_size={$postMb}M",
            '-d', 'max_file_uploads='.($files + 5),
            '-d', 'max_execution_time='.((int) config('konvertin.process_timeout') + 120),
        ];
    }
}
