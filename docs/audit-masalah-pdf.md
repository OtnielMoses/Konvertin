# Audit Permasalahan Pengguna PDF

Tanggal audit: 21 November 2026. Audit dilakukan terhadap kode yang berjalan (katalog alat, mesin Python
`resources/engines/konvertin_engine.py`, converter PHP, mode JavaScript, dan tes), bukan terhadap nama tombol.

Status: **FULLY** = sudah tuntas, **PARTIAL** = sebagian, **NOT** = belum ada, **OUT** = di luar lingkup,
**LIMITED** = terbatas secara teknis.

## A. Ukuran berkas, kompresi, dan performa

| Permasalahan | Fitur existing | Status | Kekurangan | Tindakan |
|---|---|---|---|---|
| PDF terlalu besar untuk portal | Smart Compression (target KB/MB, tangga DPI/kualitas, pencarian biner) | FULLY | – | Skip |
| Target tidak tercapai | Laporan hasil terkecil + saran target (`hint`, `suggested_target`) | FULLY | – | Skip |
| Dampak kompresi pada kualitas | Laporan DPI/kualitas JPEG yang dipakai, pratinjau hasil sebelum unduh | FULLY | – | Skip |
| Perbandingan ukuran sebelum–sesudah | Batang ukuran asli/hasil/target | FULLY | – | Skip |
| Validasi target ukuran | Aturan `compressionRules()` + validasi klien (min. 10 KB) | FULLY | – | Skip |
| Harus mengompres satu per satu | – | NOT | Smart Compression hanya menerima satu PDF | **Implement**: batch, hasil ZIP + laporan per berkas |
| Dokumen besar timeout | Batas waktu proses, `set_time_limit` per jumlah berkas, progres streaming nyata | PARTIAL | Tidak ada antrean (queue) | Dokumentasikan; antrean belum diperlukan untuk skala saat ini |

## B. Pengelolaan banyak dokumen

| Permasalahan | Fitur existing | Status | Kekurangan | Tindakan |
|---|---|---|---|---|
| Unduh–unggah ulang antaralat | Create Workflow (rangkaian langkah dalam satu proses), Assignment Pack | PARTIAL | Hasil satu alat tidak bisa diteruskan ke alat lain | **Implement**: "Lanjutkan dengan alat lain" dari panel hasil |
| Workflow tidak bisa membersihkan halaman | Workflow: merge, unlock, repair, rotate, crop, OCR, compress, nomor, watermark, protect | PARTIAL | Belum ada langkah pemeriksaan/pembersihan | **Implement**: langkah Preflight Check di workflow |
| Pemrosesan banyak berkas | JPG/Scan multi, Merge, Office batch (gabung/ZIP), Assignment Pack | PARTIAL | Kompresi belum batch | Lihat A |
| Urutan berkas salah | Kartu drag-and-drop + tombol geser + pembaca layar | FULLY | – | Skip |
| Urutan nama "1, 10, 2" | – | NOT | Tidak ada pengurutan otomatis | **Implement**: tombol "Urutkan nama" (natural sort) |
| Pengelompokan dokumen | Assignment Pack: bagian Sampul, Awal, Isi, Lampiran | FULLY | – | Skip |
| Pengaturan per halaman | Organize PDF (thumbnail, hapus, putar, sisip) | FULLY | – | Skip |
| Halaman kosong ikut tergabung | – | NOT | – | **Implement** di Preflight Check |
| Halaman duplikat ikut tergabung | – | NOT | – | **Implement** di Preflight Check |
| Bookmark/outline | Bookmark per berkas di Merge dan Office batch, daftar isi di Assignment Pack | PARTIAL | Belum ada editor outline PDF yang sudah jadi | Rekomendasi tahap berikutnya |
| Pemisah bagian | Bagian dan penomoran per bagian di Assignment Pack | FULLY | – | Skip |

## C. Kualitas konversi

| Permasalahan | Fitur existing | Status | Kekurangan | Tindakan |
|---|---|---|---|---|
| Pengguna tidak tahu hasil layak/tidak | Pesan galat per jenis kegagalan | PARTIAL | Tidak ada pemeriksaan isi hasil | **Implement**: pemeriksaan hasil terukur (teks, gambar, tabel, halaman, font) untuk PDF ke Word/PowerPoint/Excel/Markdown, tanpa skor |
| Pratinjau berdampingan | Pratinjau hasil PDF/Markdown | PARTIAL | DOCX/PPTX/XLSX tidak dapat dirender di peramban tanpa pustaka baru | LIMITED, dokumentasikan |
| Tata letak Word berubah, font beda | pdf2docx | LIMITED | Keterbatasan mesin | Peringatan font yang tidak tertanam/tidak umum |
| Laporan galat konversi | `ConversionFailure` + pesan Indonesia | FULLY | – | Skip |

## D. PDF hasil scan

| Permasalahan | Fitur existing | Status | Kekurangan | Tindakan |
|---|---|---|---|---|
| Teks tidak bisa dicari | OCR PDF (RapidOCR, lapisan teks tak terlihat) | FULLY | – | Skip |
| Deteksi halaman hasil scan | `needs_ocr` di OCR | PARTIAL | Tidak dilaporkan sebelum diproses | Dilaporkan di Preflight Check |
| Foto miring/perspektif | Scan to PDF: 4 sudut manual + koreksi perspektif, rotasi, orientasi EXIF | PARTIAL | Tepi kertas harus digeser manual | **Implement**: deteksi tepi otomatis bila sudut tidak diubah |
| Bayangan, noise, kontras rendah | – | NOT | – | **Implement**: opsi "Perjelas dokumen" (normalisasi bayangan, kontras, peredam noise, hitam-putih) |
| Rotasi otomatis berdasarkan arah teks | EXIF saja | LIMITED | Butuh model klasifikasi arah teks | Dokumentasikan |

## E. Formulir PDF

| Permasalahan | Fitur existing | Status | Kekurangan | Tindakan |
|---|---|---|---|---|
| Mengisi field yang ada | PDF Forms (teks, checkbox, radio, dropdown) | FULLY | – | Skip |
| Formulir tanpa field (gambar/teks) | Deteksi otomatis + tambah field teks/checkbox | FULLY | – | Skip |
| Tanda tangan | Sign PDF (visual, dinyatakan bukan tanda tangan digital) | FULLY | – | Skip |
| Flatten | Opsi flatten | FULLY | – | Skip |
| Field tanggal | – | NOT (P1) | – | Tahap berikutnya |

## F. Privasi dan keamanan berkas

| Permasalahan | Fitur existing | Status | Kekurangan | Tindakan |
|---|---|---|---|---|
| Berkas tersimpan di server? | Workspace terisolasi, hapus setelah unduh, purge 30 menit, hapus saat gagal | FULLY | – | Skip |
| Tanpa akun | Semua alat publik | FULLY | – | Skip |
| Tautan unduh aman | Hanya sesi pemilik, sekali pakai, `no-store` | FULLY | – | Skip |
| Validasi unggahan | Deteksi isi berkas (magic bytes), batas ukuran/jumlah | FULLY | – | Skip |
| Transparansi lokasi pemrosesan | Catatan untuk layanan eksternal (Translate, Gotenberg) | PARTIAL | Alat server biasa tidak menyebut lokasi pemrosesan | **Enhance**: keterangan "diproses di server Konvertin" di panel aksi |
| Pemrosesan di peramban | – | NOT | Butuh pustaka JS baru (mis. pdf-lib) = perubahan dependensi | Dokumentasikan, perlu persetujuan |

## G. Redaksi

| Permasalahan | Fitur existing | Status | Kekurangan | Tindakan |
|---|---|---|---|---|
| Teks di bawah kotak hitam masih bisa disalin | `apply_redactions` (teks, gambar, grafik dihapus) + scrub metadata | FULLY | – | Skip |
| Verifikasi redaksi | Pencarian ulang kata kunci | PARTIAL | Area gambar tidak diverifikasi | **Enhance**: periksa teks tersisa di setiap area, laporkan hasil verifikasi |

## H. Membaca di ponsel

| Permasalahan | Fitur existing | Status | Kekurangan | Tindakan |
|---|---|---|---|---|
| PDF sulit dibaca di ponsel | Pratinjau hasil responsif; PDF to Markdown untuk teks mengalir | OUT | Konvertin adalah alat pengolahan, bukan pembaca | Tidak diimplementasikan |

## I. Aksesibilitas PDF

| Permasalahan | Fitur existing | Status | Kekurangan | Tindakan |
|---|---|---|---|---|
| Judul, bahasa, tagged PDF, alt text, label field | – | NOT | – | **Implement** sebagai bagian Preflight Check (laporan, bukan klaim kepatuhan) |
| Urutan baca, struktur tabel | – | LIMITED | Butuh pemeriksaan manual atau validator khusus (PAC) | Dinyatakan "tidak diperiksa" di laporan |

## J. Verifikasi tanda tangan digital

| Permasalahan | Fitur existing | Status | Kekurangan | Tindakan |
|---|---|---|---|---|
| Membedakan TTD visual dan digital | Catatan di Sign PDF | PARTIAL | – | **Implement** di Preflight Check: deteksi field tanda tangan, info sertifikat, perubahan setelah ditandatangani |
| Validitas kriptografis/kepercayaan sertifikat | – | LIMITED | Butuh validator PAdES (mis. pyHanko) dan daftar CA tepercaya | Dinyatakan "tidak diverifikasi" |

## K. Kompatibilitas tambahan

| Permasalahan | Fitur existing | Status | Kekurangan | Tindakan |
|---|---|---|---|---|
| PNG ke PDF | Scan to PDF menerima PNG | PARTIAL | JPG to PDF menolak PNG | **Enhance**: JPG to PDF menerima PNG (transparansi diberi latar putih) |
| Banyak gambar ke PDF | JPG to PDF multi berkas | FULLY | – | Skip |
| HEIC ke PDF | – | LIMITED | Butuh pustaka baru (pillow-heif/libheif) | Dokumentasikan |
| Metadata, ukuran halaman, rotasi | – | NOT | – | **Implement** di Preflight Check |

## Temuan tambahan

- Bug: `cardLabel()` di `resources/js/workbench.js` memuat ulang mode JPG setiap kali kartu dirender. Diperbaiki.
- Redaksi area: MuPDF membiarkan karakter di tepi area yang hanya tertutup sebagian, dan karakter itu masih bisa disalin.
  Sekarang karakter tersebut ikut dihapus pada lintasan kedua, lalu hasil diverifikasi.

## Hasil implementasi

| Modul | Berkas utama | Uji |
|---|---|---|
| Preflight Check (+ langkah workflow) | `op_preflight` di mesin, `tools/options/preflight-pdf.blade.php`, `lib/checks.js` | `PreflightCheckTest` (8 tes, termasuk PDF bertanda tangan sungguhan) |
| Smart Compression batch | `BatchConverter` (mode ZIP + laporan per berkas), `modes/compress.js` | `PdfPainPointsTest` |
| Pemeriksaan hasil konversi | `inspect_conversion` di mesin | `PdfPainPointsTest` |
| Verifikasi redaksi | `op_redact` | `PdfPainPointsTest` |
| Scan: tepi otomatis, perjelas | `detect_paper`, `enhance_scan` | `PdfPainPointsTest` |
| PNG di JPG to PDF | `image_bytes_upright` | `PdfPainPointsTest` |
| Lanjutkan dengan alat lain, urutkan nama, keterangan pemrosesan | `workbench.js`, `result-panel.blade.php`, `tool.blade.php` | `PdfPainPointsTest` + uji Playwright di Edge |

Tidak ada dependensi baru. `cryptography` (sudah terpasang sebagai dependensi pustaka Python lain) dipakai bila tersedia
untuk membaca sertifikat tanda tangan. Bila tidak ada, bagian sertifikat dilewati.

## Sisa pekerjaan (tahap berikutnya)

1. Editor bookmark/outline untuk PDF yang sudah jadi.
2. Field tanggal di PDF Forms.
3. Pemrosesan di peramban untuk Merge, Split, dan Rotate (butuh persetujuan dependensi, misalnya pdf-lib).
4. Antrean (queue) untuk dokumen sangat besar bila Konvertin dipakai banyak orang sekaligus.
5. HEIC to PDF (butuh pillow-heif/libheif).
6. Validasi kriptografis tanda tangan digital (butuh pyHanko dan daftar CA tepercaya).
7. Pratinjau pra-proses deteksi tepi kertas di Scan to PDF (saat ini terlihat di pratinjau hasil).
