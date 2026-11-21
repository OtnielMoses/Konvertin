<?php

namespace App\Conversion\Converters;

use App\Conversion\ConversionFailed;
use App\Conversion\ConversionFailure;
use App\Conversion\ConversionInput;
use App\Conversion\ConversionResult;
use App\Conversion\Tool;
use App\Conversion\ToolCatalog;
use App\Conversion\Workspace;

/**
 * Alat berbasis PyMuPDF dan pustaka Python pendukungnya. Skrip mesin berada
 * di resources/engines/konvertin_engine.py.
 */
class PythonConverter extends ProcessConverter
{
    public const OPERATIONS = [
        'jpg-to-pdf' => 'jpg2pdf',
        'pdf-to-jpg' => 'pdf2jpg',
        'pdf-to-word' => 'pdf2docx',
        'pdf-to-powerpoint' => 'pdf2pptx',
        'pdf-to-excel' => 'pdf2xlsx',
        'pdf-to-markdown' => 'pdf2md',
        'merge-pdf' => 'merge',
        'split-pdf' => 'split',
        'organize-pdf' => 'organize',
        'rotate-pdf' => 'rotate',
        'crop-pdf' => 'crop',
        'compress-pdf' => 'compress',
        'repair-pdf' => 'repair',
        'ocr-pdf' => 'ocr',
        'edit-pdf' => 'overlay',
        'sign-pdf' => 'overlay',
        'page-numbers' => 'pagenumbers',
        'watermark-pdf' => 'watermark',
        'protect-pdf' => 'protect',
        'unlock-pdf' => 'unlock',
        'redact-pdf' => 'redact',
        'compare-pdf' => 'compare',
        'pdf-forms' => 'forms',
        'scan-to-pdf' => 'scan',
        'create-workflow' => 'workflow',
        'preflight-pdf' => 'preflight',
        'bookmark-pdf' => 'bookmarks',
    ];

    /**
     * Keterangan awal tiap operasi; mesin menyusulkan langkah yang lebih rinci bila ada.
     */
    private const STAGE_LABELS = [
        'jpg2pdf' => 'Menyiapkan foto',
        'pdf2jpg' => 'Merender halaman',
        'pdf2docx' => 'Mengubah tata letak menjadi dokumen Word',
        'pdf2pptx' => 'Menyusun slide',
        'pdf2xlsx' => 'Mencari tabel',
        'pdf2md' => 'Membaca struktur dokumen',
        'merge' => 'Menggabungkan berkas',
        'split' => 'Memisahkan halaman',
        'organize' => 'Menyusun ulang halaman',
        'rotate' => 'Memutar halaman',
        'crop' => 'Memotong halaman',
        'compress' => 'Menganalisis isi berkas',
        'repair' => 'Memeriksa struktur PDF',
        'ocr' => 'Mencari halaman hasil pindai',
        'pagenumbers' => 'Menambahkan nomor halaman',
        'watermark' => 'Menambahkan watermark',
        'overlay' => 'Menerapkan perubahan',
        'protect' => 'Mengenkripsi PDF',
        'unlock' => 'Membuka kunci PDF',
        'redact' => 'Menghapus bagian yang disensor',
        'compare' => 'Membandingkan teks kedua versi',
        'forms' => 'Mengisi formulir',
        'scan' => 'Meluruskan foto',
        'workflow' => 'Menjalankan workflow',
        'pack' => 'Menyusun paket',
        'preflight' => 'Memeriksa dokumen',
        'bookmarks' => 'Menyimpan bookmark',
        'extract-blocks' => 'Membaca teks dokumen',
        'apply-translation' => 'Menulis terjemahan ke PDF',
    ];

    /**
     * Alat yang membutuhkan mesin OCR (RapidOCR) selain pustaka dasar.
     */
    private const OCR_TOOLS = ['ocr-pdf'];

    public function isAvailable(Tool $tool): bool
    {
        if (! isset(self::OPERATIONS[$tool->slug]) || ! $this->engines->pythonReady()) {
            return false;
        }

        return ! in_array($tool->slug, self::OCR_TOOLS, true) || $this->engines->ocrReady();
    }

    public function convert(Tool $tool, ConversionInput $input, Workspace $workspace): ConversionResult
    {
        $operation = self::OPERATIONS[$tool->slug]
            ?? throw ConversionFailed::because(ConversionFailure::EngineUnavailable, 'unsupported tool');

        $payload = $this->runOperation($operation, $input, $workspace, $this->engineOptions($tool, $input));

        return $this->resultFrom($tool, $input, $payload, $workspace);
    }

    /**
     * Menjalankan satu operasi mesin dan mengembalikan JSON hasilnya.
     *
     * @param  array<string, mixed>  $options
     * @param  list<string>|null  $files
     * @return array{file: string, download?: string|null, report?: array<string, mixed>}
     *
     * @throws ConversionFailed
     */
    public function runOperation(string $operation, ConversionInput $input, Workspace $workspace, array $options = [], ?array $files = null): array
    {
        $command = [
            $this->engines->python(),
            config('konvertin.engines.python_script'),
            $operation,
            '--out-dir',
            $workspace->outputDirectory(),
            '--name',
            $input->baseName,
        ];

        foreach ($input->aux as $key => $path) {
            $command[] = '--aux';
            $command[] = $key.'='.$path;
        }

        array_push($command, ...($files ?? $input->files));

        $options['_max_pages'] = (int) config('konvertin.max_pages');

        $this->stage(self::STAGE_LABELS[$operation] ?? 'Memproses dokumen');

        // Opsi lewat stdin: kata sandi tidak muncul di daftar proses sistem.
        $result = $this->run($command, null, (string) json_encode($options));

        if (! $result->successful()) {
            throw ConversionFailed::because(
                ConversionFailure::fromEngineExitCode((int) $result->exitCode()),
                'engine '.$operation.' exit code '.$result->exitCode().$this->engineHint($result->errorOutput()),
            );
        }

        return $this->payload($result->output(), $workspace);
    }

    /**
     * Baris terakhir stderr mesin untuk log: hanya nama jenis galat atau pesan
     * teknis singkat dari mesin. Karakter selain huruf, angka, dan tanda baca
     * sederhana dibuang, sehingga path dan isi dokumen tidak ikut tercatat.
     */
    private function engineHint(string $stderr): string
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $stderr)),
            fn (string $line): bool => $line !== '' && ! str_starts_with($line, '@@progress'),
        ));
        $last = (string) end($lines);
        $clean = trim((string) preg_replace('/[^A-Za-z0-9_:,. -]+/', ' ', $last));

        return $clean === '' ? '' : ' ('.mb_substr($clean, 0, 120).')';
    }

    /**
     * Waktu yang boleh dipakai pencarian kompresi bertahap, di bawah batas waktu proses.
     */
    public function timeBudget(): int
    {
        return max(20, (int) floor((int) config('konvertin.process_timeout') * 0.6));
    }

    /**
     * @return array<string, mixed>
     */
    private function engineOptions(Tool $tool, ConversionInput $input): array
    {
        $options = $input->options;

        if ($tool->slug === 'sign-pdf') {
            $options['mode'] = 'sign';
        }

        if ($tool->slug === 'merge-pdf') {
            $options['_titles'] = $input->names;
        }

        if (in_array($tool->slug, ['compress-pdf', 'create-workflow'], true)) {
            $options['_time_budget'] = $this->timeBudget();
        }

        if ($tool->slug === 'create-workflow') {
            $operations = app(ToolCatalog::class)->workflowSteps();
            $steps = json_decode((string) ($options['steps'] ?? '[]'), true) ?: [];

            $options['steps'] = array_map(fn (array $step): array => [
                'op' => $operations[$step['tool']] ?? 'unknown',
                'options' => $step['options'] ?? [],
            ], $steps);
        }

        return $options;
    }

    /**
     * @param  array{file: string, download?: string|null, report?: array<string, mixed>}  $payload
     *
     * @throws ConversionFailed
     */
    public function resultFrom(Tool $tool, ConversionInput $input, array $payload, Workspace $workspace): ConversionResult
    {
        $path = $payload['file'];
        $download = is_string($payload['download'] ?? null) ? basename($payload['download']) : '';

        $result = $this->result($tool, $input, $path);

        return new ConversionResult(
            path: $result->path,
            downloadName: $download !== '' ? $download : $result->downloadName,
            mimeType: $result->mimeType,
            report: is_array($payload['report'] ?? null) ? $payload['report'] : [],
        );
    }

    /**
     * Membaca baris JSON terakhir dan memastikan path hasil berada di dalam workspace.
     *
     * @return array{file: string, download?: string|null, report?: array<string, mixed>}
     *
     * @throws ConversionFailed
     */
    private function payload(string $stdout, Workspace $workspace): array
    {
        $lines = array_reverse(array_filter(array_map('trim', explode("\n", $stdout))));

        foreach ($lines as $line) {
            $payload = json_decode($line, true);

            if (! is_array($payload) || ! isset($payload['file'])) {
                continue;
            }

            $path = realpath((string) $payload['file']);
            $root = realpath($workspace->outputDirectory());

            if ($path !== false && $root !== false && str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
                $payload['file'] = $path;

                return $payload;
            }
        }

        throw ConversionFailed::because(ConversionFailure::Generic, 'engine returned no output');
    }
}
