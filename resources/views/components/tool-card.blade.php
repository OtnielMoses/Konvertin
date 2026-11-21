@props(['tool', 'available' => true, 'headingLevel' => 'h4', 'revealIndex' => null])
{{--
    Alat yang mesinnya belum disiapkan dirender sebagai kartu "mati": bukan
    tautan, jadi tidak bisa diklik atau dibuka, dengan gembok sebagai penanda.
--}}
@php($tag = $available ? 'a' : 'div')
<{{ $tag }}
    @class(['tool-card', 'is-unavailable' => ! $available])
    @if ($available) href="{{ route('tools.show', $tool->slug) }}" @else aria-disabled="true" title="Belum tersedia" @endif
    data-tool-card
    data-search="{{ $tool->searchText() }}"
    data-category="{{ $tool->category->value }}"
    @if ($revealIndex !== null) data-reveal style="--d: {{ ($revealIndex % 4) * 70 }}ms" @endif
>
    <x-tool-object :tool="$tool" :index="crc32($tool->slug) % 97" />
    <span class="tool-icon"><x-icon :name="$tool->icon" size="20" /></span>
    <div class="tool-card-copy">
        <{{ $headingLevel }}>{{ $tool->name }}</{{ $headingLevel }}>
        <p>{{ $tool->summary }}</p>
    </div>
    @if ($available)
        <x-icon name="chevron-right" size="16" class="tool-card-arrow" />
    @else
        <span class="tool-card-lock" aria-hidden="true"><x-icon name="lock" size="16" /></span>
        <span class="visually-hidden">Belum disiapkan</span>
    @endif
</{{ $tag }}>
