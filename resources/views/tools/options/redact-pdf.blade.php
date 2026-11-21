<input type="hidden" name="{{ $name('areas') }}" data-editor-output="areas">
<p class="field-hint" data-editor-count>Belum ada area yang ditandai. Pilih alat "Area redaksi" lalu seret pada halaman.</p>
<div class="field">
    <label for="{{ $id('terms') }}">Cari dan redaksi kata <span class="muted">(opsional)</span></label>
    <textarea class="textarea" id="{{ $id('terms') }}" name="{{ $name('terms') }}" maxlength="2000" placeholder="Satu kata atau frasa per baris, misalnya NIK atau nomor telepon"></textarea>
    <p class="field-hint">Setiap kemunculan pada semua halaman dihapus permanen.</p>
</div>
