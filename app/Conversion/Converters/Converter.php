<?php

namespace App\Conversion\Converters;

use App\Conversion\ConversionFailed;
use App\Conversion\ConversionInput;
use App\Conversion\ConversionResult;
use App\Conversion\Tool;
use App\Conversion\Workspace;

/**
 * Kontrak mesin konversi. Setiap fitur dipetakan ke satu implementasi
 * melalui config/konvertin.php sehingga mesin dapat diganti per fitur
 * tanpa mengubah alur Pengguna (NFR-QUA-4).
 */
interface Converter
{
    public function isAvailable(Tool $tool): bool;

    /**
     * Keterangan untuk Pengguna bila berkas diproses di luar server Konvertin,
     * atau null bila diproses di server ini.
     */
    public function processingNote(Tool $tool): ?string;

    /**
     * @throws ConversionFailed
     */
    public function convert(Tool $tool, ConversionInput $input, Workspace $workspace): ConversionResult;
}
