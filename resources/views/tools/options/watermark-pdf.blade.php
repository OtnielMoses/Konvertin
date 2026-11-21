<fieldset class="segmented">
    <legend>Jenis watermark</legend>
    <label><input type="radio" name="{{ $name('type') }}" value="text" checked data-preview-input>Teks</label>
    <label><input type="radio" name="{{ $name('type') }}" value="image" data-preview-input>Gambar</label>
</fieldset>
<div class="field" data-show-when="type:text">
    <label for="{{ $id('text') }}">Teks watermark</label>
    <input type="text" class="input" id="{{ $id('text') }}" name="{{ $name('text') }}" maxlength="120" value="DRAF" data-preview-input>
</div>
<div class="field">
    <label for="{{ $id('position') }}">Posisi</label>
    <select class="select" id="{{ $id('position') }}" name="{{ $name('position') }}" data-preview-input>
        <option value="center">Tengah</option>
        <option value="top-left">Kiri atas</option>
        <option value="top-right">Kanan atas</option>
        <option value="bottom-left">Kiri bawah</option>
        <option value="bottom-right">Kanan bawah</option>
    </select>
</div>
<div class="field">
    <label for="{{ $id('opacity') }}">Transparansi: <span data-range-value="{{ $id('opacity') }}">30</span>%</label>
    <input type="range" id="{{ $id('opacity') }}" name="{{ $name('opacity') }}" min="5" max="100" value="30" data-preview-input>
</div>
<div class="field-row">
    <div class="field">
        <label for="{{ $id('size') }}"><span data-show-when="type:text">Ukuran huruf (pt)</span><span data-show-when="type:image">Lebar (% halaman)</span></label>
        <input type="number" class="input" id="{{ $id('size') }}" name="{{ $name('size') }}" min="5" max="200" value="48" data-preview-input>
    </div>
    <div class="field">
        <label for="{{ $id('rotation') }}">Rotasi (°)</label>
        <input type="number" class="input" id="{{ $id('rotation') }}" name="{{ $name('rotation') }}" min="-180" max="180" value="45" data-preview-input>
    </div>
    <div class="field" data-show-when="type:text">
        <label for="{{ $id('color') }}">Warna</label>
        <input type="color" class="input-color" id="{{ $id('color') }}" name="{{ $name('color') }}" value="#d94832" data-preview-input>
    </div>
</div>
@include('tools.partials.scope')
