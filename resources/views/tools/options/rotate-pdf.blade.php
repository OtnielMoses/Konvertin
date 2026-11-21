<fieldset class="segmented">
    <legend>Putar searah jarum jam</legend>
    <label><input type="radio" name="{{ $name('angle') }}" value="90" checked>90°</label>
    <label><input type="radio" name="{{ $name('angle') }}" value="180">180°</label>
    <label><input type="radio" name="{{ $name('angle') }}" value="270">270°</label>
</fieldset>
@include('tools.partials.scope')
