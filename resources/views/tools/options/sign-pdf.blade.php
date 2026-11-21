<input type="hidden" name="{{ $name('objects') }}" data-editor-output="objects">

<div class="field" data-signature-maker>
    <span class="field-label">Buat tanda tangan</span>
    <div class="tabs" role="tablist" aria-label="Cara membuat tanda tangan">
        <button type="button" role="tab" id="tab-draw" aria-selected="true" aria-controls="sig-draw" data-sig-tab="draw">Gambar</button>
        <button type="button" role="tab" id="tab-type" aria-selected="false" aria-controls="sig-type" tabindex="-1" data-sig-tab="type">Ketik</button>
        <button type="button" role="tab" id="tab-upload" aria-selected="false" aria-controls="sig-upload" tabindex="-1" data-sig-tab="upload">Unggah</button>
    </div>
    <div role="tabpanel" id="sig-draw" aria-labelledby="tab-draw" data-sig-panel="draw">
        <canvas class="sig-pad" width="600" height="200" aria-label="Area menggambar tanda tangan. Gunakan mouse, pena, atau jari." role="img" data-sig-pad></canvas>
        <button type="button" class="btn btn-ghost btn-sm" data-sig-clear>Bersihkan</button>
    </div>
    <div role="tabpanel" id="sig-type" aria-labelledby="tab-type" data-sig-panel="type" hidden>
        <label for="sig-name" class="field-label">Nama</label>
        <input type="text" class="input" id="sig-name" maxlength="60" autocomplete="name" data-sig-name>
        <div class="sig-typed" data-sig-typed aria-hidden="true"></div>
    </div>
    <div role="tabpanel" id="sig-upload" aria-labelledby="tab-upload" data-sig-panel="upload" hidden>
        <label for="sig-file" class="field-label">Gambar tanda tangan (PNG atau JPG)</label>
        <input type="file" class="input" id="sig-file" accept="image/png,image/jpeg" data-sig-file>
        <p class="field-hint">PNG dengan latar transparan memberi hasil terbaik.</p>
    </div>
    <button type="button" class="btn btn-secondary" data-sig-place><x-icon name="plus" size="18" />Tempatkan di halaman ini</button>
</div>

<div class="field">
    <span class="field-label">Tanda tangan di dokumen</span>
    <p class="field-hint" data-editor-count>Belum ada tanda tangan yang ditempatkan.</p>
    <ul class="obj-list" data-object-list></ul>
</div>
