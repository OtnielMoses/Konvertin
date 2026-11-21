<?php

namespace Tests\Feature;

use App\Conversion\EngineLocator;
use App\Conversion\WorkspaceManager;
use App\Http\Controllers\ToolController;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\ManagesConversionFixtures;
use Tests\TestCase;

/**
 * Alat PDF baru dengan mesin Python sungguhan. Dilewati bila pustakanya belum terpasang.
 */
class PdfToolsTest extends TestCase
{
    use ManagesConversionFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        if (! app(EngineLocator::class)->pythonReady()) {
            $this->markTestSkipped('Pustaka Python untuk pengolahan PDF belum terpasang.');
        }

        $this->useTemporaryWorkspaceRoot();
    }

    private function resultPath(TestResponse $response): string
    {
        return app(WorkspaceManager::class)->find(basename($response->json('download_url')))->result()['file'];
    }

    /**
     * PDF dua halaman yang sah, dibuat dengan menggabungkan dua PDF satu halaman.
     */
    private function twoPagePdf(): UploadedFile
    {
        $response = $this->postJson(route('tools.convert', 'merge-pdf'), [
            'files' => [$this->pdfUpload('a.pdf', 'Halaman satu'), $this->pdfUpload('b.pdf', 'Halaman dua')],
        ])->assertOk();

        return UploadedFile::fake()->createWithContent('dokumen.pdf', (string) file_get_contents($this->resultPath($response)));
    }

    public function test_split_every_page_returns_a_zip_with_one_file_per_page(): void
    {
        $this->postJson(route('tools.convert', 'split-pdf'), ['files' => [$this->twoPagePdf()], 'options' => ['mode' => 'every']])
            ->assertOk()
            ->assertJsonPath('filename', 'dokumen-dipisah.zip')
            ->assertJsonPath('report.files', 2);
    }

    public function test_split_with_out_of_range_pages_is_reported(): void
    {
        $this->postJson(route('tools.convert', 'split-pdf'), ['files' => [$this->pdfUpload()], 'options' => ['mode' => 'ranges', 'ranges' => '1-9']])
            ->assertUnprocessable()
            ->assertJsonPath('failure', 'invalid_pages');

        $this->assertSame([], $this->workspaceDirectories());
    }

    public function test_compress_reports_sizes_and_never_returns_a_larger_file(): void
    {
        $response = $this->postJson(route('tools.convert', 'compress-pdf'), [
            'files' => [$this->pdfUpload()],
            'options' => ['target_value' => '1', 'target_unit' => 'MB', 'strategy' => 'size'],
        ])
            ->assertOk()
            ->assertJsonStructure(['report' => ['before', 'after', 'target', 'target_met', 'reduction', 'already_optimal', 'strategy', 'passes']]);

        $this->assertLessThanOrEqual($response->json('report.before'), $response->json('report.after'));
    }

    public function test_protect_then_unlock_requires_the_correct_password(): void
    {
        $protected = $this->postJson(route('tools.convert', 'protect-pdf'), [
            'files' => [$this->pdfUpload('rahasia.pdf')],
            'options' => ['password' => 'kuliah123', 'password_confirmation' => 'kuliah123', 'permissions' => ['print']],
        ])->assertOk()->assertJsonPath('filename', 'rahasia-terproteksi.pdf');

        $locked = UploadedFile::fake()->createWithContent('rahasia.pdf', (string) file_get_contents($this->resultPath($protected)));

        $this->postJson(route('tools.convert', 'unlock-pdf'), ['files' => [$locked], 'options' => ['password' => 'salahsekali']])
            ->assertUnprocessable()
            ->assertJsonPath('failure', 'wrong_password');

        $locked = UploadedFile::fake()->createWithContent('rahasia.pdf', (string) file_get_contents($this->resultPath($protected)));

        $this->postJson(route('tools.convert', 'unlock-pdf'), ['files' => [$locked], 'options' => ['password' => 'kuliah123']])
            ->assertOk()
            ->assertJsonPath('filename', 'rahasia-terbuka.pdf');
    }

    public function test_other_tools_reject_password_protected_pdfs_with_a_clear_message(): void
    {
        $protected = $this->postJson(route('tools.convert', 'protect-pdf'), [
            'files' => [$this->pdfUpload()],
            'options' => ['password' => 'kuliah123', 'password_confirmation' => 'kuliah123'],
        ])->assertOk();

        $locked = UploadedFile::fake()->createWithContent('laporan.pdf', (string) file_get_contents($this->resultPath($protected)));

        $this->postJson(route('tools.convert', 'rotate-pdf'), ['files' => [$locked], 'options' => ['angle' => 90, 'scope' => 'all']])
            ->assertUnprocessable()
            ->assertJsonPath('failure', 'encrypted');
    }

    public function test_unlock_on_an_unprotected_pdf_says_there_is_nothing_to_unlock(): void
    {
        $this->postJson(route('tools.convert', 'unlock-pdf'), ['files' => [$this->pdfUpload()], 'options' => ['password' => 'apasaja']])
            ->assertUnprocessable()
            ->assertJsonPath('failure', 'not_protected');
    }

    public function test_redact_removes_the_searched_text_from_the_page(): void
    {
        $response = $this->postJson(route('tools.convert', 'redact-pdf'), [
            'files' => [$this->pdfUpload('data.pdf', 'NIK 3201234567890001')],
            'options' => ['terms' => '3201234567890001'],
        ])->assertOk()->assertJsonPath('report.marks', 1);

        $this->assertStringNotContainsString('3201234567890001', (string) file_get_contents($this->resultPath($response)));
    }

    public function test_organize_builds_the_requested_page_order_with_a_blank_page(): void
    {
        $this->postJson(route('tools.convert', 'organize-pdf'), [
            'files' => [$this->twoPagePdf()],
            'options' => ['spec' => json_encode([['src' => 0, 'page' => 1, 'rotate' => 90], ['blank' => true], ['src' => 0, 'page' => 0]])],
        ])->assertOk()->assertJsonPath('report.pages', 3);
    }

    public function test_edit_pdf_adds_annotations_and_requires_at_least_one_object(): void
    {
        $this->postJson(route('tools.convert', 'edit-pdf'), [
            'files' => [$this->pdfUpload()],
            'options' => ['objects' => json_encode([
                ['page' => 0, 'type' => 'text', 'rect' => [0.1, 0.1, 0.5, 0.15], 'text' => 'Catatan dosen'],
                ['page' => 0, 'type' => 'rect', 'rect' => [0.1, 0.2, 0.3, 0.3]],
            ])],
        ])->assertOk()->assertJsonPath('report.objects.text', 1);

        $this->postJson(route('tools.convert', 'edit-pdf'), ['files' => [$this->pdfUpload()], 'options' => ['objects' => '[]']])
            ->assertUnprocessable()
            ->assertJsonPath('failure', 'nothing_found');
    }

    public function test_forms_on_a_pdf_without_fields_and_nothing_added_is_reported(): void
    {
        $this->postJson(route('tools.convert', 'pdf-forms'), ['files' => [$this->pdfUpload()], 'options' => ['fields' => '{}', 'new_fields' => '[]']])
            ->assertUnprocessable()
            ->assertJsonPath('failure', 'no_fields');
    }

    public function test_workflow_runs_steps_in_order_and_reports_each_step(): void
    {
        $steps = json_encode([
            ['tool' => 'merge-pdf', 'options' => []],
            ['tool' => 'compress-pdf', 'options' => ['target_value' => '500', 'target_unit' => 'KB', 'strategy' => 'quality']],
            ['tool' => 'page-numbers', 'options' => ['position' => 'bottom', 'align' => 'center', 'size' => 11, 'font' => 'helvetica', 'format' => 'n', 'start' => 1, 'first_page' => 1]],
        ]);

        $this->postJson(route('tools.convert', 'create-workflow'), [
            'files' => [$this->pdfUpload('a.pdf'), $this->pdfUpload('b.pdf')],
            'options' => ['steps' => $steps],
        ])
            ->assertOk()
            ->assertJsonCount(3, 'report.steps')
            ->assertJsonPath('report.steps.2.op', 'pagenumbers');
    }

    public function test_downloaded_results_are_deleted_for_new_tools_too(): void
    {
        $response = $this->postJson(route('tools.convert', 'rotate-pdf'), ['files' => [$this->pdfUpload()], 'options' => ['angle' => 180, 'scope' => 'all']])
            ->assertOk();

        $this->withSession([ToolController::OWNER_SESSION_KEY => session(ToolController::OWNER_SESSION_KEY)])
            ->get($response->json('download_url'))
            ->assertOk()
            ->assertDownload('laporan-diputar.pdf');

        $this->assertSame([], $this->workspaceDirectories());
    }

    public function test_jpg_to_pdf_honours_page_options_and_can_return_separate_files(): void
    {
        $single = $this->postJson(route('tools.convert', 'jpg-to-pdf'), [
            'files' => [$this->photoUpload('foto.jpg', 'jpg', 1, 400, 300)],
            'options' => ['page_size' => 'letter', 'orientation' => 'portrait', 'margin' => 'none', 'fit' => 'actual', 'merge' => 'one'],
        ])->assertOk()->assertJsonPath('report.pages', 1);

        $pdf = (string) file_get_contents($this->resultPath($single));
        $this->assertMatchesRegularExpression('#/MediaBox\s*\[0 0 612 792\]#', $pdf);

        $this->postJson(route('tools.convert', 'jpg-to-pdf'), [
            'files' => [$this->photoUpload('a.jpg', 'jpg', 1, 300, 200), $this->photoUpload('b.jpg', 'jpg', 1, 300, 200)],
            'options' => ['merge' => 'separate'],
        ])
            ->assertOk()
            ->assertJsonPath('report.files', 2)
            ->assertJsonPath('filename', 'a.zip');
    }

    public function test_merge_can_add_one_bookmark_per_source_file(): void
    {
        $response = $this->postJson(route('tools.convert', 'merge-pdf'), [
            'files' => [$this->pdfUpload('Bab 1.pdf'), $this->pdfUpload('Bab 2.pdf')],
            'options' => ['bookmarks' => '1'],
        ])->assertOk()->assertJsonPath('report.pages', 2);

        $pdf = (string) file_get_contents($this->resultPath($response));
        $this->assertStringContainsString('/Outlines', $pdf);
        $this->assertStringContainsString('Bab 2', $pdf);
    }
}
