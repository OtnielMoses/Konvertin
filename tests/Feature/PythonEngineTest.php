<?php

namespace Tests\Feature;

use App\Conversion\EngineLocator;
use App\Conversion\WorkspaceManager;
use App\Http\Controllers\ToolController;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\ManagesConversionFixtures;
use Tests\TestCase;

/**
 * Menjalankan mesin Python sungguhan. Dilewati bila pustakanya belum terpasang.
 */
class PythonEngineTest extends TestCase
{
    use ManagesConversionFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        if (! app(EngineLocator::class)->pythonReady()) {
            $this->markTestSkipped('Pustaka Python untuk konversi PDF belum terpasang.');
        }

        $this->useTemporaryWorkspaceRoot();
    }

    /**
     * Membaca isi berkas hasil sebelum diunduh, karena unduhan menghapusnya.
     */
    private function resultContents(TestResponse $response): string
    {
        $workspace = app(WorkspaceManager::class)->find(basename($response->json('download_url')));

        return (string) file_get_contents($workspace->result()['file']);
    }

    public function test_merge_combines_every_page_into_one_pdf(): void
    {
        $response = $this->postJson(route('tools.convert', 'merge-pdf'), [
            'files' => [$this->pdfUpload('a.pdf', 'Satu'), $this->pdfUpload('b.pdf', 'Dua')],
        ])->assertOk()->assertJsonPath('filename', 'gabungan.pdf');

        $contents = $this->resultContents($response);
        $this->assertStringStartsWith('%PDF-', $contents);
        $this->assertSame(2, preg_match_all('#/Type\s*/Page(?!s)#', $contents));

        $owner = session(ToolController::OWNER_SESSION_KEY);
        $this->withSession([ToolController::OWNER_SESSION_KEY => $owner])
            ->get($response->json('download_url'))
            ->assertOk()
            ->assertDownload('gabungan.pdf');

        $this->assertSame([], $this->workspaceDirectories());
    }

    public function test_pdf_without_tables_is_reported_instead_of_an_empty_sheet(): void
    {
        $this->postJson(route('tools.convert', 'pdf-to-excel'), ['files' => [$this->pdfUpload()]])
            ->assertUnprocessable()
            ->assertJsonPath('failure', 'no_tables');

        $this->assertSame([], $this->workspaceDirectories());
    }

    public function test_pdf_to_markdown_produces_a_markdown_file_with_the_text(): void
    {
        $response = $this->postJson(route('tools.convert', 'pdf-to-markdown'), [
            'files' => [$this->pdfUpload('catatan.pdf', 'Catatan kuliah minggu pertama')],
        ])->assertOk()->assertJsonPath('filename', 'catatan.md');

        $this->assertStringContainsString('Catatan kuliah minggu pertama', $this->resultContents($response));
    }
}
