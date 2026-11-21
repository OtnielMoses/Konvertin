<?php

namespace Tests\Feature;

use App\Conversion\EngineLocator;
use App\Conversion\WorkspaceManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\ManagesConversionFixtures;
use Tests\TestCase;

/**
 * Preflight Check dengan mesin Python sungguhan: setiap temuan berasal dari isi PDF,
 * pembersihan benar-benar menghapus halaman, dan tanda tangan digital hanya dibaca,
 * tidak pernah dinyatakan sah.
 */
class PreflightCheckTest extends TestCase
{
    use ManagesConversionFixtures;

    /**
     * Tujuh halaman: 1-3 teks, 4 kosong, 5 sama dengan 2, 6 gambar mendatar tanpa teks,
     * 7 berukuran Letter dengan satu field formulir tanpa label.
     */
    private const SAMPLE = <<<'PY'
import sys, pymupdf
doc = pymupdf.open()
for number in range(3):
    page = doc.new_page(width=595, height=842)
    page.insert_text((72, 100), "Bab %d. Isi laporan praktikum yang cukup panjang untuk diperiksa." % (number + 1))
doc.new_page(width=595, height=842)
page = doc.new_page(width=595, height=842)
page.insert_text((72, 100), "Bab 2. Isi laporan praktikum yang cukup panjang untuk diperiksa.")
page = doc.new_page(width=842, height=595)
pixmap = pymupdf.Pixmap(pymupdf.csRGB, pymupdf.IRect(0, 0, 200, 100), 0)
pixmap.set_rect(pixmap.irect, (200, 30, 30))
page.insert_image(pymupdf.Rect(50, 50, 450, 250), pixmap=pixmap)
page = doc.new_page(width=612, height=792)
page.insert_text((72, 100), "Halaman berukuran Letter.")
widget = pymupdf.Widget()
widget.field_type = pymupdf.PDF_WIDGET_TYPE_TEXT
widget.field_name = "nama"
widget.rect = pymupdf.Rect(72, 200, 300, 220)
page.add_widget(widget)
sys.stdout.buffer.write(doc.tobytes())
PY;

    /**
     * PDF bertanda tangan digital (PKCS#7 detached, sertifikat uji self-signed).
     * Argumen "modified" menambahkan perubahan setelah tanda tangan dibuat.
     */
    private const SIGNED = <<<'PY'
import datetime, sys, pymupdf
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.hazmat.primitives.serialization import pkcs7
from cryptography.x509.oid import NameOID
key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
start = datetime.datetime(2026, 1, 1, tzinfo=datetime.timezone.utc)
cert = (x509.CertificateBuilder()
    .subject_name(x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "Penguji Konvertin")]))
    .issuer_name(x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "CA Uji Lokal")]))
    .public_key(key.public_key()).serial_number(1).not_valid_before(start)
    .not_valid_after(start + datetime.timedelta(days=365)).sign(key, hashes.SHA256()))
doc = pymupdf.open()
page = doc.new_page()
page.insert_text((72, 100), "Dokumen bertanda tangan digital.")
widget = pymupdf.Widget()
widget.field_type = pymupdf.PDF_WIDGET_TYPE_SIGNATURE
widget.field_name = "TTD1"
widget.rect = pymupdf.Rect(72, 200, 272, 260)
page.add_widget(widget)
signature_xref = doc.get_new_xref()
doc.update_object(signature_xref, "<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached "
    "/ByteRange [0 1111111111 2222222222 3333333333] /Contents <%s> /M (D:20261121120000+07'00') >>" % ("0" * 16000))
doc.xref_set_key(next(page.widgets()).xref, "V", "%d 0 R" % signature_xref)
raw = doc.tobytes(garbage=0, deflate=False, no_new_id=True)
begin = raw.index(b"/Contents<") + len(b"/Contents")
end = raw.index(b">", begin) + 1
raw = raw.replace(b"[0 1111111111 2222222222 3333333333]", ("[0 %010d %010d %010d]" % (begin, end, len(raw) - end)).encode(), 1)
signed = (pkcs7.PKCS7SignatureBuilder().set_data(raw[:begin] + raw[end:]).add_signer(cert, key, hashes.SHA256())
    .sign(serialization.Encoding.DER, [pkcs7.PKCS7Options.DetachedSignature]))
raw = raw[:begin + 1] + signed.hex().ljust(16000, "0").encode() + raw[end - 1:]
if sys.argv[1] == "modified":
    raw += b"\n% perubahan setelah tanda tangan\n"
sys.stdout.buffer.write(raw)
PY;

    protected function setUp(): void
    {
        parent::setUp();

        if (! app(EngineLocator::class)->pythonReady()) {
            $this->markTestSkipped('Pustaka Python untuk pengolahan PDF belum terpasang.');
        }

        $this->useTemporaryWorkspaceRoot();
    }

    private function sample(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('laporan akhir.pdf', $this->pythonFixture(self::SAMPLE));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function preflight(UploadedFile $file, array $options = []): TestResponse
    {
        return $this->postJson(route('tools.convert', 'preflight-pdf'), ['files' => [$file], 'options' => $options]);
    }

    private function resultFile(TestResponse $response): string
    {
        return app(WorkspaceManager::class)->find(basename($response->json('download_url')))->result()['file'];
    }

    /**
     * @return array<int, string>
     */
    private function titles(TestResponse $response, string $level): array
    {
        return collect($response->json('report.checks'))->where('level', $level)->pluck('title')->all();
    }

    private function pageCount(string $file): int
    {
        return (int) trim($this->pythonFixture('import sys, pymupdf; print(pymupdf.open(sys.argv[1]).page_count)', [$file]));
    }

    public function test_without_cleaning_the_result_is_a_report_pdf_listing_what_was_found(): void
    {
        $response = $this->preflight($this->sample())->assertOk();
        $report = $response->json('report');

        $this->assertSame('laporan akhir-laporan-preflight.pdf', $response->json('filename'));
        $this->assertSame(7, $report['pages']);
        $this->assertSame([4], $report['blank_pages']);
        $this->assertSame([['page' => 5, 'same_as' => 2]], $report['duplicate_pages']);
        $this->assertSame([6], $report['scanned_pages']);
        $this->assertSame([], $report['removed']);
        $this->assertFalse($report['cleaned']);

        $warnings = $this->titles($response, 'warn');
        $this->assertContains('Ukuran kertas campuran', $warnings);
        $this->assertContains('Halaman kosong: 4', $warnings);
        $this->assertContains('PDF tidak bertag', $warnings);
        $this->assertContains('1 field formulir tanpa label', $warnings);
        $this->assertSame(count($warnings), $report['warnings']);

        // Ligatur (fl, fi) diuraikan seperti saat teks disalin dari pembaca PDF.
        $text = $this->pythonFixture(
            'import sys, pymupdf; sys.stdout.buffer.write(pymupdf.open(sys.argv[1])[0].get_text(flags=pymupdf.TEXT_PRESERVE_WHITESPACE).encode("utf-8"))',
            [$this->resultFile($response)],
        );
        $this->assertStringContainsString('Laporan Preflight Check', $text);
        $this->assertStringContainsString('Halaman kosong: 4', $text);
        $this->assertStringContainsString('Bukan pernyataan kepatuhan standar aksesibilitas', $text);
    }

    public function test_cleaning_removes_blank_and_duplicate_pages_from_the_pdf(): void
    {
        $response = $this->preflight($this->sample(), ['remove_blank' => '1', 'remove_duplicates' => '1'])->assertOk();

        $this->assertSame('laporan akhir-bersih.pdf', $response->json('filename'));
        $this->assertSame([4, 5], $response->json('report.removed'));
        $this->assertSame(5, $response->json('report.pages_after'));
        $this->assertSame(5, $this->pageCount($this->resultFile($response)));
    }

    public function test_cleaning_never_produces_a_pdf_without_pages(): void
    {
        $blank = UploadedFile::fake()->createWithContent('kosong.pdf', $this->pythonFixture(
            'import sys, pymupdf; d = pymupdf.open(); d.new_page(); d.new_page(); sys.stdout.buffer.write(d.tobytes())',
        ));

        $response = $this->preflight($blank, ['remove_blank' => '1'])->assertOk();

        $this->assertSame([1, 2], $response->json('report.blank_pages'));
        $this->assertSame([], $response->json('report.removed'));
        $this->assertSame(2, $this->pageCount($this->resultFile($response)));
    }

    public function test_an_upload_limit_is_compared_with_the_real_file_size(): void
    {
        $response = $this->preflight($this->sample(), ['target_value' => '1', 'target_unit' => 'KB'])->assertOk();

        $size = collect($response->json('report.checks'))->firstWhere('group', 'Ukuran');
        $this->assertSame('warn', $size['level']);
        $this->assertStringContainsString('melebihi batas 1 KB', $size['title']);
    }

    public function test_a_digital_signature_is_read_but_never_declared_valid(): void
    {
        $intact = $this->preflight(UploadedFile::fake()->createWithContent('ttd.pdf', $this->pythonFixture(self::SIGNED, ['intact'])))->assertOk();
        $signature = $intact->json('report.signatures.0');

        $this->assertTrue($signature['signed']);
        $this->assertTrue($signature['covers_whole_file']);
        $this->assertSame('Penguji Konvertin', $signature['certificate']['subject']);
        $this->assertSame('CA Uji Lokal', $signature['certificate']['issuer']);
        $this->assertContains('Keabsahan sertifikat tidak diverifikasi', $this->titles($intact, 'info'));
        $this->assertEmpty(array_filter($this->titles($intact, 'ok'), fn (string $title): bool => str_contains(strtolower($title), 'sah')));

        $modified = $this->preflight(UploadedFile::fake()->createWithContent('ttd-diubah.pdf', $this->pythonFixture(self::SIGNED, ['modified'])))->assertOk();

        $this->assertFalse($modified->json('report.signatures.0.covers_whole_file'));
        $this->assertContains('Ada perubahan setelah tanda tangan Penguji Konvertin', $this->titles($modified, 'warn'));
    }

    public function test_a_pdf_without_signature_fields_says_an_image_is_not_a_digital_signature(): void
    {
        $response = $this->preflight($this->sample())->assertOk();

        $this->assertSame([], $response->json('report.signatures'));
        $this->assertContains('Tidak ada tanda tangan digital', $this->titles($response, 'info'));
    }

    public function test_preflight_can_clean_pages_as_a_workflow_step(): void
    {
        $steps = json_encode([
            ['tool' => 'preflight-pdf', 'options' => ['remove_blank' => '1', 'remove_duplicates' => '1']],
            ['tool' => 'page-numbers', 'options' => ['position' => 'bottom', 'align' => 'center', 'size' => 11, 'font' => 'helvetica', 'format' => 'n', 'start' => 1, 'first_page' => 1]],
        ]);

        $response = $this->postJson(route('tools.convert', 'create-workflow'), [
            'files' => [$this->sample()],
            'options' => ['steps' => $steps],
        ])->assertOk();

        $this->assertSame(['preflight', 'pagenumbers'], array_column($response->json('report.steps'), 'op'));
        $this->assertSame([4, 5], $response->json('report.steps.0.report.removed'));
        $this->assertSame(5, $this->pageCount($this->resultFile($response)));
    }

    public function test_invalid_options_are_rejected_before_processing(): void
    {
        $this->preflight($this->sample(), ['target_value' => '2', 'target_unit' => 'GB'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('options.target_unit');

        $this->assertSame([], $this->workspaceDirectories());
    }
}
