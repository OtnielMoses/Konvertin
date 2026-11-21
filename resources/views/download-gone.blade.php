{{-- UC-02 sampai UC-13 EP-2: berkas hasil sudah tidak tersedia (MSG-07). --}}
<x-layout title="Berkas sudah dihapus">
    <div class="container message-page">
        <h1>Berkas sudah dihapus</h1>
        <p>{{ __('konvertin.msg07') }}</p>
        <p class="muted">Setiap hasil hanya dapat diunduh sekali, dan hasil yang tidak diunduh terhapus otomatis setelah {{ config('konvertin.ttl_minutes') }} menit.</p>
        <p class="muted">Bila unduhan sebelumnya gagal atau terputus, buka lagi alatnya dan proses ulang berkas yang sama.</p>
        <div class="result-actions">
            @if (! empty($tool))
                <a class="btn btn-primary" href="{{ route('tools.show', $tool->slug) }}">Buka lagi {{ $tool->name }}</a>
                <a class="btn btn-secondary" href="{{ route('home') }}">Kembali ke daftar alat</a>
            @else
                <a class="btn btn-primary" href="{{ route('home') }}">Kembali ke daftar alat</a>
            @endif
        </div>
    </div>
</x-layout>
