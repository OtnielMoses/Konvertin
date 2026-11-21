<?php

namespace Tests\Feature;

use App\Http\Controllers\ToolController;
use Tests\Feature\Concerns\ManagesConversionFixtures;
use Tests\Feature\Fakes\FakeConverter;
use Tests\TestCase;

/**
 * Pratinjau hasil sebelum unduh: hanya untuk pemilik sesi, tidak menghapus
 * berkas, dan tautan unduh satu kali tetap berlaku.
 */
class ResultPreviewTest extends TestCase
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
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function convert(): array
    {
        $response = $this->postJson(route('tools.convert', 'merge-pdf'), [
            'files' => [$this->pdfUpload('a.pdf'), $this->pdfUpload('b.pdf')],
        ])->assertOk();

        return [$response->json(), session(ToolController::OWNER_SESSION_KEY)];
    }

    public function test_the_owner_can_preview_the_result_and_still_download_it_once(): void
    {
        [$payload, $owner] = $this->convert();

        $this->assertSame('pdf', $payload['preview_type']);
        $this->assertNotNull($payload['preview_url']);

        $preview = $this->withSession([ToolController::OWNER_SESSION_KEY => $owner])->get($payload['preview_url']);
        $preview->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $preview->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF', $preview->streamedContent());

        // Previewing twice is fine, and the files are still there.
        $this->withSession([ToolController::OWNER_SESSION_KEY => $owner])->get($payload['preview_url'])->assertOk();
        $this->assertCount(1, $this->workspaceDirectories());

        $this->withSession([ToolController::OWNER_SESSION_KEY => $owner])->get($payload['download_url'])->assertOk();
        $this->assertSame([], $this->workspaceDirectories());

        // After the one-time download the preview is gone too.
        $this->withSession([ToolController::OWNER_SESSION_KEY => $owner])->get($payload['preview_url'])->assertNotFound();
    }

    public function test_other_sessions_cannot_preview(): void
    {
        [$payload] = $this->convert();

        $this->withSession([ToolController::OWNER_SESSION_KEY => str_repeat('x', 64)])
            ->get($payload['preview_url'])
            ->assertNotFound();

        $this->assertCount(1, $this->workspaceDirectories());
    }

    public function test_tool_pages_show_the_effective_upload_limits(): void
    {
        config(['konvertin.max_files' => 30]);

        $this->get(route('tools.show', 'jpg-to-pdf'))
            ->assertOk()
            ->assertSee('maxRequestBytes', false)
            ->assertSee(' MB per foto · hingga ', false);
    }
}
