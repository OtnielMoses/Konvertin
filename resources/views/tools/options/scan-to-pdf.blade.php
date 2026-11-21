<input type="hidden" name="{{ $name('transforms') }}" data-scan-output>
<label class="check"><input type="hidden" name="{{ $name('auto_edges') }}" value="0"><input type="checkbox" name="{{ $name('auto_edges') }}" value="1" checked>
    <span>Cari tepi kertas otomatis<small>Berlaku untuk foto yang titik sudutnya tidak Anda geser. Bila tepi tidak ditemukan, titik sudut bawaan yang dipakai.</small></span>
</label>
<div class="field">
    <label for="{{ $id('enhance') }}">Tampilan hasil</label>
    <select class="select" id="{{ $id('enhance') }}" name="{{ $name('enhance') }}">
        <option value="none" selected>Asli, tanpa perubahan warna</option>
        <option value="color">Perjelas, tetap berwarna</option>
        <option value="gray">Perjelas, abu-abu</option>
        <option value="bw">Hitam-putih tajam</option>
    </select>
    <p class="field-hint">Perjelas meratakan bayangan, menaikkan kontras, dan meredam bintik. Hitam-putih paling mudah dibaca untuk dokumen teks.</p>
</div>
<label class="check"><input type="hidden" name="{{ $name('ocr') }}" value="0"><input type="checkbox" name="{{ $name('ocr') }}" value="1">
    <span>Jalankan OCR<small>Teks hasil pindai dapat dicari dan disalin. Proses lebih lama.</small></span>
</label>
