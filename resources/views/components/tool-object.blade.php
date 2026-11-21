@props(['tool', 'index' => 0])
@php
    /*
     * Objek "3D" dekoratif di dalam kartu alat, digambar dengan CSS: tumpukan
     * lembar miring yang melayang. Bentuk dan warnanya mengikuti fungsi alat.
     */
    $kinds = [
        'jpg-to-pdf' => ['photo', '#0f9d8a', 'JPG'],
        'pdf-to-jpg' => ['photo', '#0f9d8a', 'JPG'],
        'scan-to-pdf' => ['photo', '#0ea5e9', 'SCAN'],
        'word-to-pdf' => ['doc', '#2b579a', 'DOC'],
        'pdf-to-word' => ['doc', '#2b579a', 'DOC'],
        'powerpoint-to-pdf' => ['slides', '#d24726', 'PPT'],
        'pdf-to-powerpoint' => ['slides', '#d24726', 'PPT'],
        'excel-to-pdf' => ['sheet', '#217346', 'XLS'],
        'pdf-to-excel' => ['sheet', '#217346', 'XLS'],
        'html-to-pdf' => ['globe', '#6366f1', 'WEB'],
        'pdf-to-markdown' => ['markdown', '#1f2937', 'MD'],
        'pdf-to-pdfa' => ['doc', '#7c3aed', 'PDF/A'],
    ];
    $categoryAccents = [
        'convert' => '#0070e0',
        'organize' => '#0070e0',
        'optimize' => '#16a34a',
        'edit' => '#8b5cf6',
        'security' => '#e44835',
        'forms' => '#0d9488',
        'translate' => '#4f46e5',
    ];
    [$kind, $accent, $badge] = $kinds[$tool->slug] ?? ['pdf', $categoryAccents[$tool->category->value] ?? '#0070e0', null];
    // Different durations and delays so the cards never bob in unison.
    $delay = -1 * (($index * 1.37) % 6);
    $duration = 5 + ($index % 3);
@endphp
<span class="tool-object kind-{{ $kind }}" style="--accent: {{ $accent }}; --float-delay: {{ $delay }}s; --float-duration: {{ $duration }}s" aria-hidden="true">
    <span class="to-float">
        <span class="to-scene">
            @if ($kind === 'globe')
                <span class="to-globe"><i></i><i></i><i></i></span>
                <span class="to-ring"></span>
            @else
                <span class="to-layer to-back"></span>
                <span class="to-layer to-mid"></span>
                <span class="to-layer to-front">
                    @switch($kind)
                        @case('photo')
                            <span class="to-photo"><i class="to-sun"></i><i class="to-hill"></i><i class="to-hill is-near"></i></span>
                            @break
                        @case('slides')
                            <span class="to-title"></span>
                            <span class="to-bars"><i></i><i></i><i></i><i></i></span>
                            @break
                        @case('sheet')
                            <span class="to-cells">@for ($cell = 0; $cell < 12; $cell++)<i></i>@endfor</span>
                            @break
                        @case('markdown')
                            <span class="to-md">#</span>
                            <i class="to-line"></i><i class="to-line is-short"></i><i class="to-line"></i>
                            @break
                        @case('doc')
                            <span class="to-chip"><x-icon :name="$tool->icon" size="12" /></span>
                            <i class="to-line"></i><i class="to-line"></i><i class="to-line is-short"></i><i class="to-line"></i>
                            @break
                        @default
                            <span class="to-symbol"><x-icon :name="$tool->icon" size="22" /></span>
                            <i class="to-line"></i><i class="to-line is-short"></i>
                    @endswitch
                    @if ($badge)
                        <span class="to-badge">{{ $badge }}</span>
                    @endif
                </span>
            @endif
        </span>
    </span>
</span>
