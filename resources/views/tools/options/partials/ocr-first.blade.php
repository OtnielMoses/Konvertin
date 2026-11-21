{{-- PDF to Word / Markdown: OCR untuk halaman scan sebelum konversi. --}}
@php($ocrReady = app(\App\Conversion\EngineLocator::class)->ocrReady())
<input type="hidden" name="{{ $name('ocr_first') }}" value="0">
<label class="check"><input type="checkbox" name="{{ $name('ocr_first') }}" value="1" @disabled(! $ocrReady)>
    <span>OCR dulu untuk halaman scan<small>{{ $ocrReady ? $hint : 'Mesin OCR belum disiapkan di server ini.' }}</small></span>
</label>
