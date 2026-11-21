<?php

/*
 * Pesan standar Sistem (Tabel 3.2 SRS) dan variannya.
 */

return [
    'msg01' => 'Format berkas tidak didukung oleh fitur ini. Pilih berkas dengan format yang sesuai.',
    'msg02' => 'Berkas melebihi batas yang diizinkan. Gunakan berkas yang lebih kecil atau kurangi jumlah berkas.',
    'msg03' => 'Pilih berkas terlebih dahulu sebelum memulai konversi.',
    'msg04' => 'Berkas sedang diproses. Mohon tunggu.',
    'msg05' => 'Proses selesai. Unduh hasil Anda. Berkas akan dihapus dari server setelah diunduh.',
    'msg06' => 'Berkas tidak dapat diproses. Pastikan berkas tidak rusak, lalu coba lagi.',
    'msg07' => 'Berkas hasil sudah dihapus. Silakan ulangi prosesnya.',

    'merge_minimum' => 'Pilih minimal dua berkas PDF untuk digabungkan.',
    'files_minimum' => 'Alat ini membutuhkan minimal :min berkas.',
    'files_exact' => 'Alat ini membutuhkan tepat :count berkas.',
    'url_required' => 'Isi alamat halaman web terlebih dahulu.',
    'url_invalid' => 'Alamat halaman web tidak valid. Gunakan alamat publik lengkap yang diawali http:// atau https://.',
    'options_invalid' => 'Pengaturan belum lengkap atau tidak valid. Periksa kembali isian di panel pengaturan.',
    'password_confirmation' => 'Konfirmasi kata sandi tidak sama.',
    'password_short' => 'Kata sandi minimal 6 karakter.',
    'aux_required' => 'Pilih :label terlebih dahulu.',
    'redact_empty' => 'Tandai area atau tuliskan kata yang ingin diredaksi.',
    'workflow_invalid' => 'Susunan workflow tidak valid. :reason',
    'pack_invalid' => 'Susunan paket tidak valid. :reason',
    'image_too_large' => 'Foto melebihi batas :limit per foto. Gunakan foto yang lebih kecil.',

    'failure' => [
        'timeout' => 'Proses melewati batas waktu sehingga dihentikan. Coba berkas yang lebih kecil, lalu ulangi.',
        'encrypted' => 'Berkas diproteksi kata sandi. Buka dulu dengan alat Unlock PDF, lalu coba lagi.',
        'no_tables' => 'Tidak ada tabel yang ditemukan pada PDF ini, sehingga tidak ada lembar kerja yang dibuat. Pastikan PDF memuat tabel berbentuk teks, bukan gambar.',
        'no_text' => 'PDF ini tidak memuat teks yang dapat dibaca, kemungkinan hasil pindai. Jalankan OCR PDF terlebih dahulu.',
        'pdfa_not_compliant' => 'Berkas tidak dapat diubah sehingga memenuhi standar PDF/A. Tidak ada berkas yang diberikan.',
        'unreachable' => 'Halaman web tidak dapat diakses atau diproses. Pastikan alamatnya benar dan dapat dibuka tanpa login, lalu coba lagi.',
        'service_unreachable' => 'Layanan konversi sedang tidak dapat dihubungi. Berkas Anda sudah dihapus. Coba lagi dalam satu atau dua menit.',
        'engine_unavailable' => 'Mesin untuk alat ini belum disiapkan di server, sehingga alat ini belum dapat dijalankan.',
        'nothing_to_ocr' => 'Semua halaman sudah memiliki teks yang dapat dicari, jadi OCR tidak diperlukan.',
        'wrong_password' => 'Kata sandi salah. PDF yang hanya dibatasi izinnya membutuhkan kata sandi pemilik.',
        'not_protected' => 'PDF ini tidak diproteksi kata sandi, jadi tidak ada yang perlu dibuka.',
        'invalid_pages' => 'Nomor halaman di luar jangkauan dokumen. Periksa kembali nomor atau rentang halaman.',
        'unrecoverable' => 'Struktur PDF terlalu rusak sehingga tidak ada halaman yang dapat dipulihkan.',
        'too_many_pages' => 'Dokumen melebihi batas jumlah halaman untuk alat ini.',
        'no_fields' => 'PDF ini tidak memiliki field formulir, dan belum ada field baru yang ditambahkan.',
        'nothing_found' => 'Belum ada yang diterapkan. Tambahkan objek, area, atau kata yang dicari, lalu coba lagi.',
        'ai_refused' => 'Layanan penerjemahan menolak memproses dokumen ini. Tidak ada hasil yang dibuat.',
        'ai_failed' => 'Layanan penerjemahan tidak dapat menyelesaikan permintaan saat ini. Berkas Anda sudah dihapus. Coba lagi beberapa saat lagi.',
        'export_too_large' => 'Ekspor ini terlalu besar: jumlah halaman dan DPI yang dipilih menghasilkan gambar yang sangat banyak. Pilih DPI lebih rendah atau lebih sedikit halaman.',
        'invalid_result' => 'Berkas hasil ternyata rusak sehingga tidak ditawarkan untuk diunduh. Berkas Anda sudah dihapus. Coba proses lagi.',
        'word_unavailable' => 'Dokumen Word belum dapat diubah di server ini. Simpan dokumen Word sebagai PDF terlebih dahulu, lalu masukkan PDF-nya ke paket.',
    ],

    'duplicate_request' => 'Permintaan yang sama sedang atau sudah diproses. Tunggu hasilnya, atau muat ulang halaman.',
    'outline_invalid' => 'Daftar bookmark tidak valid: :reason',
    'bundle_empty' => 'Tidak ada hasil yang masih tersedia untuk dikemas. Hasil yang sudah diunduh atau kedaluwarsa tidak dapat dikemas lagi.',
    'too_many_requests' => 'Terlalu banyak permintaan dalam waktu singkat. Tunggu satu menit, lalu coba lagi.',
    'page_expired' => 'Sesi halaman sudah berakhir. Muat ulang halaman, lalu coba lagi.',
];
