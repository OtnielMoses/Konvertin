{{-- Cakupan halaman: semua halaman atau halaman tertentu. --}}
<fieldset class="segmented">
    <legend>Halaman</legend>
    <label><input type="radio" name="{{ $name('scope') }}" value="all" checked>Semua halaman</label>
    <label><input type="radio" name="{{ $name('scope') }}" value="pages">Halaman tertentu</label>
</fieldset>
<div class="field" data-show-when="scope:pages">
    <label for="{{ $id('pages') }}">Nomor halaman</label>
    <input type="text" class="input" id="{{ $id('pages') }}" name="{{ $name('pages') }}" placeholder="Contoh: 1-3, 5, 8-10" inputmode="numeric" autocomplete="off">
    <p class="field-hint">Pisahkan dengan koma. Rentang ditulis dengan tanda hubung.</p>
</div>
