<input type="hidden" name="{{ $name('fields') }}" data-forms-output="fields">
<input type="hidden" name="{{ $name('new_fields') }}" data-editor-output="new_fields">
<label class="check"><input type="hidden" name="{{ $name('flatten') }}" value="0"><input type="checkbox" name="{{ $name('flatten') }}" value="1">
    <span>Kunci isian<small>Isian menjadi bagian halaman dan tidak bisa diubah lagi.</small></span>
</label>
<p class="field-hint" data-editor-count>Field baru: belum ada. Pilih "Field teks" atau "Checkbox" di atas halaman, lalu seret.</p>
<ul class="obj-list" data-object-list></ul>
