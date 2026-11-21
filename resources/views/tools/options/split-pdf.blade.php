<fieldset class="segmented">
    <legend>Cara memisahkan</legend>
    <label><input type="radio" name="{{ $name('mode') }}" value="ranges" checked>Rentang<small>1-3, 4-6</small></label>
    <label><input type="radio" name="{{ $name('mode') }}" value="pages">Ambil halaman<small>jadi satu PDF</small></label>
    <label><input type="radio" name="{{ $name('mode') }}" value="every">Tiap halaman<small>satu berkas/halaman</small></label>
    <label><input type="radio" name="{{ $name('mode') }}" value="chunk">Per jumlah<small>tiap N halaman</small></label>
</fieldset>

<div class="field" data-show-when="mode:ranges">
    <label for="{{ $id('ranges') }}">Rentang halaman</label>
    <input type="text" class="input" id="{{ $id('ranges') }}" name="{{ $name('ranges') }}" placeholder="Contoh: 1-3, 4-6" autocomplete="off" data-preview-input>
    <p class="field-hint">Setiap rentang menjadi satu berkas PDF.</p>
</div>
<div class="field" data-show-when="mode:pages">
    <label for="{{ $id('pages') }}">Halaman yang diambil</label>
    <input type="text" class="input" id="{{ $id('pages') }}" name="{{ $name('pages') }}" placeholder="Contoh: 1, 4, 7-9" autocomplete="off" data-preview-input>
</div>
<div class="field" data-show-when="mode:chunk">
    <label for="{{ $id('chunk') }}">Jumlah halaman per berkas</label>
    <input type="number" class="input" id="{{ $id('chunk') }}" name="{{ $name('chunk') }}" min="1" max="1000" value="2" data-preview-input>
</div>
<p class="field-hint" data-split-summary aria-live="polite"></p>
