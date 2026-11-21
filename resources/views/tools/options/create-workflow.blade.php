<input type="hidden" name="{{ $name('steps') }}" data-workflow-output>
<p class="field-hint">Susun langkah di panel sebelah kiri. Setiap langkah memakai hasil langkah sebelumnya.</p>
<div class="field">
    <span class="field-label">Preset tersimpan di peramban ini</span>
    <div class="toolbar-row">
        <label for="wf-preset" class="visually-hidden">Pilih preset</label>
        <select class="select" id="wf-preset" data-preset-select style="flex: 1; min-width: 0;">
            <option value="">Pilih preset</option>
        </select>
        <button type="button" class="btn btn-secondary btn-sm" data-preset-load>Muat</button>
    </div>
    <div class="toolbar-row">
        <label for="wf-preset-name" class="visually-hidden">Nama preset baru</label>
        <input type="text" class="input" id="wf-preset-name" maxlength="40" placeholder="Nama preset" data-preset-name style="flex: 1; min-width: 0;">
        <button type="button" class="btn btn-secondary btn-sm" data-preset-save>Simpan</button>
    </div>
    <p class="field-hint" data-preset-status aria-live="polite">Kata sandi tidak ikut disimpan dalam preset.</p>
</div>
