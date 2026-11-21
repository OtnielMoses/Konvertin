@props(['kind' => 'circle', 'color' => 'yellow', 'size' => 240, 'rotate' => 0, 'float' => true, 'delay' => 0])
@php
    /*
     * Bentuk kertas-guntingan (Desain2.md): warna rata, sudut tegas, tanpa bayangan,
     * selalu dekoratif. Posisi diberikan lewat atribut style dari pemanggil.
     */
    $fills = [
        'red' => '#ff4141',
        'yellow' => '#ffda00',
        'sky' => '#91d8ec',
        'pink' => '#ffbac4',
        'green' => '#16ab59',
        'sun' => '#ffe54d',
        'frost' => '#e1edff',
    ];
    $fill = $fills[$color] ?? $fills['yellow'];
    $height = $kind === 'semicircle' ? $size / 2 : $size;
@endphp
<span {{ $attributes->class(['shape', 'shape-'.$kind, 'is-floating' => $float]) }}
    style="--shape-w: {{ $size }}px; --shape-h: {{ $height }}px; --shape-rot: {{ $rotate }}deg; --shape-delay: {{ $delay }}s; {{ $attributes->get('style') }}"
    aria-hidden="true">
    @switch($kind)
        @case('pentagon')
            <svg viewBox="0 0 100 100" focusable="false"><polygon points="50,2 98,37 80,96 20,96 2,37" fill="{{ $fill }}" /></svg>
            @break
        @case('triangle')
            <svg viewBox="0 0 100 100" focusable="false"><polygon points="50,3 98,95 2,95" fill="{{ $fill }}" /></svg>
            @break
        @case('semicircle')
            <svg viewBox="0 0 100 50" focusable="false"><path d="M0 50A50 50 0 0 1 100 50Z" fill="{{ $fill }}" /></svg>
            @break
        @case('star')
            <svg viewBox="0 0 100 100" focusable="false"><polygon points="50,2 61,37 98,37 68,59 79,95 50,73 21,95 32,59 2,37 39,37" fill="{{ $fill }}" /></svg>
            @break
        @case('arch')
            <svg viewBox="0 0 100 100" focusable="false"><path d="M0 100V50A50 50 0 0 1 100 50V100Z" fill="{{ $fill }}" /></svg>
            @break
        @default
            <svg viewBox="0 0 100 100" focusable="false"><circle cx="50" cy="50" r="50" fill="{{ $fill }}" /></svg>
    @endswitch
</span>
