@props(['downloadUrl' => null, 'filename' => '', 'size' => '', 'expiresInMinutes' => 30, 'resetHref' => null])
@php
    // Alat berikutnya yang menerima PDF, untuk meneruskan hasil tanpa unduh dan unggah ulang.
    $conversions = app(\App\Conversion\ConversionService::class);
    $nextTools = app(\App\Conversion\ToolCatalog::class)->all()
        ->filter(fn (\App\Conversion\Tool $tool): bool => in_array('pdf', $tool->inputFormats, true) && ! $tool->usesUrl && $conversions->isAvailable($tool))
        ->groupBy(fn (\App\Conversion\Tool $tool): string => $tool->category->label());
@endphp
{{--
    UI-04: hasil dan unduhan. Kiri: pratinjau semua halaman yang cukup digulir.
    Kanan: panel unduh (status, berkas, laporan, dan tombol). Dipakai oleh
    JavaScript (tanpa nilai awal) dan oleh halaman hasil tanpa JavaScript.
--}}
<section {{ $attributes->merge(['class' => 'result-layout']) }} aria-labelledby="hasil-judul">
    <section class="panel result-preview" data-result-preview aria-labelledby="pratinjau-judul" hidden>
        <div class="result-preview-head">
            <h3 id="pratinjau-judul">Pratinjau hasil</h3>
            <span class="muted" data-preview-count aria-live="polite"></span>
        </div>
        <div class="report-wide" data-report-wide hidden></div>
        <div class="preview-pages" data-preview-pages></div>
        <pre class="preview-text" data-preview-text tabindex="0" aria-label="Isi berkas hasil" hidden></pre>
        <p class="field-hint" data-preview-note>Gulir untuk memeriksa semua halaman. Pratinjau tidak menghapus berkas, dan tautan unduh tetap berlaku satu kali.</p>
    </section>

    <aside class="panel result-card" aria-label="Unduhan">
        <span class="result-badge"><x-icon name="check-circle" size="32" /></span>
        <div>
            <h2 id="hasil-judul" tabindex="-1" data-result-heading>Berhasil, hasil siap diunduh</h2>
            <p class="muted" style="margin-top: 6px;" data-result-note>{{ __('konvertin.msg05') }}</p>
        </div>

        <div class="result-file">
            <span class="file-thumb"><x-icon name="page" size="22" /></span>
            <div style="min-width: 0;">
                <p class="file-name" data-result-name>{{ $filename }}</p>
                <p class="file-meta"><span data-result-size>{{ $size }}</span><span data-result-pages></span></p>
            </div>
        </div>

        <div data-report class="report" hidden></div>
        <div data-report-extra hidden style="width: 100%;"></div>

        @if ($nextTools->isNotEmpty())
            <div class="handoff" data-handoff hidden>
                <label for="lanjut-alat" class="field-label">Lanjutkan dengan alat lain</label>
                <div class="handoff-row">
                    <select class="select" id="lanjut-alat" data-handoff-select>
                        @foreach ($nextTools as $category => $tools)
                            <optgroup label="{{ $category }}">
                                @foreach ($tools as $nextTool)
                                    <option value="{{ route('tools.show', $nextTool->slug) }}" data-slug="{{ $nextTool->slug }}">{{ $nextTool->name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <button type="button" class="btn btn-secondary" data-handoff-go>Lanjutkan<x-icon name="chevron-right" size="16" /></button>
                </div>
                <p class="field-hint" data-handoff-hint>Hasil ini dipindahkan otomatis ke alat berikutnya, jadi tidak perlu diunduh lalu diunggah lagi. Tautan unduh di sini tetap berlaku.</p>
            </div>
        @endif

        <div class="result-actions">
            <a class="btn btn-primary btn-block" href="{{ $downloadUrl ?? '#' }}" data-result-link><x-icon name="download" size="20" />Unduh hasil</a>
            @if ($resetHref)
                <a class="btn btn-secondary btn-block" href="{{ $resetHref }}"><x-icon name="refresh" size="18" />Proses ulang</a>
            @else
                <button type="button" class="btn btn-secondary btn-block" data-reset><x-icon name="refresh" size="18" />Proses ulang</button>
            @endif
            <a class="btn btn-ghost btn-block" href="{{ route('home') }}#semua-alat">Kembali ke daftar alat</a>
        </div>
        <p class="muted result-expiry" data-result-expiry>Bila tidak diunduh, berkas terhapus otomatis setelah {{ $expiresInMinutes }} menit dan tautan ini tidak berlaku lagi.</p>
    </aside>
</section>
