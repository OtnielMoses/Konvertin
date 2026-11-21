<?php

namespace Tests\Feature;

use App\Conversion\Converters\AiTranslator;
use App\Conversion\ToolCatalog;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Concerns\ManagesConversionFixtures;
use Tests\Feature\Fakes\FakeConverter;
use Tests\TestCase;

/**
 * Validasi opsi alat baru. Mesin diganti FakeConverter agar yang diuji hanya aturan validasinya.
 */
class ToolValidationTest extends TestCase
{
    use ManagesConversionFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTemporaryWorkspaceRoot();
        FakeConverter::reset();
        config(['konvertin.converters' => array_fill_keys(app(ToolCatalog::class)->all()->keys()->all(), FakeConverter::class)]);
    }

    public function test_protect_requires_a_matching_confirmation_and_never_flashes_the_password(): void
    {
        $this->postJson(route('tools.convert', 'protect-pdf'), [
            'files' => [$this->pdfUpload()],
            'options' => ['password' => 'kuliah123', 'password_confirmation' => 'berbeda123'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['options.password' => __('konvertin.password_confirmation')]);

        $this->from(route('tools.show', 'protect-pdf'))
            ->post(route('tools.convert', 'protect-pdf'), [
                'files' => [$this->pdfUpload()],
                'options' => ['password' => 'pendek', 'password_confirmation' => 'pendek2'],
            ])
            ->assertSessionHasErrors('options.password');

        $this->assertNull(session()->getOldInput('options.password'));
    }

    public function test_split_ranges_must_be_well_formed(): void
    {
        $this->postJson(route('tools.convert', 'split-pdf'), ['files' => [$this->pdfUpload()], 'options' => ['mode' => 'ranges', 'ranges' => 'satu sampai tiga']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('options.ranges');
    }

    public function test_compare_needs_exactly_two_files(): void
    {
        $this->postJson(route('tools.convert', 'compare-pdf'), ['files' => [$this->pdfUpload()]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['files' => __('konvertin.files_minimum', ['min' => 2])]);

        $this->postJson(route('tools.convert', 'compare-pdf'), ['files' => [$this->pdfUpload('a.pdf'), $this->pdfUpload('b.pdf'), $this->pdfUpload('c.pdf')]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['files' => __('konvertin.files_exact', ['count' => 2])]);
    }

    public function test_image_watermark_requires_an_image_checked_by_content(): void
    {
        $base = ['type' => 'image', 'position' => 'center', 'opacity' => 30, 'size' => 40, 'rotation' => 0, 'scope' => 'all'];

        $this->postJson(route('tools.convert', 'watermark-pdf'), ['files' => [$this->pdfUpload()], 'options' => $base])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['aux.image' => __('konvertin.aux_required', ['label' => 'gambar watermark'])]);

        $fakeImage = UploadedFile::fake()->createWithContent('logo.png', 'bukan gambar');

        $this->postJson(route('tools.convert', 'watermark-pdf'), ['files' => [$this->pdfUpload()], 'options' => $base, 'aux' => ['image' => $fakeImage]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['aux.image' => __('konvertin.msg01')]);
    }

    public function test_photos_have_their_own_size_limit_while_documents_keep_the_file_limit(): void
    {
        config(['konvertin.max_image_kb' => 2]);
        $bigPhoto = UploadedFile::fake()->createWithContent('kamera.jpg', "\xFF\xD8\xFF\xE0".str_repeat("\0", 4096));
        $smallPhoto = UploadedFile::fake()->createWithContent('kecil.jpg', "\xFF\xD8\xFF\xE0".str_repeat("\0", 512));

        $this->postJson(route('tools.convert', 'jpg-to-pdf'), ['files' => [$smallPhoto, $bigPhoto]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['files.1' => __('konvertin.image_too_large', ['limit' => '0 MB'])]);

        $this->get(route('tools.show', 'jpg-to-pdf'))->assertOk()->assertSee('maks. 0 MB per foto');
        $this->get(route('tools.show', 'merge-pdf'))->assertOk()->assertSee(' MB per berkas')->assertDontSee('per foto');
        $this->get(route('tools.show', 'assignment-pack'))->assertOk()->assertSee(' MB per dokumen, 0 MB per foto');

        $this->postJson(route('tools.convert', 'merge-pdf'), ['files' => [$this->pdfUpload('a.pdf', str_repeat('Isi ', 900)), $this->pdfUpload('b.pdf')]])
            ->assertOk();
    }

    public function test_scan_accepts_png_and_jpg_photos_only(): void
    {
        $png = UploadedFile::fake()->createWithContent('scan.png', "\x89PNG\r\n\x1A\n".str_repeat("\0", 32));

        $this->postJson(route('tools.convert', 'scan-to-pdf'), ['files' => [$png], 'options' => ['ocr' => '0']])->assertOk();

        $this->postJson(route('tools.convert', 'scan-to-pdf'), ['files' => [$this->pdfUpload()]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['files.0' => __('konvertin.msg01')]);
    }

    public function test_workflow_order_rules_are_enforced(): void
    {
        $protectFirst = json_encode([
            ['tool' => 'protect-pdf', 'options' => ['password' => 'kuliah123', 'password_confirmation' => 'kuliah123']],
            ['tool' => 'compress-pdf', 'options' => ['level' => 'low']],
        ]);

        $this->postJson(route('tools.convert', 'create-workflow'), ['files' => [$this->pdfUpload()], 'options' => ['steps' => $protectFirst]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('options.steps');

        $mergeSecond = json_encode([
            ['tool' => 'compress-pdf', 'options' => ['level' => 'low']],
            ['tool' => 'merge-pdf', 'options' => []],
        ]);

        $this->postJson(route('tools.convert', 'create-workflow'), ['files' => [$this->pdfUpload()], 'options' => ['steps' => $mergeSecond]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('options.steps');

        $badStepOptions = json_encode([['tool' => 'compress-pdf', 'options' => ['level' => 'ekstrem']]]);

        $this->postJson(route('tools.convert', 'create-workflow'), ['files' => [$this->pdfUpload()], 'options' => ['steps' => $badStepOptions]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('options.steps');
    }

    public function test_translate_stays_available_and_says_it_is_not_set_up_without_an_api_key(): void
    {
        config(['konvertin.converters.translate-pdf' => AiTranslator::class, 'konvertin.ai.api_key' => null]);

        $this->get(route('tools.show', 'translate-pdf'))
            ->assertOk()
            ->assertSee('Bahasa tujuan')
            ->assertSee('Teks dokumen dikirim ke layanan penerjemahan')
            ->assertSee(__('konvertin.failure.engine_unavailable'));

        $this->postJson(route('tools.convert', 'translate-pdf'), ['files' => [$this->pdfUpload()], 'options' => ['source' => 'auto', 'target' => 'en']])
            ->assertUnprocessable()
            ->assertJsonPath('failure', 'engine_unavailable');
    }

    public function test_removed_ai_tools_are_gone_from_the_catalog_and_the_home_page(): void
    {
        $this->get('/ai-summarizer')->assertNotFound();
        $this->postJson('/ai-summarizer')->assertNotFound();

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('AI Summarizer')
            ->assertDontSee('AI PDF Tools')
            ->assertSee('Smart Compression')
            ->assertSee('Assignment Pack Builder')
            ->assertSee('Translate PDF');
    }

    public function test_smart_compression_requires_a_target_and_a_known_strategy(): void
    {
        $this->postJson(route('tools.convert', 'compress-pdf'), ['files' => [$this->pdfUpload()], 'options' => ['strategy' => 'balanced']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['options.target_value', 'options.target_unit']);

        $this->postJson(route('tools.convert', 'compress-pdf'), ['files' => [$this->pdfUpload()], 'options' => ['target_value' => '2', 'target_unit' => 'GB', 'strategy' => 'ekstrem']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['options.target_unit', 'options.strategy']);

        $this->postJson(route('tools.convert', 'compress-pdf'), ['files' => [$this->pdfUpload()], 'options' => ['target_value' => '2', 'target_unit' => 'MB', 'strategy' => 'balanced']])
            ->assertOk();
    }

    public function test_assignment_pack_needs_one_valid_section_per_file_and_valid_numbering(): void
    {
        $base = [
            'paper' => 'a4', 'orientation' => 'auto', 'margin_mm' => 0,
            'number_position' => 'bottom', 'number_align' => 'center', 'number_size' => 11, 'number_font' => 'times', 'first_page_rule' => 'same',
            'numbering' => json_encode(['main' => ['style' => 'arabic', 'start' => 1, 'continue' => false]]),
        ];
        $files = fn (): array => [$this->pdfUpload('sampul.pdf'), $this->pdfUpload('isi.pdf')];

        $this->postJson(route('tools.convert', 'assignment-pack'), ['files' => $files(), 'options' => [...$base, 'sections' => json_encode(['cover'])]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('options.sections');

        $this->postJson(route('tools.convert', 'assignment-pack'), ['files' => $files(), 'options' => [...$base, 'sections' => json_encode(['cover', 'bab-satu'])]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('options.sections');

        $this->postJson(route('tools.convert', 'assignment-pack'), ['files' => $files(), 'options' => [...$base, 'sections' => json_encode(['cover', 'main']), 'numbering' => json_encode(['main' => ['style' => 'hieroglyph']])]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('options.sections');

        $this->postJson(route('tools.convert', 'assignment-pack'), ['files' => $files(), 'options' => [...$base, 'sections' => json_encode(['cover', 'main']), 'compress' => '1']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['options.target_value', 'options.strategy']);

        $this->postJson(route('tools.convert', 'assignment-pack'), ['files' => $files(), 'options' => [...$base, 'sections' => json_encode(['cover', 'main'])]])
            ->assertOk();
    }

    public function test_assignment_pack_checks_word_files_by_content(): void
    {
        $fakeWord = UploadedFile::fake()->createWithContent('laporan.docx', 'bukan dokumen word');

        $this->postJson(route('tools.convert', 'assignment-pack'), ['files' => [$fakeWord], 'options' => ['sections' => '["main"]']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['files.0' => __('konvertin.msg01')]);
    }

    public function test_every_tool_page_renders_with_its_settings(): void
    {
        app(ToolCatalog::class)->all()->each(function ($tool): void {
            $this->get(route('tools.show', $tool->slug))->assertOk()->assertSee($tool->actionLabel);
        });
    }
}
