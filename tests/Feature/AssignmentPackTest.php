<?php

namespace Tests\Feature;

use App\Conversion\EngineLocator;
use App\Conversion\WorkspaceManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Process\Process;
use Tests\Feature\Concerns\ManagesConversionFixtures;
use Tests\TestCase;

/**
 * Assignment Pack Builder dengan mesin Python sungguhan.
 */
class AssignmentPackTest extends TestCase
{
    use ManagesConversionFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        if (! app(EngineLocator::class)->pythonReady()) {
            $this->markTestSkipped('Pustaka Python untuk pengolahan PDF belum terpasang.');
        }

        $this->useTemporaryWorkspaceRoot();
        config(['konvertin.engines.microsoft_office' => false, 'konvertin.engines.gotenberg.url' => null, 'konvertin.engines.libreoffice' => null]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function packOptions(array $sections, array $overrides = []): array
    {
        return [
            'sections' => json_encode($sections),
            'grouping' => '1',
            'title' => 'Laporan Praktikum 3',
            'paper' => 'a4',
            'orientation' => 'auto',
            'margin_mm' => '0',
            'numbering' => json_encode([
                'cover' => ['style' => 'none', 'start' => 1, 'continue' => false],
                'preliminary' => ['style' => 'roman_lower', 'start' => 1, 'continue' => false],
                'main' => ['style' => 'arabic', 'start' => 1, 'continue' => false],
                'appendix' => ['style' => 'arabic', 'start' => 1, 'continue' => true],
            ]),
            'number_position' => 'bottom',
            'number_align' => 'center',
            'number_size' => '11',
            'number_font' => 'times',
            'first_page_rule' => 'same',
            ...$overrides,
        ];
    }

    /**
     * Label halaman, ukuran halaman, bookmark, dan teks tiap halaman dari PDF hasil.
     *
     * @return array{labels: list<string>, sizes: list<array{0: int, 1: int}>, toc: list<array{0: int, 1: string, 2: int}>, texts: list<string>}
     */
    private function inspect(TestResponse $response): array
    {
        $file = app(WorkspaceManager::class)->find(basename($response->json('download_url')))->result()['file'];
        $locator = app(EngineLocator::class);
        $script = 'import json,sys,pymupdf;d=pymupdf.open(sys.argv[1]);print(json.dumps({"labels":[p.get_label() for p in d],"sizes":[[round(p.rect.width),round(p.rect.height)] for p in d],"toc":d.get_toc(),"texts":[p.get_text() for p in d]}))';
        $process = new Process([$locator->python(), '-c', $script, $file], null, $locator->environment());
        $process->mustRun();

        return json_decode($process->getOutput(), true);
    }

    public function test_sections_are_ordered_and_numbered_with_academic_rules(): void
    {
        // Sent out of order on purpose: the server always orders by section.
        $response = $this->postJson(route('tools.convert', 'assignment-pack'), [
            'files' => [
                $this->pdfUpload('bab-1.pdf', 'Isi BAB I'),
                $this->pdfUpload('sampul.pdf', 'Sampul tugas'),
                $this->pdfUpload('kata-pengantar.pdf', 'Kata pengantar'),
                $this->photoUpload('screenshot.png', 'png', 1, 800, 400),
            ],
            'options' => $this->packOptions(['main', 'cover', 'preliminary', 'appendix']),
        ])->assertOk();

        $response->assertJsonPath('filename', 'Laporan Praktikum 3.pdf')
            ->assertJsonPath('report.pages', 4)
            ->assertJsonPath('report.documents.0.title', 'sampul')
            ->assertJsonPath('report.documents.1.title', 'kata-pengantar')
            ->assertJsonPath('report.documents.2.title', 'bab-1')
            ->assertJsonPath('report.documents.3.title', 'screenshot')
            ->assertJsonPath('report.sections.1.first_label', 'i')
            ->assertJsonPath('report.sections.2.first_label', '1')
            ->assertJsonPath('report.sections.3.first_label', '2');

        $pdf = $this->inspect($response);

        $this->assertSame(['Sampul', 'i', '1', '2'], $pdf['labels']);
        $this->assertStringContainsString('Sampul tugas', $pdf['texts'][0]);
        $this->assertStringNotContainsString("\n1\n", $pdf['texts'][0]);
        $this->assertStringContainsString('i', $pdf['texts'][1]);
        $this->assertStringContainsString('Isi BAB I', $pdf['texts'][2]);
        // Every page is placed on A4; the wide screenshot gets a landscape page.
        $this->assertSame([[595, 842], [595, 842], [595, 842], [842, 595]], $pdf['sizes']);
        $this->assertSame([1, 'Sampul', 1], $pdf['toc'][0]);
        $this->assertSame([2, 'bab-1', 3], $pdf['toc'][5]);
    }

    public function test_hidden_numbers_still_count_and_original_paper_keeps_page_sizes(): void
    {
        $response = $this->postJson(route('tools.convert', 'assignment-pack'), [
            'files' => [$this->pdfUpload('isi-1.pdf', 'Bagian satu'), $this->pdfUpload('isi-2.pdf', 'Bagian dua')],
            'options' => $this->packOptions(['main', 'main'], ['paper' => 'original', 'first_page_rule' => 'hide', 'grouping' => '0']),
        ])->assertOk();

        $pdf = $this->inspect($response);

        $this->assertSame([[612, 792], [612, 792]], $pdf['sizes']);
        $this->assertSame(['1', '2'], $pdf['labels']);
        $response->assertJsonPath('report.sections.0.first_label', null);
    }

    public function test_the_optimize_step_reuses_smart_compression(): void
    {
        $response = $this->postJson(route('tools.convert', 'assignment-pack'), [
            'files' => [$this->photoUpload('lampiran.pdf', 'pdf', 3)],
            'options' => $this->packOptions(['appendix'], ['compress' => '1', 'target_value' => '900', 'target_unit' => 'KB', 'strategy' => 'size']),
        ])->assertOk();

        $response->assertJsonPath('report.compression.target_met', true)
            ->assertJsonPath('report.compression.strategy', 'size');
        $this->assertLessThanOrEqual(900 * 1024, $response->json('report.size'));
    }

    public function test_word_documents_fail_clearly_when_no_word_engine_is_available(): void
    {
        $docx = UploadedFile::fake()->createWithContent('laporan.docx', $this->minimalDocx());

        $this->postJson(route('tools.convert', 'assignment-pack'), [
            'files' => [$this->pdfUpload('sampul.pdf'), $docx],
            'options' => $this->packOptions(['cover', 'main']),
        ])
            ->assertUnprocessable()
            ->assertJsonPath('failure', 'word_unavailable');

        $this->assertSame([], $this->workspaceDirectories());
    }

    public function test_password_protected_pdfs_are_refused(): void
    {
        $protected = $this->postJson(route('tools.convert', 'protect-pdf'), [
            'files' => [$this->pdfUpload()],
            'options' => ['password' => 'kuliah123', 'password_confirmation' => 'kuliah123'],
        ])->assertOk();
        $file = app(WorkspaceManager::class)->find(basename($protected->json('download_url')))->result()['file'];

        $this->postJson(route('tools.convert', 'assignment-pack'), [
            'files' => [UploadedFile::fake()->createWithContent('terkunci.pdf', (string) file_get_contents($file))],
            'options' => $this->packOptions(['main']),
        ])
            ->assertUnprocessable()
            ->assertJsonPath('failure', 'encrypted');
    }

    /**
     * DOCX terkecil yang sah (ZIP berisi [Content_Types].xml dan word/document.xml).
     */
    private function minimalDocx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Isi</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    public function test_bookmarks_follow_final_pages_and_can_be_turned_off(): void
    {
        $files = fn () => [
            $this->pdfUpload('Sampul.pdf', 'Sampul'),
            $this->pdfUpload('BAB I Pendahuluan.pdf', 'Isi BAB I'),
            $this->pdfUpload('Lampiran.pdf', 'Lampiran'),
        ];

        $with = $this->postJson(route('tools.convert', 'assignment-pack'), [
            'files' => $files(),
            'options' => $this->packOptions(['cover', 'main', 'appendix'], ['grouping' => '0']),
        ])->assertOk();

        $this->assertSame([[1, 'Sampul', 1], [1, 'BAB I Pendahuluan', 2], [1, 'Lampiran', 3]], $this->inspect($with)['toc']);

        $without = $this->postJson(route('tools.convert', 'assignment-pack'), [
            'files' => $files(),
            'options' => $this->packOptions(['cover', 'main', 'appendix'], ['grouping' => '0', 'bookmarks' => '0']),
        ])->assertOk();

        $this->assertSame([], $this->inspect($without)['toc']);
    }
}
