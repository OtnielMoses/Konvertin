<?php

namespace Tests\Feature;

use App\Conversion\Tool;
use App\Conversion\ToolCatalog;
use Tests\Feature\Fakes\FakeConverter;
use Tests\TestCase;

class ToolPagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FakeConverter::reset();
        config(['konvertin.converters' => array_fill_keys(array_keys(config('konvertin.converters')), FakeConverter::class)]);
    }

    public function test_home_lists_both_categories_with_every_tool_and_merge(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('Convert to PDF')
            ->assertSee('Convert from PDF');

        app(ToolCatalog::class)->all()->each(function (Tool $tool) use ($response): void {
            $response->assertSee($tool->name)
                ->assertSee($tool->summary)
                ->assertSee(route('tools.show', $tool->slug), false);
        });
    }

    public function test_every_tool_page_shows_its_name_function_and_action(): void
    {
        app(ToolCatalog::class)->all()->each(function (Tool $tool): void {
            $this->get(route('tools.show', $tool->slug))
                ->assertOk()
                ->assertSee($tool->name)
                ->assertSee($tool->summary)
                ->assertSee(route('tools.convert', $tool->slug), false);
        });
    }

    public function test_html_to_pdf_page_asks_for_an_address_instead_of_a_file(): void
    {
        $this->get(route('tools.show', 'html-to-pdf'))
            ->assertOk()
            ->assertSee('type="url"', false)
            ->assertDontSee('type="file"', false);
    }

    public function test_unknown_address_shows_not_found_page_with_link_home(): void
    {
        $this->get('/alat-yang-tidak-ada')
            ->assertNotFound()
            ->assertSee('Halaman tidak ditemukan')
            ->assertSee(route('home'), false);
    }

    public function test_tool_without_installed_engine_says_so_and_disables_the_action(): void
    {
        FakeConverter::$available = false;

        $this->get(route('tools.show', 'word-to-pdf'))
            ->assertOk()
            ->assertSee(__('konvertin.failure.engine_unavailable'))
            ->assertSee('disabled', false);

        // Locked tools are shown, but nowhere on the page do they link to their workspace.
        $this->get('/')
            ->assertSee('Belum disiapkan')
            ->assertSee('aria-disabled="true"', false)
            ->assertDontSee('href="'.route('tools.show', 'word-to-pdf').'"', false);
    }
}
