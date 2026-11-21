<x-layout title="Sesi berakhir">
    <div class="container message-page">
        <h1>Sesi halaman berakhir</h1>
        <p>{{ __('konvertin.page_expired') }}</p>
        <div class="result-actions">
            <a class="btn btn-primary" href="{{ url()->previous() !== url()->current() ? url()->previous() : route('home') }}">Muat ulang halaman sebelumnya</a>
        </div>
    </div>
</x-layout>
