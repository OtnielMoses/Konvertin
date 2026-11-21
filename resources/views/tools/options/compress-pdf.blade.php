{{-- Smart Compression. Dipakai juga oleh langkah Workflow dan langkah Optimasi Assignment Pack. --}}
<div class="option-group" data-compress-options>
    <div class="field">
        <span class="field-label" id="{{ $id('target-label') }}">Ukuran maksimal hasil</span>
        <div class="target-input" role="group" aria-labelledby="{{ $id('target-label') }}">
            <label for="{{ $id('target_value') }}" class="visually-hidden">Angka ukuran target</label>
            <input type="number" class="input" id="{{ $id('target_value') }}" name="{{ $name('target_value') }}" value="2" min="0.01" step="0.01" inputmode="decimal" data-target-value>
            <label for="{{ $id('target_unit') }}" class="visually-hidden">Satuan</label>
            <select class="select" id="{{ $id('target_unit') }}" name="{{ $name('target_unit') }}" data-target-unit>
                <option value="MB" selected>MB</option>
                <option value="KB">KB</option>
            </select>
        </div>
        <div class="target-slider" data-target-slider hidden>
            <label for="{{ $id('target_slider') }}" class="visually-hidden">Target sebagai persen dari ukuran asli</label>
            <input type="range" id="{{ $id('target_slider') }}" min="5" max="95" step="1" value="50" data-target-range>
            <p class="field-hint" data-target-reference></p>
        </div>
        <div class="chip-row" aria-label="Batas unggah yang umum">
            @foreach ([['500', 'KB'], ['1', 'MB'], ['2', 'MB'], ['5', 'MB'], ['10', 'MB']] as [$value, $unit])
                <button type="button" class="chip chip-sm" data-target-preset="{{ $value }}|{{ $unit }}">{{ $value }} {{ $unit }}</button>
            @endforeach
        </div>
    </div>

    <fieldset class="segmented segmented-stack">
        <legend>Strategi kompresi</legend>
        <label><input type="radio" name="{{ $name('strategy') }}" value="quality">Utamakan kualitas<small>Gambar tidak diturunkan di bawah 150 DPI dan kualitas 70.</small></label>
        <label><input type="radio" name="{{ $name('strategy') }}" value="balanced" checked>Seimbang<small>Sampai 96 DPI dan kualitas 50. Cocok untuk sebagian besar tugas.</small></label>
        <label><input type="radio" name="{{ $name('strategy') }}" value="size">Utamakan ukuran<small>Sampai 72 DPI dan kualitas 30. Gambar bisa tampak buram.</small></label>
    </fieldset>

    <details class="advanced">
        <summary>Optimasi lanjutan<x-icon name="chevron-down" size="16" /></summary>
        <div class="advanced-body">
            <input type="hidden" name="{{ $name('downsample') }}" value="0">
            <label class="check"><input type="checkbox" name="{{ $name('downsample') }}" value="1" checked><span>Turunkan resolusi gambar<small>Gambar beresolusi tinggi diperkecil seperlunya. Bila dimatikan, hanya kualitas JPEG yang diubah.</small></span></label>

            <div class="field-row">
                <div class="field">
                    <label for="{{ $id('min_dpi') }}">Resolusi minimum</label>
                    <select class="select" id="{{ $id('min_dpi') }}" name="{{ $name('min_dpi') }}">
                        <option value="">Ikuti strategi</option>
                        @foreach ([200, 150, 110, 96, 72] as $dpi)
                            <option value="{{ $dpi }}">{{ $dpi }} DPI</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="{{ $id('min_quality') }}">Kualitas gambar minimum</label>
                    <select class="select" id="{{ $id('min_quality') }}" name="{{ $name('min_quality') }}">
                        <option value="">Ikuti strategi</option>
                        @foreach ([85, 70, 50, 30] as $quality)
                            <option value="{{ $quality }}">{{ $quality }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <input type="hidden" name="{{ $name('strip_metadata') }}" value="0">
            <label class="check"><input type="checkbox" name="{{ $name('strip_metadata') }}" value="1" checked><span>Hapus metadata<small>Judul, penulis, dan riwayat aplikasi pembuat dihapus dari berkas.</small></span></label>
            <input type="hidden" name="{{ $name('optimize_resources') }}" value="0">
            <label class="check"><input type="checkbox" name="{{ $name('optimize_resources') }}" value="1" checked><span>Optimalkan struktur berkas<small>Objek ganda dan yang tidak terpakai dibuang, lalu disimpan dalam aliran objek terkompresi.</small></span></label>
            <input type="hidden" name="{{ $name('subset_fonts') }}" value="0">
            <label class="check"><input type="checkbox" name="{{ $name('subset_fonts') }}" value="1" checked><span>Ringkas font<small>Hanya huruf yang dipakai dokumen yang disimpan dari setiap font tertanam.</small></span></label>
        </div>
    </details>
</div>
