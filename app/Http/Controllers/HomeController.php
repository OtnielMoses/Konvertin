<?php

namespace App\Http\Controllers;

use App\Conversion\ConversionService;
use App\Conversion\Tool;
use App\Conversion\ToolCatalog;
use App\Conversion\ToolCategory;
use Illuminate\View\View;

class HomeController extends Controller
{
    /**
     * Beranda (UI-01, fitur 4.1): pencarian, filter kategori, dan direktori alat.
     */
    public function __invoke(ToolCatalog $catalog, ConversionService $conversions): View
    {
        return view('home', [
            'categories' => collect(ToolCategory::cases())->map(fn (ToolCategory $category) => [
                'category' => $category,
                'tools' => $catalog->inCategory($category),
            ]),
            'quickPicks' => collect(['merge-pdf', 'word-to-pdf', 'pdf-to-word', 'jpg-to-pdf', 'translate-pdf'])
                ->map(fn (string $slug) => $catalog->find($slug)),
            'featured' => collect(['compress-pdf', 'assignment-pack', 'preflight-pdf', 'translate-pdf'])
                ->map(fn (string $slug) => $catalog->find($slug)),
            'availability' => $catalog->all()->map(fn (Tool $tool): bool => $conversions->isAvailable($tool)),
            'toolCount' => $catalog->all()->count(),
            'ttlMinutes' => (int) config('konvertin.ttl_minutes'),
        ]);
    }
}
