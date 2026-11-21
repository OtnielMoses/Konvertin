{{-- PDF to JPG: format, resolusi, kualitas, dan halaman. Ukuran piksel dihitung pasti dari ukuran halaman. --}}
<fieldset class="segmented">
    <legend>Format gambar</legend>
    <label><input type="radio" name="{{ $name('format') }}" value="jpg" checked>JPG<small>Lebih kecil, cocok untuk foto dan dibagikan.</small></label>
    <label><input type="radio" name="{{ $name('format') }}" value="png">PNG<small>Tanpa kompresi lossy, teks dan garis tetap tajam.</small></label>
</fieldset>
<div class="field">
    <label for="{{ $id('dpi') }}">Resolusi</label>
    <select class="select" id="{{ $id('dpi') }}" name="{{ $name('dpi') }}">
        <option value="72">72 DPI · layar, paling kecil</option>
        <option value="150" selected>150 DPI · layar dan presentasi</option>
        <option value="300">300 DPI · cetak</option>
        <option value="600">600 DPI · cetak detail, berkas sangat besar</option>
    </select>
</div>
<div class="field" data-show-when="format:jpg">
    <label for="{{ $id('quality') }}">Kualitas JPG: <span data-range-value="{{ $id('quality') }}">90</span></label>
    <input type="range" id="{{ $id('quality') }}" name="{{ $name('quality') }}" min="50" max="100" step="5" value="90">
    <p class="field-hint">Makin rendah, makin kecil berkasnya, tetapi tepi huruf bisa tampak berbintik.</p>
</div>
@include('tools.partials.scope')
<p class="field-hint export-info" data-export-info aria-live="polite"></p>
