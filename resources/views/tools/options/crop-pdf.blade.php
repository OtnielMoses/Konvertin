<fieldset class="segmented">
    <legend>Cara memotong</legend>
    <label><input type="radio" name="{{ $name('mode') }}" value="box" checked>Pilih area<small>seret di pratinjau</small></label>
    <label><input type="radio" name="{{ $name('mode') }}" value="margins">Margin<small>dalam milimeter</small></label>
</fieldset>

<input type="hidden" name="{{ $name('box') }}" data-crop-box>
<p class="field-hint" data-show-when="mode:box" data-crop-summary>Seret kotak pada pratinjau halaman untuk memilih area yang dipertahankan.</p>

<div class="field-row" data-show-when="mode:margins">
    @foreach (['top' => 'Atas', 'right' => 'Kanan', 'bottom' => 'Bawah', 'left' => 'Kiri'] as $side => $label)
        <div class="field">
            <label for="{{ $id($side) }}">{{ $label }} (mm)</label>
            <input type="number" class="input" id="{{ $id($side) }}" name="{{ $name($side) }}" min="0" max="200" step="0.5" value="10">
        </div>
    @endforeach
</div>
@include('tools.partials.scope')
