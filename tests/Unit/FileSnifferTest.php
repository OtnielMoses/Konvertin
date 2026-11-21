<?php

namespace Tests\Unit;

use App\Conversion\FileSniffer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class FileSnifferTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->paths, 'is_file'));

        parent::tearDown();
    }

    private function fileWith(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'snf');
        file_put_contents($path, $contents);

        return $this->paths[] = $path;
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function zipWith(array $entries): string
    {
        $path = $this->fileWith('');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $path;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function officeOpenXmlParts(): array
    {
        return [
            'word' => ['word/document.xml', 'docx'],
            'excel' => ['xl/workbook.xml', 'xlsx'],
            'powerpoint' => ['ppt/presentation.xml', 'pptx'],
        ];
    }

    public function test_detects_jpeg_and_pdf_by_signature(): void
    {
        $sniffer = new FileSniffer;

        $this->assertSame('jpg', $sniffer->detect($this->fileWith("\xFF\xD8\xFF\xE0rest")));
        $this->assertSame('pdf', $sniffer->detect($this->fileWith("%PDF-1.7\n...")));
        $this->assertNull($sniffer->detect($this->fileWith('teks biasa')));
    }

    #[DataProvider('officeOpenXmlParts')]
    public function test_detects_office_open_xml_by_its_main_part(string $part, string $expected): void
    {
        $this->assertSame($expected, (new FileSniffer)->detect($this->zipWith([$part => '<x/>'])));
    }

    public function test_rejects_macro_enabled_documents(): void
    {
        $path = $this->zipWith(['word/document.xml' => '<x/>', 'word/vbaProject.bin' => 'macro']);

        $this->assertNull((new FileSniffer)->detect($path));
    }

    public function test_rejects_ordinary_zip_archives(): void
    {
        $this->assertNull((new FileSniffer)->detect($this->zipWith(['catatan.txt' => 'halo'])));
    }

    public function test_detects_legacy_office_formats_by_stream_name(): void
    {
        $header = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 200);
        $sniffer = new FileSniffer;

        $this->assertSame('doc', $sniffer->detect($this->fileWith($header.mb_convert_encoding('WordDocument', 'UTF-16LE'))));
        $this->assertSame('xls', $sniffer->detect($this->fileWith($header.mb_convert_encoding('Workbook', 'UTF-16LE'))));
        $this->assertSame('ppt', $sniffer->detect($this->fileWith($header.mb_convert_encoding('PowerPoint Document', 'UTF-16LE'))));
    }
}
