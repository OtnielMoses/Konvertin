<?php

namespace App\Conversion;

final readonly class ConversionResult
{
    /**
     * @param  array<string, mixed>  $report  Informasi hasil untuk ditampilkan, misalnya ukuran sebelum/sesudah kompresi.
     */
    public function __construct(
        public string $path,
        public string $downloadName,
        public string $mimeType,
        public array $report = [],
    ) {}
}
