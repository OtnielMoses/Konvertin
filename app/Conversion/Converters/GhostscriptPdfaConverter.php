<?php

namespace App\Conversion\Converters;

use App\Conversion\ConversionFailed;
use App\Conversion\ConversionFailure;
use App\Conversion\ConversionInput;
use App\Conversion\ConversionResult;
use App\Conversion\Tool;
use App\Conversion\Workspace;

/**
 * Konversi PDF ke PDF/A-2b memakai Ghostscript.
 *
 * PDFACompatibilityPolicy=2 membuat Ghostscript berhenti bila berkas tidak
 * dapat memenuhi standar, sehingga Sistem tidak pernah menyajikan berkas yang
 * tidak sesuai sebagai konversi berhasil (4.11/REQ-13, NFR-SAF-2).
 */
class GhostscriptPdfaConverter extends ProcessConverter
{
    public function isAvailable(Tool $tool): bool
    {
        return $this->engines->ghostscript() !== null && $this->engines->pdfaIccProfile() !== null;
    }

    public function convert(Tool $tool, ConversionInput $input, Workspace $workspace): ConversionResult
    {
        $this->stage('Mengubah ke standar arsip PDF/A');

        $binary = $this->engines->ghostscript();
        $iccProfile = $this->engines->pdfaIccProfile();

        if ($binary === null || $iccProfile === null) {
            throw ConversionFailed::because(ConversionFailure::EngineUnavailable);
        }

        $definition = $workspace->path.DIRECTORY_SEPARATOR.'PDFA_def.ps';
        file_put_contents($definition, $this->pdfaDefinition($iccProfile));

        $output = $workspace->outputDirectory().DIRECTORY_SEPARATOR.'result.pdf';

        $result = $this->run([
            $binary,
            '-dPDFA=2',
            '-dBATCH',
            '-dNOPAUSE',
            '-dSAFER',
            '-dNOOUTERSAVE',
            '-dPDFACompatibilityPolicy=2',
            '-sColorConversionStrategy=RGB',
            '-sProcessColorModel=DeviceRGB',
            '-sDEVICE=pdfwrite',
            '--permit-file-read='.$this->postScriptPath($iccProfile),
            '-sOutputFile='.$output,
            $definition,
            $input->firstFile(),
        ], $workspace->path);

        if (! $result->successful() || ! is_file($output) || ! $this->isPdf($output)) {
            $log = strtolower($result->output().$result->errorOutput());

            throw ConversionFailed::because(
                str_contains($log, 'password') ? ConversionFailure::Encrypted : ConversionFailure::PdfaNotCompliant,
                'ghostscript exit code '.$result->exitCode(),
            );
        }

        return $this->result($tool, $input, $output);
    }

    private function pdfaDefinition(string $iccProfile): string
    {
        $profile = $this->postScriptPath($iccProfile);

        return <<<PS
        %!
        /ICCProfile ({$profile}) def
        [/_objdef {icc_PDFA} /type /stream /OBJ pdfmark
        [{icc_PDFA} <</N 3>> /PUT pdfmark
        [{icc_PDFA} ICCProfile (r) file /PUT pdfmark
        [/_objdef {OutputIntent_PDFA} /type /dict /OBJ pdfmark
        [{OutputIntent_PDFA} <<
          /Type /OutputIntent
          /S /GTS_PDFA1
          /DestOutputProfile {icc_PDFA}
          /OutputConditionIdentifier (sRGB)
        >> /PUT pdfmark
        [{Catalog} <</OutputIntents [ {OutputIntent_PDFA} ]>> /PUT pdfmark
        PS;
    }

    /**
     * Ghostscript menerima garis miring maju pada semua sistem operasi. Tanda
     * kurung di-escape karena dipakai sebagai pembatas string PostScript.
     */
    private function postScriptPath(string $path): string
    {
        return str_replace(['(', ')'], ['\\(', '\\)'], str_replace('\\', '/', $path));
    }
}
