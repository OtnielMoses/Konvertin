<?php

namespace Tests\Feature;

use App\Conversion\Converters\GotenbergConverter;
use App\Http\Controllers\ToolController;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\ManagesConversionFixtures;
use Tests\TestCase;
use ZipArchive;

class GotenbergConverterTest extends TestCase
{
    use ManagesConversionFixtures;

    private const BASE_URL = 'https://konversi.contoh.ac.id';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTemporaryWorkspaceRoot();

        config([
            'konvertin.engines.gotenberg.url' => self::BASE_URL,
            'konvertin.engines.gotenberg.username' => 'konvertin',
            'konvertin.engines.gotenberg.password' => 'rahasia',
            'konvertin.converters.word-to-pdf' => GotenbergConverter::class,
            'konvertin.converters.pdf-to-pdfa' => GotenbergConverter::class,
        ]);

        Http::preventStrayRequests();
    }

    private function docxUpload(string $name = 'Makalah.docx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<w:document/>');
        $zip->close();

        $upload = UploadedFile::fake()->createWithContent($name, (string) file_get_contents($path));
        unlink($path);

        return $upload;
    }

    public function test_word_document_is_sent_to_libreoffice_route_with_basic_auth(): void
    {
        Http::fake([self::BASE_URL.'/forms/libreoffice/convert' => Http::response($this->pdfBytes('Hasil Word'))]);

        $response = $this->postJson(route('tools.convert', 'word-to-pdf'), ['files' => [$this->docxUpload()]])
            ->assertOk()
            ->assertJsonPath('filename', 'Makalah.pdf');

        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE_URL.'/forms/libreoffice/convert'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('konvertin:rahasia'))
            && $request->isMultipart());

        $owner = session(ToolController::OWNER_SESSION_KEY);
        $this->withSession([ToolController::OWNER_SESSION_KEY => $owner])
            ->get($response->json('download_url'))
            ->assertOk()
            ->assertDownload('Makalah.pdf');

        $this->assertSame([], $this->workspaceDirectories());
    }

    public function test_pdfa_uses_the_remediate_route_with_pdfa_2b_profile(): void
    {
        Http::fake([self::BASE_URL.'/forms/pdf/remediate' => Http::response($this->pdfBytes('Arsip'), 200, [
            GotenbergConverter::RESIDUAL_HEADER => '0',
        ])]);

        $this->postJson(route('tools.convert', 'pdf-to-pdfa'), ['files' => [$this->pdfUpload('Skripsi.pdf')]])
            ->assertOk()
            ->assertJsonPath('filename', 'Skripsi-pdfa.pdf');

        Http::assertSent(function (Request $request): bool {
            $profile = collect($request->data())->firstWhere('name', 'profile');

            return $request->url() === self::BASE_URL.'/forms/pdf/remediate'
                && ($profile['contents'] ?? null) === GotenbergConverter::PDFA_PROFILE;
        });
    }

    public function test_pdfa_with_remaining_violations_is_refused_and_removed(): void
    {
        Http::fake([self::BASE_URL.'/forms/pdf/remediate' => Http::response($this->pdfBytes(), 200, [
            GotenbergConverter::RESIDUAL_HEADER => '3',
        ])]);

        $this->postJson(route('tools.convert', 'pdf-to-pdfa'), ['files' => [$this->pdfUpload()]])
            ->assertUnprocessable()
            ->assertJsonPath('failure', 'pdfa_not_compliant');

        $this->assertSame([], $this->workspaceDirectories());
    }

    public function test_password_protected_document_is_reported_as_such(): void
    {
        Http::fake([self::BASE_URL.'/*' => Http::response("The document 'input-01.docx' is password-protected.", 400)]);

        $this->postJson(route('tools.convert', 'word-to-pdf'), ['files' => [$this->docxUpload()]])
            ->assertUnprocessable()
            ->assertJsonPath('failure', 'encrypted');

        $this->assertSame([], $this->workspaceDirectories());
    }

    public function test_unreachable_service_reports_a_retry_message(): void
    {
        Http::fake([self::BASE_URL.'/*' => Http::response('', 503)]);

        $this->postJson(route('tools.convert', 'word-to-pdf'), ['files' => [$this->docxUpload()]])
            ->assertUnprocessable()
            ->assertJsonPath('failure', 'service_unreachable')
            ->assertJsonPath('message', __('konvertin.failure.service_unreachable'));

        $this->assertSame([], $this->workspaceDirectories());
    }

    public function test_service_is_unavailable_without_url_or_over_plain_http(): void
    {
        config(['konvertin.engines.gotenberg.url' => null]);
        $this->get(route('tools.show', 'word-to-pdf'))->assertSee(__('konvertin.failure.engine_unavailable'));

        config(['konvertin.engines.gotenberg.url' => 'http://konversi.contoh.ac.id']);
        $this->get(route('tools.show', 'word-to-pdf'))->assertSee(__('konvertin.failure.engine_unavailable'));

        $this->postJson(route('tools.convert', 'word-to-pdf'), ['files' => [$this->docxUpload()]])
            ->assertUnprocessable()
            ->assertJsonPath('failure', 'engine_unavailable');

        Http::assertNothingSent();
    }

    public function test_tool_page_tells_users_where_the_file_is_processed(): void
    {
        $this->get(route('tools.show', 'excel-to-pdf'))
            ->assertOk()
            ->assertSee('layanan konversi milik Konvertin')
            ->assertDontSee(__('konvertin.failure.engine_unavailable'));
    }
}
