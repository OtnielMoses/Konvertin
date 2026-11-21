<?php

namespace Tests\Feature;

use App\Conversion\EngineLocator;
use App\Conversion\WorkspaceManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\ManagesConversionFixtures;
use Tests\Feature\Fakes\FakeConverter;
use Tests\TestCase;
use ZipArchive;

/**
 * Word, PowerPoint, dan Excel ke PDF dengan banyak berkas sekaligus.
 */
class BatchConversionTest extends TestCase
{
    use ManagesConversionFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTemporaryWorkspaceRoot();
        FakeConverter::reset();
        config(['konvertin.converters.word-to-pdf' => FakeConverter::class]);
    }

    private function docx(string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function resultFile(TestResponse $response): string
    {
        return app(WorkspaceManager::class)->find(basename($response->json('download_url')))->result()['file'];
    }

    public function test_several_word_documents_become_one_pdf_with_a_bookmark_each(): void
    {
        if (! app(EngineLocator::class)->pythonReady()) {
            $this->markTestSkipped('Pustaka Python untuk pengolahan PDF belum terpasang.');
        }

        $response = $this->postJson(route('tools.convert', 'word-to-pdf'), [
            'files' => [$this->docx('Bab 1.docx'), $this->docx('Bab 2.docx'), $this->docx('Lampiran.docx')],
            'options' => ['merge' => 'one'],
        ])->assertOk()
            ->assertJsonPath('filename', 'Bab 1.pdf')
            ->assertJsonPath('report.files', 3)
            ->assertJsonPath('report.pages', 3);

        $this->assertCount(3, FakeConverter::$received);
        $pdf = (string) file_get_contents($this->resultFile($response));
        $this->assertStringContainsString('/Outlines', $pdf);
        $this->assertStringContainsString('Lampiran', $pdf);
    }

    public function test_separate_output_is_a_zip_with_one_pdf_per_document(): void
    {
        $response = $this->postJson(route('tools.convert', 'word-to-pdf'), [
            'files' => [$this->docx('laporan.docx'), $this->docx('laporan.docx'), $this->docx('notulen.docx')],
            'options' => ['merge' => 'separate'],
        ])->assertOk()
            ->assertJsonPath('filename', 'laporan-pdf.zip')
            ->assertJsonPath('report.files', 3)
            ->assertJsonPath('preview_url', null);

        $zip = new ZipArchive;
        $zip->open($this->resultFile($response));
        $names = array_map(fn (int $index): string => (string) $zip->getNameIndex($index), range(0, $zip->numFiles - 1));
        $zip->close();

        $this->assertSame(['laporan.pdf', 'laporan (2).pdf', 'notulen.pdf'], $names);
    }

    public function test_a_single_document_still_converts_directly(): void
    {
        $this->postJson(route('tools.convert', 'word-to-pdf'), ['files' => [$this->docx('makalah.docx')]])
            ->assertOk()
            ->assertJsonPath('filename', 'makalah.pdf');

        $this->assertCount(1, FakeConverter::$received);
    }

    public function test_office_tools_accept_up_to_the_file_limit(): void
    {
        config(['konvertin.max_files' => 2]);

        $this->postJson(route('tools.convert', 'word-to-pdf'), [
            'files' => [$this->docx('a.docx'), $this->docx('b.docx'), $this->docx('c.docx')],
        ])->assertUnprocessable()->assertJsonValidationErrors('files');
    }
}
