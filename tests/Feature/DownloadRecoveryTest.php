<?php

namespace Tests\Feature;

use App\Conversion\WorkspaceManager;
use App\Http\Controllers\ToolController;
use Tests\Feature\Concerns\ManagesConversionFixtures;
use Tests\Feature\Fakes\FakeConverter;
use Tests\TestCase;
use ZipArchive;

/**
 * Antrean dan pemulihan unduhan: permintaan ganda ditolak, hasil yang berhasil dapat
 * dikemas bersama, hasil rusak tidak pernah ditawarkan, dan halaman "sudah dihapus"
 * mengarah kembali ke alatnya.
 */
class DownloadRecoveryTest extends TestCase
{
    use ManagesConversionFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        FakeConverter::reset();
        config(['konvertin.converters' => array_fill_keys(array_keys(config('konvertin.converters')), FakeConverter::class)]);
        $this->useTemporaryWorkspaceRoot();
    }

    private function convert(string $name, array $headers = []): string
    {
        return $this->withHeaders($headers)->postJson(route('tools.convert', 'pdf-to-word'), [
            'files' => [$this->pdfUpload($name)],
        ])->assertOk()->json('id');
    }

    public function test_the_same_request_id_is_never_processed_twice(): void
    {
        $headers = ['X-Konvertin-Request' => '6f1c2a90-1111-4c2b-9d3e-123456789abc'];

        $this->convert('a.pdf', $headers);

        $this->withHeaders($headers)->postJson(route('tools.convert', 'pdf-to-word'), ['files' => [$this->pdfUpload('a.pdf')]])
            ->assertStatus(409)
            ->assertJsonPath('failure', 'duplicate');

        $this->assertCount(1, FakeConverter::$received);
    }

    public function test_finished_results_of_the_session_are_bundled_into_one_zip_without_removing_them(): void
    {
        $first = $this->convert('bab 1.pdf');
        $second = $this->convert('bab 2.pdf');
        $owner = session(ToolController::OWNER_SESSION_KEY);

        $response = $this->withSession([ToolController::OWNER_SESSION_KEY => $owner])
            ->postJson(route('download.bundle'), ['ids' => [$first, $second, str_repeat('Z', 40)]])
            ->assertOk()
            ->assertJsonPath('files', 2)
            ->assertJsonPath('skipped', 1);

        $bundle = app(WorkspaceManager::class)->find(basename($response->json('download_url')));
        $zip = new ZipArchive;
        $zip->open($bundle->result()['file']);
        $this->assertSame(['bab 1.pdf', 'bab 2.pdf'], [$zip->getNameIndex(0), $zip->getNameIndex(1)]);
        $zip->close();

        // Individual results stay available until they are downloaded or expire.
        $this->assertNotNull(app(WorkspaceManager::class)->find($first)?->result());
    }

    public function test_results_of_another_session_cannot_be_bundled(): void
    {
        $id = $this->convert('rahasia.pdf');

        $this->withSession([ToolController::OWNER_SESSION_KEY => str_repeat('b', 64)])
            ->postJson(route('download.bundle'), ['ids' => [$id]])
            ->assertStatus(410);
    }

    public function test_a_corrupt_result_is_never_offered_for_download(): void
    {
        FakeConverter::$corrupt = true;

        $this->postJson(route('tools.convert', 'pdf-to-word'), ['files' => [$this->pdfUpload('a.pdf')]])
            ->assertStatus(422)
            ->assertJsonPath('failure', 'invalid_result');

        $this->assertSame([], $this->workspaceDirectories());
    }

    public function test_an_expired_result_points_back_to_its_tool(): void
    {
        $id = $this->convert('a.pdf');
        $owner = session(ToolController::OWNER_SESSION_KEY);
        $this->travel(31)->minutes();

        $this->withSession([ToolController::OWNER_SESSION_KEY => $owner])
            ->get(route('download', $id))
            ->assertStatus(410)
            ->assertSee('Buka lagi PDF to Word')
            ->assertSee(route('tools.show', 'pdf-to-word'), false);
    }

    public function test_tool_pages_show_where_documents_are_processed(): void
    {
        $this->get(route('tools.show', 'merge-pdf'))
            ->assertSee('Lokasi pemrosesan')
            ->assertSee('value="browser" checked', false)
            ->assertSee('Diproses di server Konvertin');

        $this->get(route('tools.show', 'compress-pdf'))
            ->assertDontSee('Lokasi pemrosesan')
            ->assertSee('data-region="queue"', false);

        $this->get(route('tools.show', 'pdf-to-word'))
            ->assertSee('data-region="queue"', false)
            ->assertSee('OCR dulu untuk halaman scan');
    }
}
