{{-- Halaman hasil untuk peramban tanpa JavaScript (UI-04). --}}
<x-layout :title="$tool->name.' selesai'">
    <h1 class="visually-hidden">{{ $tool->name }}: hasil</h1>

    <div class="container" style="padding-block: 28px;">
        <x-result-panel
            :download-url="$download_url"
            :filename="$filename"
            :size="$size"
            :expires-in-minutes="$expires_in_minutes"
            :reset-href="route('tools.show', $tool->slug)"
        />
    </div>
</x-layout>
