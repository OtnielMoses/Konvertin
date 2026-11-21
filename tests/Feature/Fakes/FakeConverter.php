<?php

namespace Tests\Feature\Fakes;

use App\Conversion\ConversionFailed;
use App\Conversion\ConversionFailure;
use App\Conversion\ConversionInput;
use App\Conversion\ConversionResult;
use App\Conversion\Converters\Converter;
use App\Conversion\Tool;
use App\Conversion\Workspace;

/**
 * Mesin konversi palsu untuk menguji alur tanpa mesin sungguhan.
 */
class FakeConverter implements Converter
{
    public static bool $available = true;

    public static ?ConversionFailure $failWith = null;

    /** Menulis PDF terpotong, untuk menguji pemeriksaan hasil sebelum diunduh. */
    public static bool $corrupt = false;

    /** @var list<ConversionInput> */
    public static array $received = [];

    public static function reset(): void
    {
        self::$available = true;
        self::$failWith = null;
        self::$corrupt = false;
        self::$received = [];
    }

    private static function onePagePdf(): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$body."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 4\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer << /Size 4 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    public function isAvailable(Tool $tool): bool
    {
        return self::$available;
    }

    public function processingNote(Tool $tool): ?string
    {
        return null;
    }

    public function convert(Tool $tool, ConversionInput $input, Workspace $workspace): ConversionResult
    {
        self::$received[] = $input;

        $partial = $workspace->outputDirectory().DIRECTORY_SEPARATOR.'result.pdf';
        file_put_contents($partial, self::$corrupt ? substr(self::onePagePdf(), 0, 60) : self::onePagePdf());

        if (self::$failWith !== null) {
            throw ConversionFailed::because(self::$failWith);
        }

        return new ConversionResult($partial, $input->baseName.'.pdf', 'application/pdf');
    }
}
