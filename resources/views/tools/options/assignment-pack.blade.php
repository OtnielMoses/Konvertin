{{-- Assignment Pack Builder: pengaturan per langkah. Nilai bagian dan penomoran disusun JavaScript ke field tersembunyi. --}}
@php
    $numberSections = [
        'cover' => ['Sampul', 'none', 1, false],
        'preliminary' => ['Bagian awal', 'roman_lower', 1, false],
        'main' => ['Isi utama', 'arabic', 1, false],
        'appendix' => ['Lampiran', 'arabic', 1, true],
    ];
    $styles = ['none' => 'Tanpa nomor', 'arabic' => '1, 2, 3', 'roman_lower' => 'i, ii, iii', 'roman_upper' => 'I, II, III'];
@endphp
<input type="hidden" name="{{ $name('sections') }}" data-pack-sections>
<input type="hidden" name="{{ $name('numbering') }}" data-pack-numbering>

<div class="pack-step" data-pack-step="1">
    <h3 class="step-title" tabindex="-1">1. Tambahkan dokumen</h3>
    <p class="field-hint">Masukkan PDF, dokumen Word (DOC/DOCX), dan gambar JPG atau PNG. Dokumen ditempatkan ke bagian yang sesuai dengan namanya, misalnya "sampul" atau "lampiran", dan bisa Anda pindahkan.</p>
    @unless ($wordReady)
        <div class="alert alert-warning" role="status"><x-icon name="info" size="18" /><p>Dokumen Word belum dapat diubah di server ini. Simpan dokumen Word sebagai PDF terlebih dahulu, lalu masukkan PDF-nya.</p></div>
    @endunless
    <div class="field">
        <label for="{{ $id('title') }}">Judul paket <span class="muted">(opsional)</span></label>
        <input type="text" class="input" id="{{ $id('title') }}" name="{{ $name('title') }}" maxlength="120" placeholder="Laporan Praktikum 3 - Kelompok 2" autocomplete="off">
        <p class="field-hint">Dipakai sebagai nama berkas dan judul dokumen PDF.</p>
    </div>
</div>

<div class="pack-step" data-pack-step="2" hidden>
    <h3 class="step-title" tabindex="-1">2. Susun dokumen</h3>
    <input type="hidden" name="{{ $name('grouping') }}" value="0">
    <label class="check"><input type="checkbox" name="{{ $name('grouping') }}" value="1" checked data-pack-grouping><span>Kelompokkan per bagian<small>Sampul, bagian awal, isi utama, dan lampiran. Matikan bila cukup satu urutan dan satu jenis nomor halaman.</small></span></label>
    <ul class="pack-section-summary" data-pack-section-summary></ul>
    <input type="hidden" name="{{ $name('bookmarks') }}" value="0">
    <label class="check"><input type="checkbox" name="{{ $name('bookmarks') }}" value="1" checked><span>Buat bookmark otomatis<small>Satu bookmark per dokumen, dinamai sesuai nama berkas (misalnya "BAB I Pendahuluan"), dan dikelompokkan per bagian bila pengelompokan aktif. Tujuannya dihitung dari halaman akhir setelah paket disusun.</small></span></label>
</div>

<div class="pack-step" data-pack-step="3" hidden>
    <h3 class="step-title" tabindex="-1">3. Pengaturan halaman</h3>
    <div class="field">
        <label for="{{ $id('paper') }}">Ukuran kertas</label>
        <select class="select" id="{{ $id('paper') }}" name="{{ $name('paper') }}" data-pack-paper>
            <option value="original">Ikuti ukuran asli setiap dokumen</option>
            <option value="a4" selected>A4 (210 × 297 mm)</option>
            <option value="f4">F4 / Folio (215 × 330 mm)</option>
            <option value="letter">Letter (216 × 279 mm)</option>
            <option value="legal">Legal (216 × 356 mm)</option>
        </select>
        <p class="field-hint" data-paper-hint>Halaman yang ukurannya berbeda diperkecil atau diperbesar secara proporsional agar utuh di kertas yang dipilih.</p>
    </div>
    <div data-paper-options>
        <fieldset class="segmented" style="grid-template-columns: repeat(3, 1fr);">
            <legend>Orientasi</legend>
            <label><input type="radio" name="{{ $name('orientation') }}" value="auto" checked>Ikuti halaman</label>
            <label><input type="radio" name="{{ $name('orientation') }}" value="portrait">Potret</label>
            <label><input type="radio" name="{{ $name('orientation') }}" value="landscape">Lanskap</label>
        </fieldset>
        <div class="field">
            <label for="{{ $id('margin_mm') }}">Margin tambahan: <span data-range-value="{{ $id('margin_mm') }}">0</span> mm</label>
            <input type="range" id="{{ $id('margin_mm') }}" name="{{ $name('margin_mm') }}" min="0" max="40" step="1" value="0">
            <p class="field-hint">Ruang kosong di tepi kertas. Isi halaman diperkecil seperlunya agar tetap utuh.</p>
        </div>
    </div>
</div>

<div class="pack-step" data-pack-step="4" hidden>
    <h3 class="step-title" tabindex="-1">4. Nomor halaman</h3>
    <p class="field-hint">Atur sesuai pedoman kampus Anda. Setiap bagian bisa punya format dan nomor awal sendiri.</p>
    <div class="number-rules">
        @foreach ($numberSections as $key => [$label, $style, $start, $continue])
            <fieldset class="number-rule" data-number-row="{{ $key }}">
                <legend data-number-title>{{ $label }}</legend>
                <div class="field">
                    <label for="{{ $id('style-'.$key) }}" class="visually-hidden">Format nomor {{ $label }}</label>
                    <select class="select" id="{{ $id('style-'.$key) }}" data-number-style>
                        @foreach ($styles as $value => $text)
                            <option value="{{ $value }}" @selected($value === $style)>{{ $text }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="number-rule-start" data-number-start-box>
                    <label for="{{ $id('start-'.$key) }}">Mulai dari</label>
                    <input type="number" class="input" id="{{ $id('start-'.$key) }}" min="0" max="9999" value="{{ $start }}" data-number-start @disabled($continue)>
                    <label class="check check-inline"><input type="checkbox" data-number-continue @checked($continue)><span>Lanjutkan</span></label>
                </div>
            </fieldset>
        @endforeach
    </div>

    <fieldset class="segmented" style="grid-template-columns: 1fr 1fr;">
        <legend>Posisi</legend>
        <label><input type="radio" name="{{ $name('number_position') }}" value="bottom" checked>Bawah</label>
        <label><input type="radio" name="{{ $name('number_position') }}" value="top">Atas</label>
    </fieldset>
    <fieldset class="segmented" style="grid-template-columns: repeat(3, 1fr);">
        <legend>Perataan</legend>
        <label><input type="radio" name="{{ $name('number_align') }}" value="left">Kiri</label>
        <label><input type="radio" name="{{ $name('number_align') }}" value="center" checked>Tengah</label>
        <label><input type="radio" name="{{ $name('number_align') }}" value="right">Kanan</label>
    </fieldset>
    <div class="field-row">
        <div class="field">
            <label for="{{ $id('number_font') }}">Huruf</label>
            <select class="select" id="{{ $id('number_font') }}" name="{{ $name('number_font') }}">
                <option value="times" selected>Times</option>
                <option value="helvetica">Helvetica</option>
            </select>
        </div>
        <div class="field">
            <label for="{{ $id('number_size') }}">Ukuran</label>
            <select class="select" id="{{ $id('number_size') }}" name="{{ $name('number_size') }}">
                @foreach ([9, 10, 11, 12, 14] as $size)
                    <option value="{{ $size }}" @selected($size === 11)>{{ $size }} pt</option>
                @endforeach
            </select>
        </div>
    </div>
    <div class="field">
        <label for="{{ $id('first_page_rule') }}">Halaman pertama setiap dokumen</label>
        <select class="select" id="{{ $id('first_page_rule') }}" name="{{ $name('first_page_rule') }}">
            <option value="same" selected>Sama seperti halaman lain</option>
            <option value="bottom_center">Nomor di bawah tengah (awal bab)</option>
            <option value="hide">Tanpa nomor, tetapi tetap dihitung</option>
        </select>
    </div>
    <div class="field">
        <label for="{{ $id('hide_pages') }}">Sembunyikan nomor di halaman <span class="muted">(opsional)</span></label>
        <input type="text" class="input" id="{{ $id('hide_pages') }}" name="{{ $name('hide_pages') }}" placeholder="Contoh: 2, 5-6" inputmode="numeric" autocomplete="off">
        <p class="field-hint">Urutan halaman di PDF akhir. Halaman tetap dihitung, hanya nomornya tidak dicetak.</p>
    </div>
</div>

<div class="pack-step" data-pack-step="5" hidden>
    <h3 class="step-title" tabindex="-1">5. Optimasi</h3>
    <input type="hidden" name="{{ $name('compress') }}" value="0">
    <label class="check"><input type="checkbox" name="{{ $name('compress') }}" value="1" data-pack-compress><span>Kompres PDF akhir<small>Memakai Smart Compression. Berguna bila portal pengumpulan membatasi ukuran berkas.</small></span></label>
    <div data-pack-compression hidden>
        @include('tools.options.compress-pdf', ['name' => $name, 'id' => $id])
    </div>
</div>

<div class="pack-step" data-pack-step="6" hidden>
    <h3 class="step-title" tabindex="-1">6. Pratinjau dan ekspor</h3>
    <p class="field-hint">Periksa susunan dan nomor halaman di sebelah kiri. Bila sudah sesuai, buat PDF final.</p>
    <ul class="pack-checklist" data-pack-checklist></ul>
</div>

<div class="pack-nav">
    <button type="button" class="btn btn-secondary" data-pack-prev hidden><x-icon name="chevron-left" size="16" />Kembali</button>
    <button type="button" class="btn btn-secondary" data-pack-next>Berikutnya<x-icon name="chevron-right" size="16" /></button>
</div>
