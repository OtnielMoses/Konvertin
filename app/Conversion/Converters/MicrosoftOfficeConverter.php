<?php

namespace App\Conversion\Converters;

use App\Conversion\ConversionFailed;
use App\Conversion\ConversionFailure;
use App\Conversion\ConversionInput;
use App\Conversion\ConversionResult;
use App\Conversion\Tool;
use App\Conversion\Workspace;

/**
 * Word, PowerPoint, dan Excel ke PDF memakai Microsoft Office yang sudah
 * terpasang di Windows, lewat otomasi COM (resources/engines/office_export.ps1).
 *
 * Tidak memasang perangkat lunak apa pun dan tidak butuh hak administrator.
 * Ditujukan untuk pengembangan lokal: Microsoft tidak mendukung otomasi
 * Office di sisi server, dan lisensi Office pribadi umumnya tidak mencakup
 * layanan publik. Aktif hanya bila KONVERTIN_MICROSOFT_OFFICE=true.
 */
class MicrosoftOfficeConverter extends ProcessConverter
{
    private const KINDS = [
        'word-to-pdf' => 'word',
        'powerpoint-to-pdf' => 'powerpoint',
        'excel-to-pdf' => 'excel',
    ];

    public function isAvailable(Tool $tool): bool
    {
        return isset(self::KINDS[$tool->slug]) && $this->engines->microsoftOfficeReady();
    }

    public function convert(Tool $tool, ConversionInput $input, Workspace $workspace): ConversionResult
    {
        $this->stage('Mengubah dokumen menjadi PDF');

        $kind = self::KINDS[$tool->slug]
            ?? throw ConversionFailed::because(ConversionFailure::EngineUnavailable);

        $output = $workspace->outputDirectory().DIRECTORY_SEPARATOR.'result.pdf';

        $result = $this->run([
            'powershell.exe',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-File',
            resource_path('engines/office_export.ps1'),
            '-Kind',
            $kind,
            '-Source',
            $input->firstFile(),
            '-Target',
            $output,
        ], $workspace->path);

        if ($result->exitCode() === 4) {
            throw ConversionFailed::because(ConversionFailure::Encrypted, 'office document is password protected');
        }

        if (! $result->successful() || ! is_file($output) || ! $this->isPdf($output)) {
            throw ConversionFailed::because(ConversionFailure::Generic, 'office export exit code '.$result->exitCode());
        }

        return $this->result($tool, $input, $output);
    }
}
