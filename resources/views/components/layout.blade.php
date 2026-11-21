@props(['title' => null, 'description' => null, 'bodyClass' => null, 'footer' => true])
@php
    $catalog = app(\App\Conversion\ToolCatalog::class);
    $categories = \App\Conversion\ToolCategory::cases();
    $featured = [
        ['slug' => 'compress-pdf', 'label' => 'Smart Compression'],
        ['slug' => 'assignment-pack', 'label' => 'Assignment Pack'],
    ];
    $currentTool = request()->route('tool');
    // Alat tanpa mesin tampil terkunci di menu dan footer, bukan sebagai tautan.
    $conversions = app(\App\Conversion\ConversionService::class);
    $toolReady = fn (\App\Conversion\Tool $tool): bool => $conversions->isAvailable($tool);
    $onHome = request()->routeIs('home');
@endphp
<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title ? $title.' · '.config('app.name') : config('app.name').' · Pengolahan PDF untuk tugas kuliah' }}</title>
        <meta name="description" content="{{ $description ?? 'Kompres PDF sampai ukuran target, susun paket tugas siap kumpul, dan olah PDF langsung dari peramban. Gratis, tanpa akun, berkas dihapus setelah diunduh.' }}">
        <meta name="view-transition" content="same-origin">
        <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 32 32%27%3E%3Crect width=%2732%27 height=%2732%27 rx=%2710%27 fill=%27%232727e6%27/%3E%3Cpath d=%27M16 11.4c-2.6-1.9-5.7-2.5-8.7-2.2a.8.8 0 0 0-.7.8v11.6c0 .5.4.8.9.8 2.9-.2 5.9.4 8.5 2.3z%27 fill=%27%23fff%27/%3E%3Cpath d=%27M16 11.4c2.6-1.9 5.7-2.5 8.7-2.2.4 0 .7.4.7.8v11.6c0 .5-.4.8-.9.8-2.9-.2-5.9.4-8.5 2.3z%27 fill=%27%23e1edff%27/%3E%3Cpath d=%27M20.6 9.7v5.6l1.5-1.1 1.5 1.1V9.5c-1-.1-2 0-3 .2z%27 fill=%27%23ffda00%27/%3E%3C/svg%3E">
        <script>document.documentElement.classList.add('js');</script>
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body @class([$bodyClass])>
        <a class="skip-link" href="#konten">Lewati ke konten utama</a>

        <header class="site-header">
            <div class="container">
                <nav class="nav" aria-label="Navigasi utama">
                    <a class="brand" href="{{ route('home') }}">
                        <x-logo-mark class="brand-mark" />
                        {{ config('app.name') }}
                    </a>

                    <ul class="nav-links glass-menu">
                        <li><a class="nav-link" href="{{ route('home') }}" @if ($onHome) aria-current="page" @endif><x-icon name="home" size="16" />Beranda</a></li>
                        @foreach ($featured as $item)
                            <li><a class="nav-link" href="{{ route('tools.show', $item['slug']) }}" @if ($currentTool === $item['slug']) aria-current="page" @endif><x-icon :name="$catalog->find($item['slug'])->icon" size="16" />{{ $item['label'] }}</a></li>
                        @endforeach
                        <li class="nav-dropdown" data-dropdown>
                            <button type="button" class="nav-link" aria-expanded="false" aria-controls="menu-kategori" data-dropdown-toggle>
                                <x-icon name="grid" size="16" />Kategori <x-icon name="chevron-down" size="14" />
                            </button>
                            <ul class="nav-menu" id="menu-kategori" hidden>
                                @foreach ($categories as $category)
                                    @php($groupTools = $catalog->inCategory($category))
                                    <li class="nav-group">
                                        <a href="{{ route('home') }}#kategori-{{ $category->value }}">
                                            <span class="tool-icon"><x-icon :name="$category->icon()" size="18" /></span>
                                            <span>{{ $category->label() }}<small>{{ $groupTools->count() }} alat</small></span>
                                            <x-icon name="chevron-right" size="16" class="nav-group-arrow" />
                                        </a>
                                        <ul class="nav-submenu" aria-label="Alat {{ $category->label() }}">
                                            @foreach ($groupTools as $groupTool)
                                                <li>
                                                    @if ($toolReady($groupTool))
                                                        <a href="{{ route('tools.show', $groupTool->slug) }}" @if ($currentTool === $groupTool->slug) aria-current="page" @endif>
                                                            <x-icon :name="$groupTool->icon" size="16" />{{ $groupTool->name }}
                                                        </a>
                                                    @else
                                                        <span class="is-locked" aria-disabled="true" title="Belum tersedia">
                                                            <x-icon :name="$groupTool->icon" size="16" />{{ $groupTool->name }}<x-icon name="lock" size="14" class="locked-icon" /><span class="visually-hidden"> (belum disiapkan)</span>
                                                        </span>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </li>
                                @endforeach
                            </ul>
                        </li>
                        <li><a class="nav-link" href="{{ route('home') }}#tentang"><x-icon name="info" size="16" />Tentang</a></li>
                    </ul>

                    <a class="btn btn-primary btn-sm nav-cta" href="{{ route('home') }}#semua-alat">
                        <x-icon name="search" size="16" />Cari alat
                    </a>

                    <button type="button" class="btn btn-ghost btn-icon menu-toggle" aria-expanded="false" aria-controls="menu-mobile" data-menu-toggle>
                        <x-icon name="menu" size="20" />
                        <span class="visually-hidden">Buka menu</span>
                    </button>
                </nav>

                <div class="mobile-nav" id="menu-mobile" hidden>
                    <ul>
                        @foreach ($featured as $item)
                            <li><a href="{{ route('tools.show', $item['slug']) }}"><span class="tool-icon"><x-icon :name="$catalog->find($item['slug'])->icon" size="18" /></span>{{ $item['label'] }}</a></li>
                        @endforeach
                        <li><a href="{{ route('home') }}#semua-alat"><span class="tool-icon"><x-icon name="search" size="18" /></span>Semua Alat</a></li>
                        @foreach ($categories as $category)
                            <li>
                                <a href="{{ route('home') }}#kategori-{{ $category->value }}">
                                    <span class="tool-icon"><x-icon :name="$category->icon()" size="18" /></span>{{ $category->label() }}
                                </a>
                            </li>
                        @endforeach
                        <li><a href="{{ route('home') }}#tentang"><span class="tool-icon"><x-icon name="info" size="18" /></span>Tentang</a></li>
                    </ul>
                </div>
            </div>
        </header>

        {{-- Navigasi cepat di layar kecil: tab bar kaca di bawah, mudah dijangkau ibu jari. --}}
        <nav class="tabbar glass-menu" aria-label="Navigasi cepat">
            <a href="{{ route('home') }}" @if ($onHome) aria-current="page" @endif><x-icon name="home" size="20" /><span>Beranda</span></a>
            <a href="{{ route('tools.show', 'compress-pdf') }}" @if ($currentTool === 'compress-pdf') aria-current="page" @endif><x-icon name="compress" size="20" /><span>Kompres</span></a>
            <a href="{{ route('tools.show', 'assignment-pack') }}" @if ($currentTool === 'assignment-pack') aria-current="page" @endif><x-icon name="pack" size="20" /><span>Paket Tugas</span></a>
            <a href="{{ route('home') }}#semua-alat"><x-icon name="grid" size="20" /><span>Semua Alat</span></a>
        </nav>

        <main id="konten" tabindex="-1">
            {{ $slot }}
        </main>

        @if ($footer)
        <footer class="site-footer" id="tentang">
            <div class="shape-layer" aria-hidden="true">
                <x-shape kind="semicircle" color="red" :size="280" class="ft-1" :float="false" />
                <x-shape kind="pentagon" color="yellow" :size="160" :rotate="14" class="ft-2" />
                <x-shape kind="circle" color="sky" :size="120" class="ft-3" :delay="-3" />
            </div>
            <div class="container">
                <section class="footer-hero" aria-labelledby="footer-judul">
                    {{-- Logo 3D: ubin logo dengan ketebalan berlapis, melayang dan berkilau. --}}
                    <div class="logo3d" aria-hidden="true">
                        <div class="logo3d-float">
                            <div class="logo3d-body">
                                <span class="l3-layer l3-depth-3"></span>
                                <span class="l3-layer l3-depth-2"></span>
                                <span class="l3-layer l3-depth-1"></span>
                                <x-logo-mark class="l3-face" :size="112" />
                                <span class="l3-shine"></span>
                            </div>
                        </div>
                        <span class="l3-shadow"></span>
                    </div>

                    <div class="footer-intro">
                        <p class="footer-eyebrow">Tentang {{ config('app.name') }}</p>
                        <h2 id="footer-judul">Satu tempat untuk menyiapkan PDF tugas kuliah.</h2>
                        <p class="footer-lead">{{ config('app.name') }} adalah platform pengolahan PDF yang dibuat untuk mahasiswa. Semua alatnya berjalan langsung dari peramban, tanpa memasang aplikasi, sehingga berkas tugas bisa disiapkan sesuai aturan pengumpulan dalam beberapa langkah.</p>
                        <ul class="footer-uses">
                            <li><span class="footer-use-icon"><x-icon name="compress" size="16" /></span><span><strong>Lolos batas unggah.</strong> Kompres PDF sampai di bawah ukuran yang diminta portal kampus.</span></li>
                            <li><span class="footer-use-icon"><x-icon name="pack" size="16" /></span><span><strong>Paket tugas siap kumpul.</strong> Gabungkan sampul, isi Word, foto, dan lampiran menjadi satu PDF bernomor.</span></li>
                            <li><span class="footer-use-icon"><x-icon name="convert" size="16" /></span><span><strong>Ubah dan rapikan dokumen.</strong> Konversi format, susun halaman, beri tanda tangan, kunci, atau terjemahkan.</span></li>
                        </ul>
                        <div class="footer-cta">
                            <a class="btn btn-primary btn-arrow" href="{{ route('home') }}#semua-alat">Lihat semua alat</a>
                            <a class="btn btn-dark" href="{{ route('tools.show', 'assignment-pack') }}">Susun paket tugas</a>
                        </div>
                    </div>
                </section>

                <section class="footer-pillars" aria-labelledby="footer-layanan">
                    <h2 id="footer-layanan" class="visually-hidden">Layanan dan keamanan data</h2>
                    <article class="footer-pillar">
                        <span class="footer-pillar-icon"><x-icon name="check-circle" size="20" /></span>
                        <h3>Gratis dipakai</h3>
                        <p>Semua alat bisa dipakai tanpa biaya dan tanpa membuat akun. Hasilnya bersih, tanpa watermark tambahan.</p>
                    </article>
                    <article class="footer-pillar">
                        <span class="footer-pillar-icon"><x-icon name="trash" size="20" /></span>
                        <h3>Berkas dihapus otomatis</h3>
                        <p>Berkas masukan dan hasil dihapus begitu hasil diunduh, atau otomatis setelah {{ config('konvertin.ttl_minutes') }} menit bila tidak diunduh.</p>
                    </article>
                    <article class="footer-pillar">
                        <span class="footer-pillar-icon"><x-icon name="lock" size="20" /></span>
                        <h3>Hanya bisa dibuka oleh Anda</h3>
                        <p>Setiap proses memakai ruang kerja terpisah dengan tautan acak yang hanya berlaku di peramban Anda. Isi berkas tidak pernah dicatat di log.</p>
                    </article>
                    <article class="footer-pillar">
                        <span class="footer-pillar-icon"><x-icon name="info" size="20" /></span>
                        <h3>Jelas soal pengiriman data</h3>
                        <p>Berkas diperiksa berdasarkan isinya dan diolah oleh {{ config('app.name') }}. Satu-satunya pengecualian, Translate PDF, mengirim teks ke layanan penerjemahan dan selalu memberi tahu Anda lebih dulu.</p>
                    </article>
                </section>

                <nav class="footer-tools" aria-labelledby="footer-fitur">
                    <div class="footer-tools-head">
                        <h2 id="footer-fitur">Semua fitur</h2>
                        <p>{{ $catalog->all()->count() }} alat dalam {{ count($categories) }} kategori.</p>
                    </div>
                    <div class="footer-tools-grid">
                        @foreach ($categories as $category)
                            <div class="footer-tool-group">
                                <h3><a href="{{ route('home') }}#kategori-{{ $category->value }}"><x-icon :name="$category->icon()" size="16" />{{ $category->label() }}</a></h3>
                                <ul>
                                    @foreach ($catalog->inCategory($category) as $footerTool)
                                        <li>
                                            @if ($toolReady($footerTool))
                                                <a href="{{ route('tools.show', $footerTool->slug) }}">{{ $footerTool->name }}</a>
                                            @else
                                                <span class="is-locked" aria-disabled="true" title="Belum tersedia">{{ $footerTool->name }}<x-icon name="lock" size="13" class="locked-icon" /><span class="visually-hidden"> (belum disiapkan)</span></span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </nav>
            </div>

            <div class="footer-bottom">
                <div class="container">
                    <p class="footer-brand"><x-logo-mark :size="22" />{{ config('app.name') }} &copy; {{ date('Y') }}</p>
                    <p>Kebijakan privasi dan syarat penggunaan sedang disusun.</p>
                    <a class="footer-top" href="#konten"><x-icon name="arrow-up" size="16" />Kembali ke atas</a>
                </div>
            </div>
        </footer>
        @endif

        {{-- Ikon yang dipakai elemen buatan JavaScript. --}}
        <template id="icon-templates">
            @foreach (['trash', 'arrow-up', 'arrow-down', 'grip', 'rotate', 'plus', 'page', 'check', 'x', 'download', 'chevron-left', 'chevron-right', 'chevron-down', 'alert', 'check-circle', 'image', 'clock', 'note', 'doc-word', 'info'] as $iconName)
                <x-icon :name="$iconName" size="18" data-icon-name="{{ $iconName }}" />
            @endforeach
        </template>
    </body>
</html>
