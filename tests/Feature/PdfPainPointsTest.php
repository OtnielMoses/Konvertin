<?php

namespace Tests\Feature;

use App\Conversion\EngineLocator;
use App\Conversion\WorkspaceManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\ManagesConversionFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * Penyempurnaan dari audit masalah pengguna PDF, diuji dengan mesin Python sungguhan:
 * pemeriksaan hasil konversi, verifikasi redaksi, Scan to PDF, kompresi banyak berkas,
 * PNG di JPG to PDF, dan meneruskan hasil ke alat berikutnya.
 */
class PdfPainPointsTest extends TestCase
{
    use ManagesConversionFixtures;

    /**
     * Satu halaman: judul, paragraf, tabel 4x3 bergaris, gambar, dan nomor identitas.
     */
    private const REPORT_PDF = <<<'PY'
import sys, pymupdf
doc = pymupdf.open()
page = doc.new_page()
page.insert_text((72, 80), "Laporan Praktikum Fisika Dasar", fontsize=18)
for number in range(8):
    page.insert_text((72, 110 + number * 16), "Paragraf %d berisi penjelasan percobaan di laboratorium." % (number + 1))
for row in range(4):
    for column in range(3):
        cell = pymupdf.Rect(72 + column * 120, 260 + row * 22, 72 + (column + 1) * 120, 260 + (row + 1) * 22)
        page.draw_rect(cell, color=(0, 0, 0), width=0.8)
        page.insert_text((cell.x0 + 4, cell.y1 - 7), "R%dC%d" % (row, column) if row else ["Waktu", "Jarak", "Kecepatan"][column])
pixmap = pymupdf.Pixmap(pymupdf.csRGB, pymupdf.IRect(0, 0, 120, 80), 0)
pixmap.set_rect(pixmap.irect, (30, 90, 200))
page.insert_image(pymupdf.Rect(72, 380, 272, 500), pixmap=pixmap)
page.insert_text((72, 540), "Nomor KTP 3273011234567890 milik mahasiswa.")
sys.stdout.buffer.write(doc.tobytes())
PY;

    /**
     * Foto kertas miring di atas meja gelap, dengan bayangan dari kiri.
     */
    private const PAPER_PHOTO = <<<'PY'
import sys, cv2, numpy
height, width = 1600, 1200
photo = numpy.full((height, width, 3), (60, 70, 80), numpy.uint8)
paper = numpy.full((1100, 800, 3), 245, numpy.uint8)
for line in range(18):
    cv2.putText(paper, "Baris teks laporan nomor %d" % (line + 1), (50, 80 + line * 55), cv2.FONT_HERSHEY_SIMPLEX, 1.0, (30, 30, 30), 2)
source = numpy.float32([[0, 0], [800, 0], [800, 1100], [0, 1100]])
target = numpy.float32([[230, 180], [1000, 260], [950, 1420], [160, 1330]])
matrix = cv2.getPerspectiveTransform(source, target)
mask = cv2.warpPerspective(numpy.full((1100, 800), 255, numpy.uint8), matrix, (width, height))
photo[mask > 0] = cv2.warpPerspective(paper, matrix, (width, height))[mask > 0]
photo = (photo * numpy.tile(numpy.linspace(0.55, 1.0, width), (height, 1))[..., None]).astype(numpy.uint8)
sys.stdout.buffer.write(cv2.imencode(".jpg", photo)[1].tobytes())
PY;

    protected function setUp(): void
    {
        parent::setUp();

        if (! app(EngineLocator::class)->pythonReady()) {
            $this->markTestSkipped('Pustaka Python untuk pengolahan PDF belum terpasang.');
        }

        $this->useTemporaryWorkspaceRoot();
    }

    private function reportPdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('praktikum.pdf', $this->pythonFixture(self::REPORT_PDF));
    }

    private function resultFile(TestResponse $response): string
    {
        return app(WorkspaceManager::class)->find(basename($response->json('download_url')))->result()['file'];
    }

    /**
     * @return array<string, array{level: string, title: string}>
     */
    private function qualityByGroup(TestResponse $response): array
    {
        return collect($response->json('report.quality.checks'))->keyBy('group')->all();
    }

    public function test_pdf_to_word_reports_what_was_measurably_carried_over(): void
    {
        $response = $this->postJson(route('tools.convert', 'pdf-to-word'), ['files' => [$this->reportPdf()]])->assertOk();
        $checks = $this->qualityByGroup($response);

        $this->assertSame('ok', $checks['Teks']['level']);
        $this->assertSame('1 dari 1 gambar terbawa', $checks['Gambar']['title']);
        $this->assertSame('1 tabel terdeteksi di PDF, 1 menjadi tabel Word', $checks['Tabel']['title']);
        $this->assertSame(0, $response->json('report.quality.warnings'));
        $this->assertArrayNotHasKey('score', $response->json('report.quality'));
    }

    public function test_pdf_to_markdown_says_images_are_not_included(): void
    {
        $response = $this->postJson(route('tools.convert', 'pdf-to-markdown'), ['files' => [$this->reportPdf()]])->assertOk();
        $checks = $this->qualityByGroup($response);

        $this->assertSame('ok', $checks['Teks']['level']);
        $this->assertSame('1 gambar tidak disertakan', $checks['Gambar']['title']);
    }

    public function test_a_scanned_pdf_warns_that_its_text_cannot_be_converted(): void
    {
        $scan = UploadedFile::fake()->createWithContent('scan.pdf', $this->pythonFixture(<<<'PY'
import sys, pymupdf
doc = pymupdf.open()
page = doc.new_page()
pixmap = pymupdf.Pixmap(pymupdf.csRGB, pymupdf.IRect(0, 0, 300, 400), 0)
pixmap.set_rect(pixmap.irect, (240, 240, 240))
page.insert_image(page.rect, pixmap=pixmap)
sys.stdout.buffer.write(doc.tobytes())
PY));

        $response = $this->postJson(route('tools.convert', 'pdf-to-word'), ['files' => [$scan]])->assertOk();
        $warnings = collect($response->json('report.quality.checks'))->where('level', 'warn')->pluck('title')->all();

        $this->assertContains('PDF tidak memiliki lapisan teks', $warnings);
        $this->assertContains('1 halaman berupa gambar tanpa teks', $warnings);
    }

    public function test_redaction_is_verified_and_removes_characters_only_partly_covered_by_an_area(): void
    {
        // The area cuts through the middle of the title line.
        $areas = json_encode([['page' => 0, 'rect' => [0.2, 0.08, 0.45, 0.095]]]);

        $response = $this->postJson(route('tools.convert', 'redact-pdf'), [
            'files' => [$this->reportPdf()],
            'options' => ['areas' => $areas, 'terms' => '3273011234567890'],
        ])->assertOk();

        $this->assertSame(['terms' => 1, 'areas' => 1, 'text_remaining' => 0, 'metadata_cleared' => true], $response->json('report.verified'));

        $text = $this->pythonFixture(<<<'PY'
import sys, pymupdf
page = pymupdf.open(sys.argv[1])[0]
area = pymupdf.Rect(0.2 * page.rect.width, 0.08 * page.rect.height, 0.45 * page.rect.width, 0.095 * page.rect.height)
sys.stdout.write(page.get_text("text", clip=area) + "|" + page.get_text("text"))
PY, [$this->resultFile($response)]);
        [$inArea, $whole] = explode('|', $text, 2);

        $this->assertSame('', trim($inArea));
        $this->assertStringNotContainsString('3273011234567890', $whole);
        $this->assertStringContainsString('Paragraf 1', $whole);
    }

    public function test_scan_finds_the_paper_edges_and_can_clean_up_the_page(): void
    {
        $untouched = json_encode([['rotate' => 0, 'corners' => [[0.03, 0.03], [0.97, 0.03], [0.97, 0.97], [0.03, 0.97]]]]);

        $response = $this->postJson(route('tools.convert', 'scan-to-pdf'), [
            'files' => [UploadedFile::fake()->createWithContent('foto.jpg', $this->pythonFixture(self::PAPER_PHOTO))],
            'options' => ['transforms' => $untouched, 'auto_edges' => '1', 'enhance' => 'bw'],
        ])->assertOk();

        $this->assertSame(1, $response->json('report.edges_detected'));
        $this->assertSame('bw', $response->json('report.enhance'));

        // The image is cropped to the paper (about 790 x 1160 px), not the 1200 x 1600 photo with the desk.
        [$width, $height] = array_map('intval', explode(' ', trim($this->pythonFixture(
            'import sys, pymupdf; r = pymupdf.open(sys.argv[1])[0].get_images(full=True)[0]; print(r[2], r[3])',
            [$this->resultFile($response)],
        ))));
        $this->assertEqualsWithDelta(790, $width, 40);
        $this->assertEqualsWithDelta(1160, $height, 40);
    }

    public function test_scan_rejects_an_unknown_enhancement(): void
    {
        $this->postJson(route('tools.convert', 'scan-to-pdf'), [
            'files' => [$this->photoUpload('foto.jpg', 'jpg', 1, 300, 200)],
            'options' => ['enhance' => 'sepia'],
        ])->assertUnprocessable()->assertJsonValidationErrors('options.enhance');
    }

    public function test_smart_compression_accepts_several_pdfs_and_reports_each_one(): void
    {
        $response = $this->postJson(route('tools.convert', 'compress-pdf'), [
            'files' => [$this->photoUpload('bab 1.pdf', 'pdf', 2), $this->photoUpload('bab 2.pdf', 'pdf', 2)],
            'options' => ['target_value' => '900', 'target_unit' => 'KB', 'strategy' => 'size'],
        ])->assertOk();

        $this->assertSame('bab 1-hasil.zip', $response->json('filename'));
        $this->assertSame(2, $response->json('report.files'));
        $this->assertSame(['bab 1-kompres.pdf', 'bab 2-kompres.pdf'], array_column($response->json('report.items'), 'name'));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->resultFile($response)) === true);

        foreach ($response->json('report.items') as $item) {
            $this->assertTrue($item['report']['target_met']);
            $this->assertSame(strlen((string) $zip->getFromName($item['name'])), $item['report']['after']);
        }

        $zip->close();
    }

    public function test_jpg_to_pdf_also_accepts_png_images(): void
    {
        $response = $this->postJson(route('tools.convert', 'jpg-to-pdf'), [
            'files' => [$this->photoUpload('layar.png', 'png', 1, 400, 300), $this->photoUpload('foto.jpg', 'jpg', 1, 400, 300)],
        ])->assertOk();

        $this->assertSame(2, $response->json('report.pages'));
    }

    public function test_the_result_panel_offers_only_available_pdf_tools_to_continue_with(): void
    {
        $page = $this->get(route('tools.show', 'merge-pdf'))->assertOk();

        $page->assertSee('Lanjutkan dengan alat lain')
            ->assertSee('data-slug="compress-pdf"', false)
            ->assertSee('data-slug="preflight-pdf"', false)
            ->assertDontSee('data-slug="jpg-to-pdf"', false)
            ->assertDontSee('data-slug="html-to-pdf"', false)
            ->assertSee('Diproses di server Konvertin, tidak dikirim ke layanan lain.')
            ->assertSee('data-sort-names', false);
    }
}
