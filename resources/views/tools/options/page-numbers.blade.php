<fieldset class="segmented">
    <legend>Posisi</legend>
    <label><input type="radio" name="{{ $name('position') }}" value="top" data-preview-input>Atas</label>
    <label><input type="radio" name="{{ $name('position') }}" value="bottom" checked data-preview-input>Bawah</label>
</fieldset>
<fieldset class="segmented">
    <legend>Perataan</legend>
    <label><input type="radio" name="{{ $name('align') }}" value="left" data-preview-input>Kiri</label>
    <label><input type="radio" name="{{ $name('align') }}" value="center" checked data-preview-input>Tengah</label>
    <label><input type="radio" name="{{ $name('align') }}" value="right" data-preview-input>Kanan</label>
</fieldset>
<div class="field">
    <label for="{{ $id('format') }}">Format nomor</label>
    <select class="select" id="{{ $id('format') }}" name="{{ $name('format') }}" data-preview-input>
        <option value="n">1</option>
        <option value="n_of_total">1 / 10</option>
        <option value="page_n">Halaman 1</option>
        <option value="page_n_of_total">Halaman 1 dari 10</option>
        <option value="roman_lower">i, ii, iii</option>
        <option value="roman_upper">I, II, III</option>
    </select>
</div>
<div class="field-row">
    <div class="field">
        <label for="{{ $id('size') }}">Ukuran huruf</label>
        <input type="number" class="input" id="{{ $id('size') }}" name="{{ $name('size') }}" min="6" max="48" value="11" data-preview-input>
    </div>
    <div class="field">
        <label for="{{ $id('font') }}">Jenis huruf</label>
        <select class="select" id="{{ $id('font') }}" name="{{ $name('font') }}" data-preview-input>
            <option value="helvetica">Helvetica</option>
            <option value="times">Times</option>
            <option value="courier">Courier</option>
        </select>
    </div>
    <div class="field">
        <label for="{{ $id('color') }}">Warna</label>
        <input type="color" class="input-color" id="{{ $id('color') }}" name="{{ $name('color') }}" value="#111827" data-preview-input>
    </div>
</div>
<div class="field-row">
    <div class="field">
        <label for="{{ $id('start') }}">Nomor awal</label>
        <input type="number" class="input" id="{{ $id('start') }}" name="{{ $name('start') }}" min="0" value="1" data-preview-input>
    </div>
    <div class="field">
        <label for="{{ $id('first_page') }}">Mulai halaman</label>
        <input type="number" class="input" id="{{ $id('first_page') }}" name="{{ $name('first_page') }}" min="1" value="1" data-preview-input>
    </div>
    <div class="field">
        <label for="{{ $id('last_page') }}">Sampai halaman</label>
        <input type="number" class="input" id="{{ $id('last_page') }}" name="{{ $name('last_page') }}" min="1" placeholder="Akhir" data-last-page>
    </div>
</div>
<div class="field-row">
    <div class="field">
        <label for="{{ $id('prefix') }}">Awalan</label>
        <input type="text" class="input" id="{{ $id('prefix') }}" name="{{ $name('prefix') }}" maxlength="30" placeholder="Contoh: Hal. " data-preview-input>
    </div>
    <div class="field">
        <label for="{{ $id('suffix') }}">Akhiran</label>
        <input type="text" class="input" id="{{ $id('suffix') }}" name="{{ $name('suffix') }}" maxlength="30" data-preview-input>
    </div>
</div>
