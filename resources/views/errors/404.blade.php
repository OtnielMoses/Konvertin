{{-- 4.1/REQ-6: halaman tidak ditemukan beserta tautan ke Beranda. --}}
<x-layout title="Halaman tidak ditemukan">
    <div class="container message-page">
        <h1>Halaman tidak ditemukan</h1>
        <p>Alamat yang Anda buka tidak dikenali. Mungkin ada salah ketik, atau tautannya sudah tidak berlaku.</p>
        <div class="result-actions">
            <a class="btn btn-primary" href="{{ route('home') }}">Buka Beranda</a>
        </div>
    </div>
</x-layout>
