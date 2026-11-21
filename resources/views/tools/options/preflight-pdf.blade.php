{{-- Preflight Check. Dipakai juga sebagai langkah Workflow (hanya bagian pembersihan). --}}
<div class="option-group">
    <p class="field-hint" data-preflight-intro>Tanpa pembersihan, Anda mendapat laporan PDF. Centang pembersihan untuk langsung mendapat PDF yang sudah rapi.</p>
    <input type="hidden" name="{{ $name('remove_blank') }}" value="0">
    <label class="check"><input type="checkbox" name="{{ $name('remove_blank') }}" value="1"><span>Hapus halaman kosong<small>Halaman tanpa teks dan tanpa isi yang terlihat.</small></span></label>
    <input type="hidden" name="{{ $name('remove_duplicates') }}" value="0">
    <label class="check"><input type="checkbox" name="{{ $name('remove_duplicates') }}" value="1"><span>Hapus halaman ganda<small>Halaman yang isinya sama persis dengan halaman sebelumnya. Halaman pertama tetap disimpan.</small></span></label>
</div>

<div class="field" data-preflight-target>
    <span class="field-label" id="{{ $id('limit-label') }}">Batas ukuran portal <span class="muted">(opsional)</span></span>
    <div class="target-input" role="group" aria-labelledby="{{ $id('limit-label') }}">
        <label for="{{ $id('target_value') }}" class="visually-hidden">Angka batas ukuran</label>
        <input type="number" class="input" id="{{ $id('target_value') }}" name="{{ $name('target_value') }}" min="0.01" step="0.01" inputmode="decimal" placeholder="Misalnya 2">
        <label for="{{ $id('target_unit') }}" class="visually-hidden">Satuan</label>
        <select class="select" id="{{ $id('target_unit') }}" name="{{ $name('target_unit') }}">
            <option value="MB" selected>MB</option>
            <option value="KB">KB</option>
        </select>
    </div>
    <p class="field-hint">Laporan menyebut apakah berkas sudah di bawah batas unggah.</p>
</div>
