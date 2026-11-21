<?php

namespace App\Conversion;

use ZipArchive;

/**
 * Pemeriksaan struktur berkas hasil sebelum ditawarkan untuk diunduh, agar
 * berkas yang rusak (misalnya terpotong karena disk penuh) tidak pernah dikirim.
 * Isi dokumen tidak dibaca selain penanda struktur.
 */
class ResultValidator
{
    /**
     * @throws ConversionFailed
     */
    public function validate(ConversionResult $result): void
    {
        $path = $result->path;

        if (! is_file($path) || filesize($path) === 0) {
            throw ConversionFailed::because(ConversionFailure::InvalidResult, 'result missing or empty');
        }

        $valid = match (true) {
            str_starts_with($result->mimeType, 'application/pdf') => $this->isPdf($path),
            str_starts_with($result->mimeType, 'application/zip'),
            str_contains($result->mimeType, 'openxmlformats') => $this->isZip($path),
            default => true,
        };

        if (! $valid) {
            throw ConversionFailed::because(ConversionFailure::InvalidResult, 'result failed structure check');
        }
    }

    /**
     * Header %PDF- di awal dan penanda %%EOF di bagian akhir berkas.
     */
    private function isPdf(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = (string) fread($handle, 5);
        $size = (int) filesize($path);
        fseek($handle, max(0, $size - 2048));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        return $head === '%PDF-' && str_contains($tail, '%%EOF');
    }

    /**
     * ZIP (termasuk DOCX, PPTX, XLSX) yang dapat dibuka dan berisi minimal satu berkas.
     */
    private function isZip(string $path): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        $count = $zip->numFiles;
        $zip->close();

        return $count > 0;
    }
}
