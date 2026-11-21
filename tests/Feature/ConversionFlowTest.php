<?php

namespace Tests\Feature;

use App\Conversion\ConversionFailure;
use App\Conversion\WorkspaceManager;
use App\Http\Controllers\ToolController;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Concerns\ManagesConversionFixtures;
use Tests\Feature\Fakes\FakeConverter;
use Tests\TestCase;

class ConversionFlowTest extends TestCase
{
    use ManagesConversionFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTemporaryWorkspaceRoot();
        FakeConverter::reset();
        config(['konvertin.converters' => array_fill_keys(array_keys(config('konvertin.converters')), FakeConverter::class)]);
    }

    /**
     * @return array{0: string, 1: string} Tautan unduh dan token pemilik sesi.
     */
    private function convertOnePdf(): array
    {
        $response = $this->postJson(route('tools.convert', 'pdf-to-word'), [
            'files' => [$this->pdfUpload('Laporan Akhir.pdf')],
        ]);

        $response->assertOk()
            ->assertJsonPath('filename', 'Laporan Akhir.pdf')
            ->assertJsonPath('expires_in_minutes', 30);

        return [$response->json('download_url'), session(ToolController::OWNER_SESSION_KEY)];
    }

    public function test_conversion_stores_input_under_a_random_name_and_returns_a_download_link(): void
    {
        [$downloadUrl] = $this->convertOnePdf();

        $this->assertMatchesRegularExpression('#/unduh/[A-Za-z0-9]{40}$#', $downloadUrl);

        $directories = $this->workspaceDirectories();
        $this->assertCount(1, $directories);

        $storedNames = array_map('basename', glob($directories[0].'/input-*'));
        $this->assertSame(['input-01.pdf'], $storedNames);
    }

    public function test_download_sends_the_file_once_then_deletes_every_temporary_file(): void
    {
        [$downloadUrl, $owner] = $this->convertOnePdf();

        $this->withSession([ToolController::OWNER_SESSION_KEY => $owner])
            ->get($downloadUrl)
            ->assertOk()
            ->assertDownload('Laporan Akhir.pdf')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

        $this->assertSame([], $this->workspaceDirectories());

        $this->withSession([ToolController::OWNER_SESSION_KEY => $owner])
            ->get($downloadUrl)
            ->assertStatus(410)
            ->assertSee(__('konvertin.msg07'));
    }

    public function test_another_session_cannot_download_the_result(): void
    {
        [$downloadUrl] = $this->convertOnePdf();

        $this->withSession([ToolController::OWNER_SESSION_KEY => str_repeat('x', 64)])
            ->get($downloadUrl)
            ->assertNotFound();

        $this->assertCount(1, $this->workspaceDirectories());
    }

    public function test_expired_result_is_not_sent_and_shows_msg07(): void
    {
        [$downloadUrl, $owner] = $this->convertOnePdf();

        $this->travel(31)->minutes();

        $this->withSession([ToolController::OWNER_SESSION_KEY => $owner])
            ->get($downloadUrl)
            ->assertStatus(410)
            ->assertSee(__('konvertin.msg07'));

        $this->assertSame([], $this->workspaceDirectories());
    }

    public function test_failed_conversion_reports_the_reason_and_removes_partial_files(): void
    {
        FakeConverter::$failWith = ConversionFailure::NoTables;

        $this->postJson(route('tools.convert', 'pdf-to-excel'), ['files' => [$this->pdfUpload()]])
            ->assertUnprocessable()
            ->assertJsonPath('failure', 'no_tables')
            ->assertJsonPath('message', __('konvertin.failure.no_tables'));

        $this->assertSame([], $this->workspaceDirectories());
    }

    public function test_tool_without_engine_does_not_accept_files(): void
    {
        FakeConverter::$available = false;

        $this->postJson(route('tools.convert', 'pdf-to-word'), ['files' => [$this->pdfUpload()]])
            ->assertUnprocessable()
            ->assertJsonPath('failure', 'engine_unavailable');

        $this->assertSame([], $this->workspaceDirectories());
    }

    public function test_merge_passes_files_to_the_engine_in_the_submitted_order(): void
    {
        $this->postJson(route('tools.convert', 'merge-pdf'), [
            'files' => [$this->pdfUpload('sampul.pdf'), $this->pdfUpload('isi.pdf'), $this->pdfUpload('lampiran.pdf')],
        ])->assertOk()->assertJsonPath('filename', 'sampul.pdf');

        $input = FakeConverter::$received[0];
        $this->assertSame(['input-01.pdf', 'input-02.pdf', 'input-03.pdf'], array_map('basename', $input->files));
    }

    public function test_conversion_without_javascript_shows_the_result_page(): void
    {
        $this->post(route('tools.convert', 'pdf-to-word'), ['files' => [$this->pdfUpload('esai.pdf')]])
            ->assertOk()
            ->assertSee('Berhasil, hasil siap diunduh')
            ->assertSee(__('konvertin.msg05'))
            ->assertSee('esai.pdf');
    }

    public function test_purge_command_deletes_only_expired_workspaces(): void
    {
        $this->convertOnePdf();
        $this->travel(10)->minutes();
        $this->convertOnePdf();
        $this->assertCount(2, $this->workspaceDirectories());

        $this->travel(25)->minutes();
        Artisan::call('konvertin:purge');

        $this->assertCount(1, $this->workspaceDirectories());
    }

    public function test_conversion_requests_are_rate_limited_per_client(): void
    {
        config(['konvertin.rate_limit_per_minute' => 2]);

        $this->postJson(route('tools.convert', 'pdf-to-word'), [])->assertUnprocessable();
        $this->postJson(route('tools.convert', 'pdf-to-word'), [])->assertUnprocessable();

        $this->postJson(route('tools.convert', 'pdf-to-word'), [])
            ->assertTooManyRequests()
            ->assertJsonPath('message', __('konvertin.too_many_requests'));
    }

    public function test_workspace_ids_are_never_resolved_outside_the_root(): void
    {
        $this->assertNull(app(WorkspaceManager::class)->find('../../../.env'));
    }
}
