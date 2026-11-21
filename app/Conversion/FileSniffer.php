<?php

namespace App\Conversion;

use ZipArchive;

/**
 * Mengenali format berkas dari isinya, bukan dari ekstensinya (NFR-SEC-2).
 */
class FileSniffer
{
    private const OLE_SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    /**
     * Mengembalikan kode format (jpg, pdf, doc, docx, ppt, pptx, xls, xlsx) atau null.
     */
    public function detect(string $path): ?string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $head = (string) fread($handle, 1024);
        fclose($handle);

        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'jpg';
        }

        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            return 'png';
        }

        if (str_contains($head, '%PDF-')) {
            return 'pdf';
        }

        if (str_starts_with($head, "PK\x03\x04")) {
            return $this->detectOfficeOpenXml($path);
        }

        if (str_starts_with($head, self::OLE_SIGNATURE)) {
            return $this->detectLegacyOffice($path);
        }

        return null;
    }

    private function detectOfficeOpenXml(string $path): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            // Berkas berisi makro tidak diterima (NFR-SEC-5).
            foreach (['word/vbaProject.bin', 'xl/vbaProject.bin', 'ppt/vbaProject.bin'] as $macroPart) {
                if ($zip->locateName($macroPart) !== false) {
                    return null;
                }
            }

            return match (true) {
                $zip->locateName('word/document.xml') !== false => 'docx',
                $zip->locateName('xl/workbook.xml') !== false => 'xlsx',
                $zip->locateName('ppt/presentation.xml') !== false => 'pptx',
                default => null,
            };
        } finally {
            $zip->close();
        }
    }

    /**
     * Format Office lama disimpan sebagai Compound File. Nama stream utamanya
     * tersimpan dalam UTF-16LE di direktori berkas.
     */
    private function detectLegacyOffice(string $path): ?string
    {
        $contents = (string) file_get_contents($path);

        $streams = [
            'doc' => 'WordDocument',
            'xls' => 'Workbook',
            'ppt' => 'PowerPoint Document',
        ];

        foreach ($streams as $format => $streamName) {
            if (str_contains($contents, mb_convert_encoding($streamName, 'UTF-16LE', 'UTF-8'))) {
                return $format;
            }
        }

        if (str_contains($contents, mb_convert_encoding('Book', 'UTF-16LE', 'UTF-8'))) {
            return 'xls';
        }

        return null;
    }
}
