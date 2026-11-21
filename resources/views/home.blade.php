@php
    // Teks sorotan untuk empat alat utama. Fakta perilaku alat, bukan data contoh.
    $highlights = [
        'compress-pdf' => [
            'color' => 'yellow',
            'text' => 'Tentukan ukuran maksimal, misalnya 2 MB untuk portal kampus. Kompresi berhenti di tahap pertama yang sudah memenuhi target.',
            'points' => ['Target dalam KB atau MB', 'Banyak PDF sekaligus', 'Ukuran hasil diukur dari berkas nyata'],
        ],
        'assignment-pack' => [
            'color' => 'pink',
            'text' => 'Gabungkan sampul, laporan Word, screenshot, dan lampiran menjadi satu PDF bernomor sesuai pedoman kampus.',
            'points' => ['Romawi untuk bagian awal, Arab untuk isi', 'Bookmark per bagian', 'Pratinjau sebelum ekspor'],
        ],
        'preflight-pdf' => [
            'color' => 'sky',
            'text' => 'Periksa PDF sebelum dikumpulkan: halaman kosong atau ganda, ukuran kertas, halaman scan, font, dan aksesibilitas.',
            'points' => ['Laporan per kategori', 'Hapus halaman kosong dan ganda', 'Rekomendasi OCR untuk halaman scan'],
        ],
        'translate-pdf' => [
            'color' => 'green',
            'text' => 'Terjemahkan isi PDF dengan posisi judul, paragraf, dan tabel dipertahankan.',
            'points' => ['12 bahasa', 'Tata letak dipertahankan', 'Selalu diberi tahu sebelum teks dikirim ke layanan penerjemahan'],
        ],
    ];
@endphp
<x-layout body-class="page-home">
    <section class="sh-hero" aria-labelledby="judul">
        {{-- Lapisan bentuk kertas-guntingan, terpotong oleh tepi layar. --}}
        <div class="shape-layer" aria-hidden="true">
            <x-shape kind="pentagon" color="yellow" :size="240" :rotate="-12" class="hs-1" />
            <x-shape kind="semicircle" color="red" :size="300" class="hs-2" :delay="-2" />
            <x-shape kind="star" color="pink" :size="150" :rotate="12" class="hs-3" :delay="-4" />
            <x-shape kind="circle" color="sky" :size="220" class="hs-4" :delay="-1" />
            <x-shape kind="triangle" color="green" :size="130" :rotate="14" class="hs-5" :delay="-3" />
            <x-shape kind="arch" color="sun" :size="120" :rotate="-8" class="hs-6" :delay="-5" />
            <x-shape kind="circle" color="pink" :size="64" class="hs-7" :delay="-6" />
        </div>

        <div class="container sh-hero-inner">
            <span class="hero-mark intro" style="--i: 0"><x-logo-mark :size="30" /></span>
            <p class="mono-label intro" style="--i: 0">Untuk tugas dan laporan kuliah</p>
            <h1 id="judul" class="sh-hero-title">
                <span class="intro-line"><span class="intro" style="--i: 1">Semua yang dibutuhkan</span></span>
                <span class="intro-line"><span class="intro" style="--i: 2">dokumen tugas Anda.</span></span>
            </h1>
            <p class="sh-hero-lead intro" style="--i: 3">Ubah, susun, perkecil, dan periksa dokumen dalam satu ruang kerja sederhana. Langsung dari peramban, tanpa akun.</p>

            <div class="sh-hero-actions intro" style="--i: 4">
                <a class="btn btn-primary btn-arrow" href="#semua-alat">Lihat semua alat</a>
                <a class="btn btn-dark" href="{{ route('tools.show', 'assignment-pack') }}">Susun paket tugas</a>
            </div>

            <div class="search intro" style="--i: 5" role="search">
                <label for="cari-alat" class="visually-hidden">Cari alat</label>
                <x-icon name="search" size="18" />
                <input type="search" id="cari-alat" placeholder="Cari alat, misalnya: kompres, gabung, Word" autocomplete="off" data-tool-search aria-describedby="hasil-cari">
                <kbd class="search-key" aria-hidden="true">/</kbd>
            </div>
            <p id="hasil-cari" class="visually-hidden" aria-live="polite" data-search-status></p>

            <ul class="quick-list intro" style="--i: 6" aria-label="Alat yang sering dipakai">
                @foreach ($quickPicks as $tool)
                    <li>
                        @if ($availability[$tool->slug])
                            <a class="chip" href="{{ route('tools.show', $tool->slug) }}"><x-icon :name="$tool->icon" size="16" />{{ $tool->name }}</a>
                        @else
                            <span class="chip is-locked" aria-disabled="true" title="Belum tersedia"><x-icon name="lock" size="14" />{{ $tool->name }}<span class="visually-hidden"> (belum disiapkan)</span></span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    <section class="sh-featured" aria-labelledby="judul-sorotan">
        <div class="shape-layer" aria-hidden="true">
            <x-shape kind="semicircle" color="yellow" :size="260" :rotate="180" class="fs-1" :float="false" />
            <x-shape kind="star" color="red" :size="110" :rotate="-10" class="fs-2" />
            <x-shape kind="circle" color="sky" :size="180" class="fs-3" :delay="-3" />
        </div>
        <div class="container">
            <div class="section-head" data-reveal>
                <div>
                    <p class="mono-label">Paling sering dipakai</p>
                    <h2 id="judul-sorotan">Siap untuk pengumpulan tugas</h2>
                </div>
                <a class="btn btn-primary btn-arrow" href="#semua-alat">Semua alat</a>
            </div>

            <div class="sh-featured-grid">
                @foreach ($featured as $tool)
                    @php($ready = $availability[$tool->slug])
                    @php($info = $highlights[$tool->slug])
                    @php($tag = $ready ? 'a' : 'div')
                    <{{ $tag }} @class(['sh-feature', 'is-unavailable' => ! $ready])
                        @if ($ready) href="{{ route('tools.show', $tool->slug) }}" @else aria-disabled="true" @endif
                        data-reveal style="--d: {{ $loop->index * 90 }}ms">
                        <div class="sh-feature-art" aria-hidden="true">
                            <x-shape kind="{{ ['pentagon', 'arch', 'circle', 'triangle'][$loop->index] }}" :color="$info['color']" :size="96" :rotate="$loop->even ? -8 : 10" :float="false" class="sh-feature-shape" />
                            <span class="sh-feature-icon"><x-icon :name="$tool->icon" size="26" /></span>
                        </div>
                        <div class="sh-feature-copy">
                            <h3>{{ $tool->name }}</h3>
                            <p>{{ $info['text'] }}</p>
                            <ul>
                                @foreach ($info['points'] as $point)
                                    <li><x-icon name="check" size="16" />{{ $point }}</li>
                                @endforeach
                            </ul>
                        </div>
                        @if ($ready)
                            <span class="sh-feature-link">Buka alat<x-icon name="chevron-right" size="16" /></span>
                        @else
                            <span class="sh-feature-link is-locked"><x-icon name="lock" size="16" />Belum disiapkan di server ini</span>
                        @endif
                    </{{ $tag }}>
                @endforeach
            </div>
        </div>
    </section>

    <section class="container directory" id="semua-alat" aria-labelledby="judul-alat">
        <div class="section-head directory-head" data-reveal>
            <div>
                <p class="mono-label">{{ $toolCount }} alat · {{ count($categories) }} kategori</p>
                <h2 id="judul-alat">Semua alat</h2>
            </div>
            <p class="muted section-note">Pilih kategori atau cari dengan kata kunci. Setiap alat memberi tahu di mana dokumen Anda diproses.</p>
        </div>

        <div class="filters" role="toolbar" aria-label="Filter kategori">
            <button type="button" class="chip" aria-pressed="true" data-filter="all">Semua</button>
            @foreach ($categories as $group)
                <button type="button" class="chip" aria-pressed="false" data-filter="{{ $group['category']->value }}">{{ $group['category']->label() }}</button>
            @endforeach
        </div>

        @foreach ($categories as $group)
            @php($category = $group['category'])
            <section class="category-section" id="kategori-{{ $category->value }}" data-category-section="{{ $category->value }}" aria-labelledby="judul-{{ $category->value }}">
                <div class="category-head" data-reveal>
                    <span class="tool-icon"><x-icon :name="$category->icon()" size="20" /></span>
                    <div>
                        <h3 id="judul-{{ $category->value }}">{{ $category->label() }}</h3>
                        <p>{{ $category->description() }}</p>
                    </div>
                </div>

                @if ($category === \App\Conversion\ToolCategory::Convert)
                    @foreach (['to' => 'Convert to PDF', 'from' => 'Convert from PDF'] as $groupKey => $groupLabel)
                        <p class="category-sub" data-subgroup>{{ $groupLabel }}</p>
                        <div class="tool-grid">
                            @foreach ($group['tools']->filter(fn ($tool) => $tool->group === $groupKey) as $tool)
                                <x-tool-card :tool="$tool" :available="$availability[$tool->slug]" :reveal-index="$loop->index" />
                            @endforeach
                        </div>
                    @endforeach
                @else
                    <div class="tool-grid">
                        @foreach ($group['tools'] as $tool)
                            <x-tool-card :tool="$tool" :available="$availability[$tool->slug]" :reveal-index="$loop->index" />
                        @endforeach
                    </div>
                @endif
            </section>
        @endforeach

        <div class="empty-search" data-search-empty hidden>
            <x-shape kind="star" color="pink" :size="72" :float="false" class="empty-shape" />
            <h3>Alat tidak ditemukan</h3>
            <p>Coba kata lain, misalnya "gabung", "kompres", atau "tanda tangan".</p>
            <button type="button" class="btn btn-secondary" data-search-reset>Tampilkan semua alat</button>
        </div>
    </section>
</x-layout>
