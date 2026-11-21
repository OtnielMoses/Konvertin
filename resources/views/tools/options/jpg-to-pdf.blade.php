<div class="option-group">
    <div class="field">
        <label for="{{ $id('page_size') }}">Ukuran halaman</label>
        <select class="select" id="{{ $id('page_size') }}" name="{{ $name('page_size') }}">
            <option value="a4" selected>A4 (210 × 297 mm)</option>
            <option value="f4">F4 / Folio (215 × 330 mm)</option>
            <option value="letter">Letter (216 × 279 mm)</option>
            <option value="legal">Legal (216 × 356 mm)</option>
            <option value="image">Ikuti ukuran gambar</option>
        </select>
    </div>

    <fieldset class="segmented" style="grid-template-columns: repeat(3, 1fr);">
        <legend>Orientasi</legend>
        <label><input type="radio" name="{{ $name('orientation') }}" value="auto" checked>Otomatis</label>
        <label><input type="radio" name="{{ $name('orientation') }}" value="portrait">Potret</label>
        <label><input type="radio" name="{{ $name('orientation') }}" value="landscape">Lanskap</label>
    </fieldset>
    <p class="field-hint" style="margin-top: -6px;">Otomatis: halaman lanskap untuk gambar yang lebar, potret untuk yang tinggi.</p>

    <fieldset class="segmented" style="grid-template-columns: repeat(3, 1fr);">
        <legend>Margin</legend>
        <label><input type="radio" name="{{ $name('margin') }}" value="none">Tanpa</label>
        <label><input type="radio" name="{{ $name('margin') }}" value="small" checked>Kecil</label>
        <label><input type="radio" name="{{ $name('margin') }}" value="large">Besar</label>
    </fieldset>

    <fieldset class="segmented segmented-stack">
        <legend>Penempatan gambar</legend>
        <label><input type="radio" name="{{ $name('fit') }}" value="fit" checked>Penuhi area halaman<small>Gambar diperbesar atau diperkecil sampai memenuhi halaman, tanpa terpotong.</small></label>
        <label><input type="radio" name="{{ $name('fit') }}" value="actual">Ukuran asli bila muat<small>Gambar kecil tidak diperbesar sehingga tidak pecah.</small></label>
    </fieldset>

    <fieldset class="segmented" style="grid-template-columns: 1fr 1fr;">
        <legend>Hasil</legend>
        <label><input type="radio" name="{{ $name('merge') }}" value="one" checked>Satu PDF</label>
        <label><input type="radio" name="{{ $name('merge') }}" value="separate">PDF terpisah (ZIP)</label>
    </fieldset>
</div>
