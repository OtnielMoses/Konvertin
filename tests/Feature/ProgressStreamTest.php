<?php

namespace Tests\Feature;

use App\Conversion\ConversionFailure;
use App\Conversion\EngineLocator;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\ManagesConversionFixtures;
use Tests\Feature\Fakes\FakeConverter;
use Tests\TestCase;

/**
 * Respons bertahap: langkah kemajuan yang nyata selama proses, lalu hasil atau galat.
 */
class ProgressStreamTest extends TestCase
{
    use ManagesConversionFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTemporaryWorkspaceRoot();
        FakeConverter::reset();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(TestResponse $response): array
    {
        $lines = array_filter(explode("\n", $response->streamedContent()));

        return array_values(array_map(fn (string $line): array => json_decode($line, true), $lines));
    }

    private function stream(string $tool, array $data): TestResponse
    {
        return $this->withHeaders(['X-Konvertin-Progress' => '1'])->postJson(route('tools.convert', $tool), $data);
    }

    public function test_steps_arrive_in_order_and_the_last_line_carries_the_result(): void
    {
        if (! app(EngineLocator::class)->pythonReady()) {
            $this->markTestSkipped('Pustaka Python untuk pengolahan PDF belum terpasang.');
        }

        $response = $this->stream('merge-pdf', ['files' => [$this->pdfUpload('a.pdf'), $this->pdfUpload('b.pdf'), $this->pdfUpload('c.pdf')]]);

        $response->assertOk()->assertHeader('Content-Type', 'application/x-ndjson; charset=UTF-8');
        $events = $this->events($response);
        $progress = array_values(array_filter($events, fn (array $event): bool => $event['type'] === 'progress'));
        $fractions = array_values(array_filter(array_column($progress, 'fraction'), fn ($value) => $value !== null));

        $this->assertContains('Menggabungkan berkas 2 dari 3', array_column($progress, 'label'));
        $this->assertSame($fractions, array_values(collect($fractions)->sort()->all()), 'Progress never moves backwards.');
        $this->assertEqualsWithDelta(1.0, end($fractions), 0.0001);

        $final = end($events);
        $this->assertSame('done', $final['type']);
        $this->assertSame('gabungan.pdf', $final['payload']['filename']);
        $this->assertStringContainsString('/unduh/', $final['payload']['download_url']);
    }

    public function test_a_failure_is_reported_as_the_final_line(): void
    {
        config(['konvertin.converters.pdf-to-word' => FakeConverter::class]);
        FakeConverter::$failWith = ConversionFailure::Encrypted;

        $events = $this->events($this->stream('pdf-to-word', ['files' => [$this->pdfUpload()]])->assertOk());
        $final = end($events);

        $this->assertSame('failed', $final['type']);
        $this->assertSame('encrypted', $final['failure']);
        $this->assertSame(ConversionFailure::Encrypted->message(), $final['message']);
        $this->assertSame([], $this->workspaceDirectories());
    }

    public function test_validation_errors_are_still_plain_json_before_any_streaming(): void
    {
        $this->stream('merge-pdf', ['files' => [$this->pdfUpload()]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('files');
    }
}
