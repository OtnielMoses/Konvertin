{{-- Assignment Pack Builder: langkah, papan bagian dokumen, dan pratinjau susunan. --}}
@php
    $packSteps = [1 => 'Dokumen', 2 => 'Susun', 3 => 'Halaman', 4 => 'Nomor', 5 => 'Optimasi', 6 => 'Pratinjau'];
    $packSections = [
        'cover' => ['Sampul', 'Halaman judul. Biasanya tanpa nomor.'],
        'preliminary' => ['Bagian awal', 'Lembar pengesahan, kata pengantar, daftar isi. Biasanya angka Romawi.'],
        'main' => ['Isi utama', 'BAB I dan seterusnya. Biasanya angka Arab.'],
        'appendix' => ['Lampiran', 'Screenshot, data, dan dokumen pendukung.'],
    ];
@endphp
<div class="pack" data-pack>
    <nav aria-label="Langkah penyusunan paket">
        <ol class="stepper">
            @foreach ($packSteps as $number => $label)
                <li>
                    <button type="button" class="step" data-step-go="{{ $number }}" @if ($number === 1) aria-current="step" @endif>
                        <span class="step-num">{{ $number }}</span>
                        <span class="step-label">{{ $label }}</span>
                    </button>
                </li>
            @endforeach
        </ol>
    </nav>

    <div class="pack-board" data-pack-board hidden>
        <div class="pack-board-head">
            <div>
                <h2>Susunan dokumen</h2>
                <p class="muted" data-pack-count></p>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" data-pack-add><x-icon name="plus" size="16" />Tambah dokumen</button>
        </div>
        <p class="field-hint">Seret kartu untuk mengubah urutan atau memindahkannya ke bagian lain. Bagian yang tidak Anda perlukan boleh dibiarkan kosong.</p>

        @foreach ($packSections as $key => [$label, $hint])
            <section class="lane" data-lane="{{ $key }}" aria-labelledby="lane-{{ $key }}">
                <header class="lane-head">
                    <h3 id="lane-{{ $key }}" data-lane-title>{{ $label }}</h3>
                    <span class="lane-meta" data-lane-meta></span>
                    <p class="lane-hint" data-lane-hint>{{ $hint }}</p>
                </header>
                <ol class="file-grid lane-grid" data-lane-list="{{ $key }}" aria-labelledby="lane-{{ $key }}"></ol>
            </section>
        @endforeach
        <p class="visually-hidden" aria-live="polite" data-order-status></p>
    </div>

    <div class="pack-preview" data-pack-preview hidden>
        <div class="pack-board-head">
            <div>
                <h2>Pratinjau susunan</h2>
                <p class="muted" data-preview-summary></p>
            </div>
        </div>
        <ol class="pack-outline" data-pack-outline></ol>
        <p class="field-hint" data-preview-note></p>
        <div class="page-grid pack-pages" data-pack-pages></div>
    </div>
</div>
