<input type="hidden" name="{{ $name('objects') }}" data-editor-output="objects">
<div class="field">
    <span class="field-label">Objek yang ditambahkan</span>
    <p class="field-hint" data-editor-count>Belum ada objek. Pilih alat di atas halaman, lalu klik atau seret pada halaman.</p>
    <ul class="obj-list" data-object-list></ul>
</div>
<div class="field" data-editor-props hidden>
    <span class="field-label">Objek terpilih</span>
    <div class="field-row">
        <div class="field">
            <label for="prop-color">Warna</label>
            <input type="color" class="input-color" id="prop-color" value="#d94832" data-prop="color">
        </div>
        <div class="field">
            <label for="prop-width">Tebal garis</label>
            <input type="number" class="input" id="prop-width" min="1" max="12" value="2" data-prop="width">
        </div>
        <div class="field">
            <label for="prop-fontsize">Ukuran teks</label>
            <input type="number" class="input" id="prop-fontsize" min="6" max="72" value="14" data-prop="fontsize">
        </div>
    </div>
    <div class="field">
        <label for="prop-text">Teks</label>
        <textarea class="textarea" id="prop-text" data-prop="text" style="min-height: 64px;"></textarea>
    </div>
</div>
