<?php

namespace App\Conversion\Converters;

use App\Conversion\ConversionFailed;
use App\Conversion\ConversionFailure;
use App\Conversion\ConversionInput;
use App\Conversion\ConversionResult;
use App\Conversion\EngineLocator;
use App\Conversion\ProgressReporter;
use App\Conversion\Tool;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

/**
 * Dasar bersama untuk mesin konversi yang dijalankan sebagai proses terpisah.
 */
abstract class ProcessConverter implements Converter
{
    private const MIME_TYPES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'zip' => 'application/zip',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'md' => 'text/markdown; charset=UTF-8',
    ];

    public function __construct(protected EngineLocator $engines) {}

    /**
     * Mesin berbasis proses berjalan di server Konvertin sendiri.
     */
    public function processingNote(Tool $tool): ?string
    {
        return null;
    }

    /**
     * Keterangan tahap yang tidak dapat diukur per langkah, misalnya satu dokumen
     * yang diubah oleh Office. Antarmuka menampilkannya tanpa persentase.
     */
    protected function stage(string $label): void
    {
        app(ProgressReporter::class)->report(null, $label);
    }

    /**
     * Menjalankan perintah dengan batas waktu proses (NFR-PERF-3).
     *
     * @param  list<string>  $command
     *
     * @throws ConversionFailed
     */
    protected function run(array $command, ?string $workingDirectory = null, ?string $stdin = null): ProcessResult
    {
        try {
            $process = Process::timeout(max(10, (int) config('konvertin.process_timeout')))
                ->env($this->engines->environment())
                ->input($stdin ?? '');

            if ($workingDirectory !== null) {
                $process = $process->path($workingDirectory);
            }

            // Progress lines from the engine arrive on stderr while it runs.
            $progress = app(ProgressReporter::class);
            $pending = '';

            return $process->run($command, function (string $type, string $output) use (&$pending, $progress): void {
                if ($type !== 'err') {
                    return;
                }

                $pending .= $output;

                while (($newline = strpos($pending, "\n")) !== false) {
                    $progress->engineLine(rtrim(substr($pending, 0, $newline), "\r"));
                    $pending = substr($pending, $newline + 1);
                }
            });
        } catch (ProcessTimedOutException $exception) {
            throw new ConversionFailed(ConversionFailure::Timeout, 'process timed out', $exception);
        }
    }

    /**
     * Membentuk hasil konversi dengan nama unduhan sesuai TBD-25.
     *
     * @throws ConversionFailed
     */
    protected function result(Tool $tool, ConversionInput $input, string $path): ConversionResult
    {
        if (! is_file($path) || filesize($path) === 0) {
            throw ConversionFailed::because(ConversionFailure::Generic, 'empty output');
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $downloadName = match (true) {
            $tool->isMerge() => 'gabungan.pdf',
            $tool->slug === 'pdf-to-pdfa' => $input->baseName.'-pdfa.pdf',
            $extension === 'zip' => $input->baseName.'-jpg.zip',
            default => $input->baseName.'.'.$extension,
        };

        return new ConversionResult(
            path: $path,
            downloadName: $downloadName,
            mimeType: self::MIME_TYPES[$extension] ?? 'application/octet-stream',
        );
    }

    /**
     * Mengubah path lokal menjadi URI file:// yang dipahami LibreOffice dan peramban.
     */
    protected function fileUri(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        return str_starts_with($normalized, '/') ? 'file://'.$normalized : 'file:///'.$normalized;
    }

    protected function isPdf(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = (string) fread($handle, 1024);
        fclose($handle);

        return str_contains($head, '%PDF-');
    }
}
