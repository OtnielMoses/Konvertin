@php($languages = \App\Conversion\Converters\AiTranslator::LANGUAGES)
<div class="field">
    <label for="{{ $id('source') }}">Bahasa sumber</label>
    <select class="select" id="{{ $id('source') }}" name="{{ $name('source') }}">
        @foreach ($languages as $code => $label)
            <option value="{{ $code }}">{{ $label }}</option>
        @endforeach
    </select>
</div>
<div class="field">
    <label for="{{ $id('target') }}">Bahasa tujuan</label>
    <select class="select" id="{{ $id('target') }}" name="{{ $name('target') }}">
        @foreach ($languages as $code => $label)
            @continue($code === 'auto')
            <option value="{{ $code }}" @selected($code === 'en')>{{ $label }}</option>
        @endforeach
    </select>
</div>
