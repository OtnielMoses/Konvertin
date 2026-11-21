<?php

namespace App\Conversion\Converters;

use App\Conversion\ConversionFailed;
use App\Conversion\ConversionFailure;
use App\Conversion\ConversionInput;
use App\Conversion\ConversionResult;
use App\Conversion\Tool;
use App\Conversion\Workspace;

/**
 * Konversi Word, PowerPoint, dan Excel ke PDF memakai LibreOffice headless.
 *
 * Setiap proses memakai profil LibreOffice sendiri di dalam workspace agar
 * proses yang berjalan bersamaan tidak saling mengunci (NFR-SAF-3). Makro
 * tidak dijalankan karena LibreOffice headless memakai tingkat keamanan makro
 * bawaan (NFR-SEC-5).
 */
class LibreOfficeConverter extends ProcessConverter
{
    public function isAvailable(Tool $tool): bool
    {
        return $this->engines->libreOffice() !== null;
    }

    public function convert(Tool $tool, ConversionInput $input, Workspace $workspace): ConversionResult
    {
        $this->stage('Mengubah dokumen menjadi PDF');

        $binary = $this->engines->libreOffice()
            ?? throw ConversionFailed::because(ConversionFailure::EngineUnavailable);

        $source = $input->firstFile();
        $outputDirectory = $workspace->outputDirectory();

        $result = $this->run([
            $binary,
            '-env:UserInstallation='.$this->fileUri($workspace->path.DIRECTORY_SEPARATOR.'lo-profile'),
            '--headless',
            '--invisible',
            '--norestore',
            '--nologo',
            '--nodefault',
            '--nolockcheck',
            '--convert-to',
            'pdf',
            '--outdir',
            $outputDirectory,
            $source,
        ], $workspace->path);

        $output = $outputDirectory.DIRECTORY_SEPARATOR.pathinfo($source, PATHINFO_FILENAME).'.pdf';

        if (! $result->successful() || ! is_file($output) || ! $this->isPdf($output)) {
            throw ConversionFailed::because(ConversionFailure::Generic, 'libreoffice exit code '.$result->exitCode());
        }

        return $this->result($tool, $input, $output);
    }
}
