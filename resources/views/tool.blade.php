@php
    // Teks batas: foto (JPG/PNG) punya batas sendiri, dokumen memakai batas per berkas.
    $imageFormats = array_intersect($tool->inputFormats, ['jpg', 'png']);
    $fileMbText = rtrim(rtrim(number_format($maxFileBytes / 1048576, 1, ',', ''), '0'), ',').' MB';
    $imageMbText = rtrim(rtrim(number_format($maxImageBytes / 1048576, 1, ',', ''), '0'), ',').' MB';
    $limitText = match (true) {
        $imageFormats === [] => 'maks. '.$fileMbText.' per berkas',
        count($imageFormats) === count($tool->inputFormats) => 'maks. '.$imageMbText.' per foto',
        default => 'maks. '.$fileMbText.' per dokumen, '.$imageMbText.' per foto',
    };
    $messages = [
        'msg01' => __('konvertin.msg01'),
        'msg02' => __('konvertin.msg02'),
        'msg03' => __('konvertin.msg03'),
        'msg04' => __('konvertin.msg04'),
        'msg06' => __('konvertin.msg06'),
        'mergeMinimum' => __('konvertin.merge_minimum'),
        'filesMinimum' => __('konvertin.files_minimum', ['min' => $tool->minFiles]),
        'filesExact' => __('konvertin.files_exact', ['count' => $maxFiles]),
        'urlRequired' => __('konvertin.url_required'),
        'urlInvalid' => __('konvertin.url_invalid'),
        'tooManyRequests' => __('konvertin.too_many_requests'),
        'pageExpired' => __('konvertin.page_expired'),
        'network' => 'Koneksi ke server terputus. Periksa koneksi internet Anda, lalu coba lagi.',
        'fileTooLarge' => 'Berkas :name berukuran :size, melebihi batas :limit.',
        'requestTooLarge' => 'Total ukuran semua berkas (:size) melebihi batas satu kali unggah (:limit). Kurangi jumlah berkas, lalu proses sisanya secara terpisah.',
    ];
    $config = [
        'slug' => $tool->slug,
        'mode' => $tool->mode,
        'multiple' => $tool->acceptsMultiple,
        'minFiles' => $tool->minFiles,
        'maxFiles' => $maxFiles,
        'maxBytes' => $maxFileBytes,
        'maxImageBytes' => $maxImageBytes,
        'limitText' => $limitText,
        'maxRequestBytes' => $maxRequestBytes,
        'maxPages' => $maxPages,
        'extensions' => $tool->clientExtensions(),
        'formatsText' => $tool->inputFormatsText(),
        'usesUrl' => $tool->usesUrl,
        'isMerge' => $tool->isMerge(),
        'name' => $tool->name,
        'ttlMinutes' => $ttlMinutes,
        'queue' => $tool->queue,
        'wordReady' => $wordReady,
        'messages' => $messages,
    ];
    $name = fn (string $field): string => 'options['.$field.']';
    $id = fn (string $field): string => 'opt-'.$field;
    $serverError = $errors->first();
    $documentsHeading = match (true) {
        $tool->isMerge() => 'Urutan penggabungan',
        $tool->slug === 'compare-pdf' => 'Dua versi dokumen',
        $maxFiles > 1 => 'Dokumen',
        default => 'Dokumen',
    };
@endphp

<x-layout :title="$tool->name" :description="$tool->summary" body-class="page-tool" :footer="false">

    <form
        class="container workspace"
        method="POST"
        action="{{ route('tools.convert', $tool->slug) }}"
        enctype="multipart/form-data"
        novalidate
        data-workbench
        data-bundle-url="{{ route('download.bundle') }}"
        data-config='@json($config)'
    >
        @csrf

        <div class="workspace-main">
            {{-- Area dokumen --}}
            <section class="panel workspace-panel" data-region="input" aria-labelledby="judul-masukan">
                @if ($tool->usesUrl)
                    <h2 class="panel-title" id="judul-masukan">Halaman web</h2>
                    <div class="field url-field">
                        <label for="url">Alamat halaman web</label>
                        <input type="url" id="url" name="url" class="input input-lg" inputmode="url" autocomplete="url"
                            placeholder="https://contoh.ac.id/pengumuman" value="{{ old('url') }}" aria-describedby="url-hint" data-url-input>
                        <p class="field-hint" id="url-hint">Alamat lengkap yang diawali http:// atau https://. Halaman harus dapat dibuka tanpa login.</p>
                    </div>
                @else
                    <h2 class="visually-hidden" id="judul-masukan">Berkas masukan</h2>
                    <div class="dropzone" data-dropzone>
                        <span class="upload-icon"><x-icon name="upload" size="28" /></span>
                        <div>
                            <p class="dz-title" data-dz-title>
                                @if ($tool->mode === 'scan')
                                    Seret foto ke sini, atau ambil dengan kamera
                                @elseif ($tool->mode === 'pack')
                                    Seret sampul, dokumen Word, gambar, dan lampiran ke sini
                                @else
                                    Seret {{ $maxFiles > 1 ? 'berkas-berkas' : 'berkas' }} {{ $tool->inputFormatsText() }} ke sini
                                @endif
                            </p>
                            <p class="dz-limit">{{ $tool->inputFormatsText() }} · {{ $limitText }}{{ $maxFiles > 1 ? ' · hingga '.$maxFiles.' berkas' : '' }}</p>
                        </div>
                        <label for="files" class="visually-hidden">Pilih berkas {{ $tool->inputFormatsText() }}</label>
                        <input type="file" id="files" name="files[]" accept="{{ $tool->accept }}" @if ($maxFiles > 1) multiple @endif data-file-input>
                        <span class="btn btn-secondary js-only" aria-hidden="true"><x-icon name="plus" size="16" />Pilih berkas</span>
                    </div>

                    <div class="file-panel" data-file-panel hidden>
                        <div class="file-list-head">
                            <h2 id="daftar-berkas">{{ $documentsHeading }}</h2>
                            <span class="muted" data-file-count></span>
                            @if ($maxFiles > 2)
                                <button type="button" class="btn btn-ghost btn-sm file-sort" data-sort-names><x-icon name="arrow-down" size="14" />Urutkan nama</button>
                            @endif
                        </div>
                        @if ($maxFiles > 1)
                            <p class="field-hint file-order-hint">Seret kartu untuk mengubah urutan, atau pakai tombol panah di setiap kartu.</p>
                        @endif
                        <p class="empty-files" data-file-empty>Belum ada berkas dipilih.</p>
                        <ol class="file-grid" aria-labelledby="daftar-berkas" data-file-list></ol>
                        <p class="visually-hidden" aria-live="polite" data-order-status></p>
                    </div>
                @endif

                @includeIf('tools.modes.'.$tool->mode, ['tool' => $tool])

                @if ($tool->usesPreview() || in_array($tool->mode, ['scan', 'workflow', 'pack', 'compress'], true))
                    <noscript>
                        <p class="alert alert-warning">Alat ini membutuhkan JavaScript untuk pratinjau dan pengaturan interaktif.</p>
                    </noscript>
                @endif
            </section>

            {{-- Proses --}}
            <section class="panel progress-card" data-region="progress" aria-labelledby="proses-judul" hidden>
                <div class="progress-visual" aria-hidden="true">
                    <span class="pv-sheet pv-back"></span>
                    <span class="pv-sheet pv-front"><i></i><i></i><i></i><i></i><i></i></span>
                    <span class="pv-scan"></span>
                </div>
                <h2 id="proses-judul" data-progress-label>Mengunggah berkas</h2>
                <p class="progress-activity" data-progress-activity aria-live="polite">Menyiapkan unggahan</p>
                <div class="progress-track" data-progress-track role="progressbar" aria-labelledby="proses-judul" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span></span></div>
                <div class="progress-meta">
                    <span data-progress-value>0%</span>
                    <span data-progress-elapsed>0:00</span>
                </div>
                <ol class="progress-steps">
                    <li data-progress-step="upload"><span class="progress-step-dot"></span>Unggah</li>
                    <li data-progress-step="process"><span class="progress-step-dot"></span>Proses</li>
                    <li data-progress-step="finish"><span class="progress-step-dot"></span>Selesai</li>
                </ol>
                <p class="muted progress-hint">Jangan tutup halaman ini sampai proses selesai.</p>
            </section>

            {{-- Antrean: banyak berkas, satu permintaan per berkas --}}
            @if ($tool->queue)
                <section class="panel queue-panel" data-region="queue" aria-labelledby="antrean-judul" hidden>
                    <div class="queue-head">
                        <h2 id="antrean-judul" tabindex="-1" data-queue-heading>Antrean proses</h2>
                        <p class="muted" data-queue-summary aria-live="polite"></p>
                    </div>
                    <dl class="report queue-stats" data-queue-stats></dl>
                    <ol class="queue-list" data-queue-list></ol>
                    <div class="queue-footer">
                        <button type="button" class="btn btn-primary" data-queue-zip disabled><x-icon name="download" size="18" /><span>Unduh semua hasil (ZIP)</span></button>
                        <button type="button" class="btn btn-secondary" data-queue-retry hidden><x-icon name="refresh" size="16" />Ulangi yang gagal</button>
                        <button type="button" class="btn btn-ghost" data-queue-cancel hidden>Batalkan yang menunggu</button>
                        <button type="button" class="btn btn-ghost" data-queue-reset>Proses berkas lain</button>
                    </div>
                    <p class="field-hint" data-queue-notice aria-live="polite"></p>
                    <p class="muted queue-policy">Berkas diproses satu per satu agar server tidak kelebihan beban, dan satu berkas yang gagal tidak menghentikan yang lain. Proses yang sedang berjalan tidak dapat dihentikan dari peramban; yang masih menunggu bisa dibatalkan. Setiap hasil bisa diunduh satu kali, dan terhapus otomatis setelah {{ $ttlMinutes }} menit bila tidak diunduh.</p>
                </section>
            @endif

            {{-- Hasil --}}
            <x-result-panel data-region="result" hidden :expires-in-minutes="$ttlMinutes" />

            <section class="panel result-card" data-region="downloaded" aria-labelledby="unduh-judul" hidden>
                <span class="result-badge"><x-icon name="download" size="28" /></span>
                <h2 id="unduh-judul" tabindex="-1" data-downloaded-heading>Unduhan dimulai</h2>
                <p class="muted">Berkas hasil sedang dikirim ke peramban Anda. Server menghapus berkas masukan dan hasil begitu selesai mengirimkannya, jadi tautan unduh tadi tidak berlaku lagi. Bila unduhan gagal atau terputus, proses lagi dengan berkas yang sama; berkasnya masih ada di halaman ini.</p>
                <div class="result-actions">
                    <button type="button" class="btn btn-primary" data-rerun><x-icon name="refresh" size="16" />Proses lagi dengan berkas yang sama</button>
                    <button type="button" class="btn btn-secondary" data-reset><x-icon name="refresh" size="16" />{{ $tool->mode === 'pack' ? 'Buat paket baru' : 'Proses berkas lain' }}</button>
                    <a class="btn btn-ghost" href="{{ route('home') }}#semua-alat">Kembali ke daftar alat</a>
                </div>
            </section>
        </div>

        {{-- Panel opsi --}}
        <aside class="settings" aria-labelledby="judul-pengaturan">
            <section class="panel settings-body" data-settings>
                <header class="options-head">
                    <div class="options-title">
                        <span class="tool-icon"><x-icon :name="$tool->icon" size="18" /></span>
                        <div>
                            <p class="eyebrow">{{ $tool->category->label() }}</p>
                            <h1 class="panel-title" id="judul-pengaturan">{{ $tool->name }}</h1>
                        </div>
                    </div>
                    <p class="options-summary">{{ $tool->summary }}</p>
                    <p class="processing-badge {{ $processingNote ? 'is-external' : '' }}" data-processing-badge><x-icon name="{{ $processingNote ? 'globe' : 'lock' }}" size="13" /><span>{{ $processingNote ? 'Memakai layanan eksternal' : 'Diproses di server Konvertin' }}</span></p>
                    @if ($processingNote)
                        <p class="options-note"><x-icon name="info" size="15" /><span>{{ $processingNote }}</span></p>
                    @endif
                </header>

                @unless ($available)
                    <div class="alert alert-warning" role="status">
                        <x-icon name="info" size="18" />
                        <div><strong>Belum disiapkan di server ini</strong>{{ __('konvertin.failure.engine_unavailable') }}</div>
                    </div>
                @endunless

                <div class="options-body">
                    @if ($tool->local)
                        <fieldset class="segmented segmented-stack location-choice">
                            <legend>Lokasi pemrosesan</legend>
                            <label><input type="radio" name="processing_location" value="browser" checked>Di peramban ini<small>Berkas tidak diunggah ke server. Untuk total berkas sampai 150 MB.</small></label>
                            <label><input type="radio" name="processing_location" value="server">Di server Konvertin<small>Untuk berkas besar. Berkas dihapus setelah diunduh atau setelah {{ $ttlMinutes }} menit.</small></label>
                        </fieldset>
                    @endif
                    @includeIf('tools.options.'.$tool->slug, ['name' => $name, 'id' => $id, 'tool' => $tool])

                    @foreach ($tool->auxFiles as $key => $aux)
                        <div class="field aux-upload" data-aux="{{ $key }}" @if ($key === 'image') data-show-when="type:image" @endif>
                            <label for="aux-{{ $key }}">{{ $aux['label'] }} @if ($key !== 'image')<span class="muted">(opsional)</span>@endif</label>
                            <input type="file" id="aux-{{ $key }}" name="aux[{{ $key }}]" accept="{{ $aux['accept'] }}" class="input" data-aux-input="{{ $key }}">
                        </div>
                    @endforeach
                </div>

                <div data-error-region aria-live="assertive">
                    @if ($serverError)
                        <div class="alert alert-error" role="alert"><x-icon name="alert" size="18" /><p>{{ $serverError }}</p></div>
                    @endif
                </div>

                <div class="action-area">
                    @unless ($tool->usesUrl)
                        <p class="action-summary" data-action-summary aria-live="polite">Belum ada berkas.</p>
                    @endunless
                    <button type="submit" class="btn btn-primary btn-block" data-submit @disabled(! $available)>{{ $tool->actionLabel }}</button>
                    <p class="hint"><x-icon name="clock" size="14" /><span data-processing-hint>{{ $processingNote ? '' : 'Diproses di server Konvertin, tidak dikirim ke layanan lain. ' }}Berkas dihapus setelah diunduh, atau otomatis setelah {{ $ttlMinutes }} menit.</span></p>
                </div>
            </section>

        </aside>
    </form>

</x-layout>
