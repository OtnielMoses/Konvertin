# Perubahan Ruang Lingkup terhadap SRS Konvertin

Dokumen ini mencatat perbedaan antara SRS awal dan implementasi setelah tahap
"Project Refinement" (19 November 2026), agar spesifikasi tidak lagi bertentangan
dengan aplikasi.

## 1. Positioning produk

Konvertin difokuskan pada pengolahan, penyusunan, dan optimasi dokumen akademik.
Konvertin tidak dikembangkan menjadi chatbot atau asisten AI dokumen.

| Perubahan | Keterangan |
|---|---|
| Dihapus: AI Summarizer | Alat, konverter (`AiSummarizer`), opsi, operasi mesin `extract-text`, dan kategori "AI PDF Tools" dihapus. Alamat `/ai-summarizer` kini 404. |
| Tidak dibuat: AI Study Companion, Ask Your PDF, AI Flashcards, AI Quiz Generator, chatbot | Tidak pernah ada di kode dan tidak termasuk ruang lingkup. |
| Dipertahankan: Translate PDF | Menjadi kategori sendiri ("Translate PDF"). Tetap memakai layanan penerjemahan eksternal (Claude, Anthropic) melalui `KONVERTIN_ANTHROPIC_API_KEY`. Halaman alat dan footer menjelaskan bahwa teks dokumen dikirim ke penyedia eksternal. |
| Create Workflow | Dipindah dari kategori AI ke Organize PDF. Tidak memakai AI. |

## 2. Fitur baru

### Smart Compression to Target Size (`compress-pdf`)

Menggantikan Compress PDF tiga tingkat.

- Masukan: ukuran target (KB/MB), strategi (`quality`, `balanced`, `size`), opsi lanjutan (turunkan resolusi gambar, resolusi minimum, kualitas JPEG minimum, hapus metadata, optimalkan struktur, ringkas font).
- Proses: kompresi bertahap memakai tangga resolusi/kualitas. Pencarian biner memilih tahap paling ringan yang sudah memenuhi target, dibatasi 60% batas waktu proses.
- Keluaran: ukuran asli, ukuran hasil yang diukur dari berkas sebenarnya, target, selisih, persentase, strategi, dan pengaturan gambar yang dipakai. Bila target tidak tercapai, sistem memberikan hasil terkecil yang benar-benar dicapai beserta saran target dan alasannya. Bila tidak bisa diperkecil, berkas asli dikembalikan.

### Assignment Pack Builder (`assignment-pack`)

- Masukan: PDF, DOC/DOCX, JPG, dan PNG, hingga 20 berkas. Isi berkas divalidasi di server.
- Word diubah ke PDF dengan mesin yang sama seperti alat Word to PDF. Bila mesin Word tidak tersedia, sistem menampilkan galat `word_unavailable`.
- Bagian opsional: Sampul, Bagian awal, Isi utama, Lampiran. Urutan akhir selalu mengikuti urutan bagian.
- Pengaturan halaman: kertas asli/A4/F4/Letter/Legal, orientasi, dan margin 0–40 mm.
- Penomoran akademik per bagian:
  - Gaya: tanpa nomor, Arab, Romawi kecil, atau Romawi besar.
  - Nomor awal dapat ditentukan, atau penomoran dilanjutkan dari bagian sebelumnya.
  - Posisi, perataan, huruf, dan ukuran nomor dapat diatur.
  - Aturan halaman pertama tiap dokumen: sama seperti halaman lain, di bawah tengah, atau tersembunyi.
  - Halaman tertentu dapat disembunyikan nomornya.
- Hasil:
  - Nomor ditulis ke halaman sekaligus sebagai label halaman PDF.
  - Bookmark dibuat per bagian dan per dokumen.
  - Kompresi opsional memakai Smart Compression.
  - Pratinjau dirender dari PDF akhir.

## 3. Perluasan fitur lama

- JPG to PDF: opsi ukuran halaman, orientasi, margin, penempatan gambar, dan hasil satu PDF atau PDF terpisah (ZIP).
- Merge PDF: opsi bookmark per berkas asal.

## 3a. Hasil audit masalah pengguna PDF (21 November 2026)

Rincian ada di `docs/audit-masalah-pdf.md`. Ringkasnya:

- Alat baru Preflight Check (`preflight-pdf`). Memeriksa:
  - halaman kosong dan halaman ganda;
  - ukuran kertas, orientasi, dan rotasi;
  - halaman scan tanpa teks dan font yang tidak tertanam;
  - formulir dan bookmark;
  - batas ukuran berkas;
  - aksesibilitas dasar;
  - data tanda tangan digital dan metadata.

  Hasilnya laporan PDF, atau PDF yang sudah dibersihkan. Alat ini juga tersedia sebagai langkah Create Workflow.
- Smart Compression menerima hingga 30 PDF (batas berkas per proses dinaikkan dari 25 ke 30). Target berlaku per berkas, dan hasilnya satu ZIP dengan laporan per berkas.
- Pemeriksaan hasil terukur (tanpa skor) untuk PDF to Word, PowerPoint, Excel, dan Markdown.
- Redact PDF: karakter yang hanya tertutup sebagian ikut dihapus, lalu hasil yang tersimpan diverifikasi ulang.
- Scan to PDF: deteksi tepi kertas otomatis, serta mode Perjelas (berwarna, abu-abu, hitam-putih).
- JPG to PDF menerima PNG. Transparansi diberi latar putih dan PNG tidak diubah menjadi JPEG.
- "Lanjutkan dengan alat lain": hasil PDF diteruskan ke alat berikutnya tanpa unduh dan unggah manual.
- Tombol "Urutkan nama" (urutan alami) untuk alat yang menerima banyak berkas.
- Keterangan lokasi pemrosesan ("Diproses di server Konvertin") pada alat yang tidak memakai layanan eksternal.

## 3b. Audit lanjutan (21 November 2026)

Rincian ada di `docs/audit-lanjutan-pdf.md`:

- OCR per halaman dengan teks, skor asli mesin, dan koreksi.
- OCR sebelum PDF to Word/Markdown.
- Antrean proses dengan status, ulang, batal, dan ZIP.
- Ekspor gambar JPG/PNG dengan pilihan DPI dan halaman.
- Alat baru Bookmark PDF.
- Merge, Rotate, dan Organize bisa diproses di peramban (pdf-lib).
- Validasi hasil sebelum diunduh.

## 4. Desain

Sejak 21 November 2026 desain mengikuti Desain2.md (SuperHi), dengan penyesuaian dari Pengguna.

- **Warna:**
  - Putih sebagai warna dominan.
  - Chalk Blue `#F0F6FF` dan Frost Blue `#E1EDFF` sebagai permukaan pendukung.
  - Electric Iris `#2727E6` sebagai satu-satunya warna aksi. Desain2.md tidak menjadikannya warna CTA, tetapi Pengguna memintanya.
  - Merah, kuning, biru muda, merah muda, dan hijau hanya untuk bentuk dekoratif.
- **Huruf:**
  - Inter, pengganti Haas Grot yang proprietary.
  - Judul berbobot 400 dengan tracking rapat.
  - Martian Mono (SIL OFL, di-host sendiri) untuk label kecil dan metadata.
- **Bentuk:**
  - Tombol pill dengan bayangan keras `0 4px 0 0 #111118`.
  - Kartu radius 24 px dengan bayangan keras `0 2px 0 0 #111118`.
  - Input dan tag berbentuk pill.
- **Dekorasi:**
  - Komponen `<x-shape>`: pentagon, segitiga, setengah lingkaran, bintang, lingkaran, dan lengkung. Warna rata, tanpa bayangan.
  - Dekorasi bergerak pelan di beranda.
  - Di ruang kerja dekorasi statis dan minimal.
- **Tombol:**
  - `btn-primary` (Iris) dan `btn-dark` (sekunder Desain2) untuk pasangan CTA.
  - `btn-secondary` berupa pill putih bergaris untuk aksi di ruang kerja. Pill gelap di semua aksi kecil akan membuat ruang kerja terlalu berat.

## 5. Hal yang masih terbuka

- TBD-19: kebijakan privasi untuk Translate PDF (pengiriman teks ke penyedia eksternal).
- Lisensi PyMuPDF (AGPL) sebelum layanan publik.
- PDF/A membutuhkan Gotenberg atau Ghostscript di server.
