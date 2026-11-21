<?php

namespace Tests\Feature;

use App\Conversion\UrlGuard;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ManagesConversionFixtures;
use Tests\Feature\Fakes\FakeConverter;
use Tests\TestCase;

class ConversionValidationTest extends TestCase
{
    use ManagesConversionFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTemporaryWorkspaceRoot();
        FakeConverter::reset();
        config(['konvertin.converters' => array_fill_keys(array_keys(config('konvertin.converters')), FakeConverter::class)]);
    }

    public function test_missing_file_shows_msg03_and_stores_nothing(): void
    {
        $this->postJson(route('tools.convert', 'pdf-to-word'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['files' => __('konvertin.msg03')]);

        $this->assertSame([], $this->workspaceDirectories());
        $this->assertSame([], FakeConverter::$received);
    }

    public function test_file_with_faked_extension_is_rejected_by_content_with_msg01(): void
    {
        $fake = UploadedFile::fake()->createWithContent('tugas.pdf', 'ini hanya teks, bukan PDF');

        $this->postJson(route('tools.convert', 'pdf-to-word'), ['files' => [$fake]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['files.0' => __('konvertin.msg01')]);

        $this->assertSame([], $this->workspaceDirectories());
    }

    public function test_pdf_sent_to_a_word_tool_is_rejected_with_msg01(): void
    {
        $this->postJson(route('tools.convert', 'word-to-pdf'), ['files' => [$this->pdfUpload('dokumen.docx')]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['files.0' => __('konvertin.msg01')]);
    }

    public function test_file_over_the_size_limit_shows_msg02(): void
    {
        config(['konvertin.max_file_kb' => 1]);
        $large = UploadedFile::fake()->createWithContent('besar.pdf', $this->pdfBytes(str_repeat('a', 2048)));

        $this->postJson(route('tools.convert', 'pdf-to-word'), ['files' => [$large]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['files.0' => __('konvertin.msg02')]);
    }

    public function test_too_many_files_shows_msg02(): void
    {
        config(['konvertin.max_files' => 2]);

        $this->postJson(route('tools.convert', 'merge-pdf'), [
            'files' => [$this->pdfUpload('a.pdf'), $this->pdfUpload('b.pdf'), $this->pdfUpload('c.pdf')],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['files' => __('konvertin.msg02')]);
    }

    public function test_single_file_tools_accept_only_one_file(): void
    {
        $this->postJson(route('tools.convert', 'pdf-to-word'), [
            'files' => [$this->pdfUpload('a.pdf'), $this->pdfUpload('b.pdf')],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['files' => __('konvertin.msg02')]);
    }

    public function test_merge_needs_at_least_two_pdfs(): void
    {
        $this->postJson(route('tools.convert', 'merge-pdf'), ['files' => [$this->pdfUpload()]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['files' => __('konvertin.merge_minimum')]);
    }

    public function test_html_to_pdf_requires_an_address(): void
    {
        $this->postJson(route('tools.convert', 'html-to-pdf'), ['url' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['url' => __('konvertin.url_required')]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedAddresses(): array
    {
        return [
            'not an address' => ['bukan alamat'],
            'ftp scheme' => ['ftp://contoh.ac.id/berkas'],
            'file scheme' => ['file:///etc/passwd'],
            'localhost' => ['http://localhost/admin'],
            'loopback ip' => ['http://127.0.0.1:8000/'],
            'private ip' => ['http://192.168.1.1/'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'ipv6 loopback' => ['http://[::1]/'],
        ];
    }

    #[DataProvider('rejectedAddresses')]
    public function test_html_to_pdf_rejects_invalid_or_internal_addresses(string $url): void
    {
        $this->postJson(route('tools.convert', 'html-to-pdf'), ['url' => $url])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['url' => __('konvertin.url_invalid')]);

        $this->assertSame([], FakeConverter::$received);
    }

    public function test_html_to_pdf_rejects_hostnames_that_resolve_to_the_internal_network(): void
    {
        $this->app->instance(UrlGuard::class, new class extends UrlGuard
        {
            protected function lookup(string $host): array
            {
                return ['10.0.0.5'];
            }
        });

        $this->postJson(route('tools.convert', 'html-to-pdf'), ['url' => 'https://intranet.contoh.ac.id/'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['url' => __('konvertin.url_invalid')]);
    }

    public function test_validation_without_javascript_redirects_back_with_the_message(): void
    {
        $this->from(route('tools.show', 'pdf-to-word'))
            ->post(route('tools.convert', 'pdf-to-word'), [])
            ->assertRedirect(route('tools.show', 'pdf-to-word'))
            ->assertSessionHasErrors(['files' => __('konvertin.msg03')]);
    }
}
