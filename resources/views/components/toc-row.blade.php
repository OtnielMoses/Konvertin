@props(['tool', 'available' => true])
<li>
    <a class="toc-row" href="{{ route('tools.show', $tool->slug) }}">
        <span class="toc-name">{{ $tool->name }}</span>
        <span class="toc-leader" aria-hidden="true"></span>
        <span class="toc-formats token">{{ $tool->inputLabel }} › {{ $tool->outputLabel }}</span>
        <span class="toc-desc">
            {{ $tool->summary }}
            @unless ($available)
                <span class="status-note">Belum disiapkan</span>
            @endunless
        </span>
    </a>
</li>
