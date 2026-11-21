<?php

namespace App\Conversion;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Menemukan lokasi mesin konversi di server (TBD-08, TBD-11).
 */
class EngineLocator
{
    public function libreOffice(): ?string
    {
        return $this->firstExisting(config('konvertin.engines.libreoffice'), [
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
            '/usr/bin/soffice',
            '/usr/bin/libreoffice',
            '/usr/local/bin/soffice',
            '/opt/libreoffice/program/soffice',
            '/Applications/LibreOffice.app/Contents/MacOS/soffice',
        ]);
    }

    public function browser(): ?string
    {
        return $this->firstExisting(config('konvertin.engines.browser'), [
            'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
            'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        ]);
    }

    public function ghostscript(): ?string
    {
        $candidates = [
            '/usr/bin/gs',
            '/usr/local/bin/gs',
            '/opt/homebrew/bin/gs',
        ];

        foreach (glob('C:\\Program Files\\gs\\gs*\\bin\\gswin64c.exe') ?: [] as $windowsBinary) {
            array_unshift($candidates, $windowsBinary);
        }

        return $this->firstExisting(config('konvertin.engines.ghostscript'), $candidates);
    }

    /**
     * Profil warna sRGB yang dibutuhkan OutputIntent PDF/A.
     */
    public function pdfaIccProfile(): ?string
    {
        $configured = config('konvertin.engines.pdfa_icc_profile');

        if (is_string($configured) && is_file($configured)) {
            return $configured;
        }

        $binary = $this->ghostscript();

        if ($binary === null) {
            return null;
        }

        $candidates = [
            dirname($binary, 2).DIRECTORY_SEPARATOR.'iccprofiles'.DIRECTORY_SEPARATOR.'srgb.icc',
            ...(glob('/usr/share/ghostscript/*/iccprofiles/srgb.icc') ?: []),
            '/usr/share/color/icc/ghostscript/srgb.icc',
            ...(glob('/opt/homebrew/share/ghostscript/*/iccprofiles/srgb.icc') ?: []),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Variabel lingkungan untuk proses mesin konversi.
     *
     * Symfony Process hanya mewariskan variabel yang juga ada di $_SERVER.
     * Pada beberapa web server (misalnya server bawaan PHP), $_SERVER hanya
     * berisi data permintaan, sehingga variabel seperti APPDATA, HOME, dan
     * PATH hilang dan mesin gagal menemukan pustakanya. Karena itu seluruh
     * lingkungan proses server diteruskan secara eksplisit.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        return array_filter(getenv(), fn (mixed $value): bool => is_string($value));
    }

    public function python(): string
    {
        return (string) config('konvertin.engines.python');
    }

    /**
     * Memeriksa apakah pustaka Python untuk konversi PDF tersedia. Hasil
     * pemeriksaan disimpan sebentar agar Beranda tidak menjalankan proses
     * setiap kali dimuat.
     */
    public function pythonReady(): bool
    {
        return $this->pythonCheck('check');
    }

    /**
     * Mesin OCR (RapidOCR + OpenCV) untuk OCR PDF dan opsi OCR pada Scan to PDF.
     */
    public function ocrReady(): bool
    {
        return $this->pythonCheck('check-ocr');
    }

    /**
     * Microsoft Office terpasang dan otomasi COM diizinkan lewat konfigurasi.
     * Hanya untuk Windows; bukan untuk server publik (lisensi Office dan
     * dukungan Microsoft untuk otomasi sisi server terbatas).
     */
    public function microsoftOfficeReady(): bool
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! config('konvertin.engines.microsoft_office')) {
            return false;
        }

        foreach (['C:\\Program Files\\Microsoft Office\\root\\Office16', 'C:\\Program Files (x86)\\Microsoft Office\\root\\Office16'] as $directory) {
            if (is_file($directory.'\\WINWORD.EXE') && is_file($directory.'\\EXCEL.EXE') && is_file($directory.'\\POWERPNT.EXE')) {
                return true;
            }
        }

        return false;
    }

    private function pythonCheck(string $operation): bool
    {
        return Cache::remember('konvertin.engine.'.$operation, now()->addMinutes(10), function () use ($operation): bool {
            $result = Process::timeout(60)->env($this->environment())->input('')->run([
                $this->python(),
                config('konvertin.engines.python_script'),
                $operation,
            ]);

            if (! $result->failed()) {
                return true;
            }

            Log::warning('konvertin.python_engine_unavailable', [
                'check' => $operation,
                'exit_code' => $result->exitCode(),
                'error' => mb_substr($result->errorOutput(), -500),
            ]);

            return false;
        });
    }

    /**
     * @param  list<string>  $candidates
     */
    private function firstExisting(mixed $configured, array $candidates): ?string
    {
        if (is_string($configured) && $configured !== '') {
            return is_file($configured) ? $configured : null;
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
