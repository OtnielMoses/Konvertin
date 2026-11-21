{{-- Word, PowerPoint, dan Excel ke PDF: pilihan hasil bila lebih dari satu berkas. --}}
<fieldset class="segmented" style="grid-template-columns: 1fr 1fr;">
    <legend>Hasil bila lebih dari satu berkas</legend>
    <label><input type="radio" name="{{ $name('merge') }}" value="one" checked>Satu PDF gabungan</label>
    <label><input type="radio" name="{{ $name('merge') }}" value="separate">PDF terpisah (ZIP)</label>
</fieldset>
<p class="field-hint">PDF gabungan mengikuti urutan kartu dan diberi satu penanda (bookmark) per berkas.</p>
