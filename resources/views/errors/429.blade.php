<x-layout title="Terlalu banyak permintaan">
    <div class="container message-page">
        <h1>Terlalu banyak permintaan</h1>
        <p>{{ __('konvertin.too_many_requests') }}</p>
        <div class="result-actions">
            <a class="btn btn-primary" href="{{ route('home') }}">Kembali ke daftar alat</a>
        </div>
    </div>
</x-layout>
