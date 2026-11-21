@props(['tool'])
<div class="format-pair" role="img" aria-label="Dari {{ $tool->inputLabel }} ke {{ $tool->outputLabel }}">
    <span class="sheet"><span class="token">{{ $tool->inputLabel }}</span></span>
    <svg viewBox="0 0 28 28" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path d="M4 14h18M16 8l6 6-6 6" stroke-linecap="round" stroke-linejoin="round" />
    </svg>
    <span class="sheet is-target"><span class="token">{{ $tool->outputLabel }}</span></span>
</div>
