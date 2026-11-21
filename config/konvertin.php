<?php

use App\Conversion\Converters\AiTranslator;
use App\Conversion\Converters\AssignmentPackConverter;
use App\Conversion\Converters\BrowserPdfConverter;
use App\Conversion\Converters\GhostscriptPdfaConverter;
use App\Conversion\Converters\GotenbergConverter;
use App\Conversion\Converters\LibreOfficeConverter;
use App\Conversion\Converters\MicrosoftOfficeConverter;
use App\Conversion\Converters\PythonConverter;

/*
 * Konfigurasi Sistem konversi dokumen.
 *
 * Nilai-nilai di bawah ini mewakili keputusan yang pada SRS masih berstatus
 * TBD. Nilai bawaan dipilih sebagai titik awal yang aman dan dapat diubah
 * melalui .env tanpa menyentuh kode.
 */

return [

    /*
     * Batas ukuran per berkas dalam kilobyte (TBD-04). Nilai bawaan 50 MB
     * mengikuti ilustrasi yang pernah disebut pada diskusi proyek.
     */
    'max_file_kb' => (int) env('KONVERTIN_MAX_FILE_KB', 51200),

    /*
     * Batas ukuran per foto (JPG/PNG) dalam kilobyte. Foto kamera beresolusi tinggi
     * tetap muat, sementara dokumen (PDF, Word, dan lainnya) memakai max_file_kb.
     */
    'max_image_kb' => (int) env('KONVERTIN_MAX_IMAGE_KB', 10240),

    /*
     * Batas jumlah berkas per proses untuk fitur yang menerima banyak berkas,
     * misalnya JPG to PDF, Merge PDF, dan Assignment Pack (TBD-05).
     */
    'max_files' => (int) env('KONVERTIN_MAX_FILES', 30),

    /*
     * Lokasi penyimpanan sementara (TBD-11) dan batas waktu berkas yang tidak
     * diunduh sebelum dihapus otomatis, dalam menit (TBD-07).
     */
    'workspace_path' => env('KONVERTIN_WORKSPACE_PATH', storage_path('app/konvertin')),
    'ttl_minutes' => (int) env('KONVERTIN_TTL_MINUTES', 30),

    /*
     * Batas waktu satu proses konversi dalam detik (NFR-PERF-3). Proses yang
     * melampaui batas ini diperlakukan sebagai kegagalan.
     */
    'process_timeout' => (int) env('KONVERTIN_PROCESS_TIMEOUT', 180),

    /*
     * Batas laju permintaan konversi per alamat klien per menit (NFR-SEC-8, TBD-06).
     */
    'rate_limit_per_minute' => (int) env('KONVERTIN_RATE_LIMIT', 20),

    /*
     * Paksa HTTPS di luar lingkungan lokal (NFR-SEC-1).
     */
    'force_https' => (bool) env('KONVERTIN_FORCE_HTTPS', env('APP_ENV') === 'production'),

    /*
     * Reverse proxy atau load balancer di depan aplikasi (Nginx, Render, Railway,
     * Fly.io, Cloudflare). Tanpa ini, HTTPS yang diakhiri di proxy terbaca sebagai
     * HTTP sehingga pengalihan HTTPS berulang, dan batas laju menghitung semua
     * pengguna sebagai satu alamat proxy. Isi '*' untuk mempercayai proxy mana pun
     * (aman bila aplikasi hanya bisa dijangkau lewat proxy), atau daftar alamat
     * dipisah koma. Kosongkan bila aplikasi diakses langsung.
     */
    'trusted_proxies' => env('KONVERTIN_TRUSTED_PROXIES'),

    /*
     * Lokasi mesin konversi (TBD-08). Bila kosong, Sistem mencari lokasi umum
     * pada sistem operasi server.
     */
    'engines' => [
        'python' => env('KONVERTIN_PYTHON', 'python'),
        'python_script' => resource_path('engines/konvertin_engine.py'),
        'libreoffice' => env('KONVERTIN_LIBREOFFICE'),
        'browser' => env('KONVERTIN_BROWSER'),
        'ghostscript' => env('KONVERTIN_GHOSTSCRIPT'),
        'pdfa_icc_profile' => env('KONVERTIN_PDFA_ICC'),

        /*
         * Berkas sertifikat CA untuk pemeriksaan alamat HTML to PDF. Kosongkan
         * bila PHP di server sudah memiliki curl.cainfo yang benar.
         */
        'ca_bundle' => env('KONVERTIN_CA_BUNDLE'),

        /*
         * Pakai Microsoft Office yang terpasang (Windows, otomasi COM) untuk
         * Word/PowerPoint/Excel ke PDF. Untuk pengembangan lokal saja.
         */
        'microsoft_office' => (bool) env('KONVERTIN_MICROSOFT_OFFICE', false),

        /*
         * Layanan Gotenberg milik sendiri (gotenberg/gotenberg:9.8.0-libreoffice)
         * untuk Office ke PDF dan PDF ke PDF/A, sehingga LibreOffice tidak perlu
         * dipasang di mesin yang menjalankan Konvertin. Alamat wajib HTTPS,
         * kecuali localhost. Dilindungi basic auth.
         */
        'gotenberg' => [
            'url' => env('KONVERTIN_GOTENBERG_URL'),
            'username' => env('KONVERTIN_GOTENBERG_USERNAME'),
            'password' => env('KONVERTIN_GOTENBERG_PASSWORD'),
        ],
    ],

    /*
     * Batas jumlah halaman per dokumen untuk seluruh alat (mencegah
     * pemakaian sumber daya berlebihan).
     */
    'max_pages' => (int) env('KONVERTIN_MAX_PAGES', 500),

    /*
     * Pemetaan alat ke mesin (NFR-QUA-4). Nilai berupa satu kelas, atau
     * daftar kandidat: kandidat pertama yang tersedia di server dipakai.
     */
    'converters' => [
        'jpg-to-pdf' => PythonConverter::class,
        'word-to-pdf' => $officeEngines = [MicrosoftOfficeConverter::class, GotenbergConverter::class, LibreOfficeConverter::class],
        'powerpoint-to-pdf' => $officeEngines,
        'excel-to-pdf' => $officeEngines,
        'html-to-pdf' => BrowserPdfConverter::class,
        'preflight-pdf' => PythonConverter::class,
        'bookmark-pdf' => PythonConverter::class,
        'scan-to-pdf' => PythonConverter::class,
        'pdf-to-jpg' => PythonConverter::class,
        'pdf-to-word' => PythonConverter::class,
        'pdf-to-powerpoint' => PythonConverter::class,
        'pdf-to-excel' => PythonConverter::class,
        'pdf-to-pdfa' => [GotenbergConverter::class, GhostscriptPdfaConverter::class],
        'pdf-to-markdown' => PythonConverter::class,
        'merge-pdf' => PythonConverter::class,
        'split-pdf' => PythonConverter::class,
        'organize-pdf' => PythonConverter::class,
        'rotate-pdf' => PythonConverter::class,
        'crop-pdf' => PythonConverter::class,
        'compress-pdf' => PythonConverter::class,
        'repair-pdf' => PythonConverter::class,
        'ocr-pdf' => PythonConverter::class,
        'edit-pdf' => PythonConverter::class,
        'page-numbers' => PythonConverter::class,
        'watermark-pdf' => PythonConverter::class,
        'protect-pdf' => PythonConverter::class,
        'unlock-pdf' => PythonConverter::class,
        'sign-pdf' => PythonConverter::class,
        'redact-pdf' => PythonConverter::class,
        'compare-pdf' => PythonConverter::class,
        'pdf-forms' => PythonConverter::class,
        'assignment-pack' => AssignmentPackConverter::class,
        'translate-pdf' => AiTranslator::class,
        'create-workflow' => PythonConverter::class,
    ],

    /*
     * Translate PDF memakai layanan penerjemahan eksternal (Claude dari Anthropic).
     * Tanpa API key, alat ini berstatus "Belum disiapkan". Teks dokumen
     * dikirim ke Anthropic, jadi pastikan kebijakan privasi mencakupnya (TBD-19).
     */
    'ai' => [
        'api_key' => env('KONVERTIN_ANTHROPIC_API_KEY'),
        'model' => env('KONVERTIN_AI_MODEL', 'claude-opus-5-5'),
        'max_pages_translate' => 50,
    ],

];
