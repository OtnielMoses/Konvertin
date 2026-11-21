<?php

namespace Tests\Feature;

use App\Conversion\EngineLocator;
use App\Conversion\WorkspaceManager;
use App\Http\Controllers\ToolController;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\ManagesConversionFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * OCR lanjutan, OCR sebelum konversi, ekspor gambar, dan Bookmark PDF dengan mesin
 * Python sungguhan. Skor OCR yang diperiksa adalah skor dari mesin, bukan buatan.
 */
class AdvancedPdfTest extends TestCase
{
    use ManagesConversionFixtures;

    /**
     * Tiga halaman: 1 bertek asli, 2 dan 3 berupa gambar hasil pindai berisi teks.
     */
    private const SCANNED = <<<'PY'
import sys, pymupdf
source = pymupdf.open()
page = source.new_page()
page.insert_text((72, 90), "Laporan Praktikum Fisika Dasar", fontsize=20)
for line in range(8):
    page.insert_text((72, 140 + line * 26), "Baris %d berisi hasil pengamatan di laboratorium." % (line + 1), fontsize=14)
image = page.get_pixmap(dpi=200)
doc = pymupdf.open()
doc.new_page().insert_text((72, 100), "Halaman pertama sudah memiliki teks asli yang bisa dicari.")
for _ in range(2):
    scan = doc.new_page(width=page.rect.width, height=page.rect.height)
    scan.insert_image(scan.rect, pixmap=image)
sys.stdout.buffer.write(doc.tobytes())
PY;

    protected function setUp(): void
    {
        parent::setUp();

        if (! app(EngineLocator::class)->pythonReady()) {
            $this->markTestSkipped('Pustaka Python untuk pengolahan PDF belum terpasang.');
        }

        $this->useTemporaryWorkspaceRoot();
    }

    private function scanned(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('pindaian.pdf', $this->pythonFixture(self::SCANNED));
    }

    private function resultFile(TestResponse $response): string
    {
        return app(WorkspaceManager::class)->find(basename($response->json('download_url')))->result()['file'];
    }

    /**
     * @return list<string>
     */
    private function pageTexts(string $file): array
    {
        return json_decode($this->pythonFixture(
            'import json, sys, pymupdf; sys.stdout.write(json.dumps([p.get_text() for p in pymupdf.open(sys.argv[1])]))',
            [$file],
        ), true);
    }

    private function needsOcr(): void
    {
        if (! app(EngineLocator::class)->ocrReady()) {
            $this->markTestSkipped('Mesin OCR belum terpasang.');
        }
    }

    public function test_ocr_runs_only_on_selected_pages_without_text_and_reports_engine_scores(): void
    {
        $this->needsOcr();

        $response = $this->postJson(route('tools.convert', 'ocr-pdf'), [
            'files' => [$this->scanned()],
            'options' => ['scope' => 'pages', 'pages' => '1-2'],
        ])->assertOk();
        $report = $response->json('report');

        $this->assertSame([1], $report['skipped_pages']);
        $this->assertSame(1, $report['pages_ocr']);
        $this->assertSame(2, $report['pages'][0]['page']);
        $this->assertSame('RapidOCR (PP-OCR)', $report['engine']);
        $this->assertGreaterThan(0, $report['pages'][0]['line_count']);
        $this->assertCount($report['pages'][0]['line_count'], $report['pages'][0]['lines']);

        $scores = array_column($report['pages'][0]['lines'], 'score');
        $this->assertEqualsWithDelta(array_sum($scores) / count($scores), $report['pages'][0]['mean_score'], 0.002);
        $this->assertContains('Laporan Praktikum Fisika Dasar', array_column($report['pages'][0]['lines'], 'text'));

        $texts = $this->pageTexts($this->resultFile($response));
        $this->assertStringContainsString('Laboratorium', ucwords($texts[1]));
        $this->assertSame('', trim($texts[2]), 'Halaman 3 tidak dipilih, jadi tidak di-OCR.');
    }

    public function test_corrected_lines_replace_the_recognised_text_in_the_text_layer(): void
    {
        $this->needsOcr();

        $first = $this->postJson(route('tools.convert', 'ocr-pdf'), ['files' => [$this->scanned()], 'options' => ['scope' => 'pages', 'pages' => '2']])->assertOk();
        $index = array_search('Laporan Praktikum Fisika Dasar', array_column($first->json('report.pages.0.lines'), 'text'), true);
        $this->assertNotFalse($index);

        $corrected = $this->postJson(route('tools.convert', 'ocr-pdf'), [
            'files' => [$this->scanned()],
            'options' => ['scope' => 'pages', 'pages' => '2', 'corrections' => json_encode(['2' => [(string) $index => 'Laporan Praktikum Fisika Dasar (revisi)']])],
        ])->assertOk();

        $this->assertSame(1, $corrected->json('report.pages.0.corrected'));
        $this->assertStringContainsString('(revisi)', $this->pageTexts($this->resultFile($corrected))[1]);
    }

    public function test_ocr_on_a_pdf_that_already_has_text_says_it_is_not_needed(): void
    {
        $this->needsOcr();

        $this->postJson(route('tools.convert', 'ocr-pdf'), ['files' => [$this->pdfUpload('teks.pdf')]])
            ->assertStatus(422)
            ->assertJsonPath('failure', 'nothing_to_ocr');
    }

    public function test_pdf_to_word_can_run_ocr_first_so_scanned_text_becomes_editable(): void
    {
        $this->needsOcr();

        $response = $this->postJson(route('tools.convert', 'pdf-to-word'), [
            'files' => [$this->scanned()],
            'options' => ['ocr_first' => '1'],
        ])->assertOk();

        $this->assertSame(2, $response->json('report.ocr_pages'));
        $checks = collect($response->json('report.quality.checks'));
        $this->assertSame('ok', $checks->firstWhere('group', 'Teks')['level']);
        $this->assertSame('2 halaman scan diubah menjadi teks lewat OCR', $checks->firstWhere('group', 'OCR')['title']);

        $zip = new ZipArchive;
        $zip->open($this->resultFile($response));
        $this->assertStringContainsString('Praktikum', (string) $zip->getFromName('word/document.xml'));
        $zip->close();
    }

    public function test_pdf_to_markdown_uses_ocr_text_for_scanned_pages(): void
    {
        $this->needsOcr();

        $response = $this->postJson(route('tools.convert', 'pdf-to-markdown'), [
            'files' => [$this->scanned()],
            'options' => ['ocr_first' => '1'],
        ])->assertOk();

        $this->assertSame(2, $response->json('report.ocr_pages'));
        $this->assertStringContainsString('pengamatan', (string) file_get_contents($this->resultFile($response)));
    }

    public function test_image_export_honours_format_resolution_and_pages(): void
    {
        $response = $this->postJson(route('tools.convert', 'pdf-to-jpg'), [
            'files' => [$this->scanned()],
            'options' => ['format' => 'png', 'dpi' => '72', 'scope' => 'pages', 'pages' => '2-3'],
        ])->assertOk();
        $report = $response->json('report');

        $this->assertSame('pindaian-png.zip', $response->json('filename'));
        $this->assertSame(['pindaian-hal-002.png', 'pindaian-hal-003.png'], array_column($report['images'], 'name'));
        $this->assertSame(72, $report['dpi']);

        $zip = new ZipArchive;
        $zip->open($this->resultFile($response));
        foreach ($report['images'] as $image) {
            $bytes = (string) $zip->getFromName($image['name']);
            $this->assertSame($image['size'], strlen($bytes));
            $this->assertStringStartsWith("\x89PNG", $bytes);
            $this->assertSame([$image['width'], $image['height']], array_values(array_slice(getimagesizefromstring($bytes), 0, 2)));
        }
        $zip->close();

        $single = $this->postJson(route('tools.convert', 'pdf-to-jpg'), [
            'files' => [$this->scanned()],
            'options' => ['format' => 'jpg', 'dpi' => '300', 'quality' => '60', 'scope' => 'pages', 'pages' => '1'],
        ])->assertOk();

        $this->assertSame('pindaian.jpg', $single->json('filename'));
        $this->assertSame('image', $single->json('preview_type'));
        // 595 x 842 pt at 300 DPI, rounded up like the renderer: 2480 x 3509 pixels.
        $this->assertSame([2480, 3509], array_values(array_slice(getimagesize($this->resultFile($single)), 0, 2)));
    }

    public function test_single_images_from_the_zip_are_served_to_the_owner_only_and_nothing_is_deleted(): void
    {
        $response = $this->postJson(route('tools.convert', 'pdf-to-jpg'), [
            'files' => [$this->scanned()],
            'options' => ['format' => 'jpg', 'dpi' => '72'],
        ])->assertOk();
        $owner = session(ToolController::OWNER_SESSION_KEY);
        $member = $response->json('member_base').'/1';

        $this->withSession([ToolController::OWNER_SESSION_KEY => $owner])->get($member)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
        $this->withSession([ToolController::OWNER_SESSION_KEY => $owner])->get($member.'?unduh=1')
            ->assertHeader('Content-Disposition', 'attachment; filename="pindaian-hal-002.jpg"');
        $this->withSession([ToolController::OWNER_SESSION_KEY => str_repeat('x', 64)])->get($member)->assertNotFound();
        $this->withSession([ToolController::OWNER_SESSION_KEY => $owner])->get($response->json('member_base').'/9')->assertNotFound();

        $this->assertFileExists($this->resultFile($response));
    }

    public function test_an_export_that_would_be_too_large_is_refused_with_a_clear_reason(): void
    {
        $large = UploadedFile::fake()->createWithContent('panjang.pdf', $this->pythonFixture(
            'import sys, pymupdf; d = pymupdf.open(); [d.new_page() for _ in range(150)]; sys.stdout.buffer.write(d.tobytes())',
        ));

        $this->postJson(route('tools.convert', 'pdf-to-jpg'), ['files' => [$large], 'options' => ['dpi' => '600']])
            ->assertStatus(422)
            ->assertJsonPath('failure', 'export_too_large');
    }

    public function test_bookmarks_are_saved_with_hierarchy_and_unchanged_ones_keep_their_destination(): void
    {
        $pdf = UploadedFile::fake()->createWithContent('skripsi.pdf', $this->pythonFixture(<<<'PY'
import sys, pymupdf
doc = pymupdf.open()
for _ in range(4):
    doc.new_page()
doc.set_toc([[1, "Awal", 1, {"kind": pymupdf.LINK_GOTO, "page": 0, "to": pymupdf.Point(0, 300), "zoom": 0}], [1, "Bab 1", 2]])
sys.stdout.buffer.write(doc.tobytes())
PY));

        $outline = [
            ['level' => 1, 'title' => 'Awal', 'page' => 1, 'source' => 0],
            ['level' => 1, 'title' => 'BAB I Pendahuluan', 'page' => 2, 'source' => 1],
            ['level' => 2, 'title' => '1.1 Latar Belakang', 'page' => 3],
            ['level' => 3, 'title' => '1.1.1 Rumusan', 'page' => 4],
        ];
        $response = $this->postJson(route('tools.convert', 'bookmark-pdf'), [
            'files' => [$pdf],
            'options' => ['outline' => json_encode($outline)],
        ])->assertOk();

        $this->assertSame(4, $response->json('report.bookmarks'));
        $this->assertSame(3, $response->json('report.levels'));
        $this->assertFalse($response->json('report.unchanged'));

        $toc = json_decode($this->pythonFixture(
            'import json, sys, pymupdf; t = pymupdf.open(sys.argv[1]).get_toc(simple=False); print(json.dumps([[e[0], e[1], e[2], round(e[3]["to"].y)] for e in t]))',
            [$this->resultFile($response)],
        ), true);

        // "Awal" keeps its original position on the page (y = 300 from the top in PDF space).
        $this->assertSame([1, 'Awal', 1], array_slice($toc[0], 0, 3));
        $this->assertNotSame(round(792), $toc[0][3]);
        $this->assertSame([[1, 'BAB I Pendahuluan', 2], [2, '1.1 Latar Belakang', 3], [3, '1.1.1 Rumusan', 4]], array_map(fn (array $entry): array => array_slice($entry, 0, 3), array_slice($toc, 1)));
    }

    public function test_bookmark_hierarchy_and_pages_are_validated(): void
    {
        $this->postJson(route('tools.convert', 'bookmark-pdf'), [
            'files' => [$this->pdfUpload('a.pdf')],
            'options' => ['outline' => json_encode([['level' => 2, 'title' => 'Melompat', 'page' => 1]])],
        ])->assertUnprocessable()->assertJsonValidationErrors('options.outline');

        $this->postJson(route('tools.convert', 'bookmark-pdf'), [
            'files' => [$this->pdfUpload('a.pdf')],
            'options' => ['outline' => json_encode([['level' => 1, 'title' => 'Jauh', 'page' => 9]])],
        ])->assertStatus(422)->assertJsonPath('failure', 'invalid_pages');

        $this->assertSame([], $this->workspaceDirectories());
    }

    public function test_an_unchanged_outline_is_kept_as_it_was(): void
    {
        $pdf = UploadedFile::fake()->createWithContent('buku.pdf', $this->pythonFixture(
            'import sys, pymupdf; d = pymupdf.open(); [d.new_page() for _ in range(3)]; d.set_toc([[1, "A", 1], [2, "A.1", 2], [1, "B", 3]]); sys.stdout.buffer.write(d.tobytes())',
        ));

        $this->postJson(route('tools.convert', 'bookmark-pdf'), [
            'files' => [$pdf],
            'options' => ['outline' => json_encode([
                ['level' => 1, 'title' => 'A', 'page' => 1, 'source' => 0],
                ['level' => 2, 'title' => 'A.1', 'page' => 2, 'source' => 1],
                ['level' => 1, 'title' => 'B', 'page' => 3, 'source' => 2],
            ])],
        ])->assertOk()->assertJsonPath('report.unchanged', true);
    }
}
