{{-- OCR PDF: pilihan halaman. Halaman yang sudah punya teks selalu dilewati. --}}
@include('tools.partials.scope')
<p class="field-hint">Halaman yang sudah punya teks dilewati otomatis, jadi tidak ada teks ganda. Thumbnail di kiri menandai halaman yang perlu OCR.</p>
<div class="option-note-plain">
    <strong>Bahasa</strong>
    <p class="field-hint">Mesin OCR (RapidOCR) mengenali huruf Latin, termasuk bahasa Indonesia dan Inggris, tanpa perlu memilih bahasa. Huruf beraksen dan aksara non-Latin bisa kurang akurat.</p>
</div>
<input type="hidden" name="{{ $name('corrections') }}" value="" data-ocr-corrections>
