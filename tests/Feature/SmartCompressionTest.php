<?php

namespace Tests\Feature;

use App\Conversion\EngineLocator;
use App\Conversion\WorkspaceManager;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\ManagesConversionFixtures;
use Tests\TestCase;

/**
 * Smart Compression dengan mesin Python sungguhan: ukuran dihitung dari berkas hasil,
 * target yang tidak tercapai dilaporkan apa adanya, dan hasilnya tetap PDF yang sah.
 */
class SmartCompressionTest extends TestCase
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

    private function resultFile(TestResponse $response): string
    {
        return app(WorkspaceManager::class)->find(basename($response->json('download_url')))->result()['file'];
    }

    private function compress(array $options, string $kind = 'pdf'): TestResponse
    {
        return $this->postJson(route('tools.convert', 'compress-pdf'), [
            'files' => [$this->photoUpload('foto-praktikum.pdf', $kind)],
            'options' => $options,
        ]);
    }

    public function test_a_reachable_target_is_met_and_the_reported_size_is_the_real_file_size(): void
    {
        $response = $this->compress(['target_value' => '900', 'target_unit' => 'KB', 'strategy' => 'size'])->assertOk();
        $report = $response->json('report');
        $file = $this->resultFile($response);

        $this->assertTrue($report['target_met']);
        $this->assertSame(900 * 1024, $report['target']);
        $this->assertSame(filesize($file), $report['after']);
        $this->assertLessThanOrEqual($report['target'], $report['after']);
        $this->assertSame($report['before'] - $report['after'], $report['saved']);
        $this->assertStringStartsWith('%PDF', (string) file_get_contents($file, false, null, 0, 4));
        $this->assertSame('foto-praktikum-kompres.pdf', $response->json('filename'));
    }

    public function test_the_lightest_passing_step_is_used_so_quality_is_not_lowered_needlessly(): void
    {
        $loose = $this->compress(['target_value' => '40', 'target_unit' => 'MB', 'strategy' => 'size'])->assertOk();

        // A generous target is met without touching images at all.
        $this->assertTrue($loose->json('report.target_met'));
        $this->assertFalse($loose->json('report.images_changed'));
        $this->assertNull($loose->json('report.image_quality'));
    }

    public function test_an_unreachable_target_returns_the_best_real_result_with_a_suggestion(): void
    {
        $response = $this->compress(['target_value' => '20', 'target_unit' => 'KB', 'strategy' => 'quality'])->assertOk();
        $report = $response->json('report');

        $this->assertFalse($report['target_met']);
        $this->assertSame(filesize($this->resultFile($response)), $report['after']);
        $this->assertGreaterThan($report['target'], $report['after']);
        $this->assertSame($report['after'], $report['suggested_target']);
        $this->assertSame('strategy', $report['hint']);
        $this->assertGreaterThanOrEqual(150, $report['image_dpi']);
    }

    public function test_strategy_limits_and_advanced_options_are_respected(): void
    {
        $response = $this->compress([
            'target_value' => '20', 'target_unit' => 'KB', 'strategy' => 'size',
            'downsample' => '0', 'min_quality' => '70', 'strip_metadata' => '1', 'optimize_resources' => '1', 'subset_fonts' => '1',
        ])->assertOk();

        $this->assertNull($response->json('report.image_dpi'));
        $this->assertGreaterThanOrEqual(70, $response->json('report.image_quality'));
        $this->assertSame('size', $response->json('report.strategy'));
    }
}
