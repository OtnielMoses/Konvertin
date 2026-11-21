# Audit Lanjutan: OCR, Antrean, Ekspor Gambar, Bookmark, Pemrosesan Lokal, Pemulihan Unduhan

Tanggal audit: 21 November 2026. Diperiksa dari kode yang berjalan, bukan dari nama tombol.

Status: **FULLY** = sudah tuntas (skip), **PARTIAL** = sebagian (enhance), **NOT** = belum ada (implement),
**OUT** = di luar arah produk, **LIMITED** = terbatas secara teknis (didokumentasikan).

## Arsitektur yang ditemukan

- Laravel 13, PHP 8.5. Frontend Vite + CSS sendiri, pdf.js 6.5 untuk pratinjau.
- Mesin `resources/engines/konvertin_engine.py`:
  - PyMuPDF untuk operasi PDF.
  - RapidOCR (ONNX, model PP-OCR) untuk OCR.
  - OpenCV dan Pillow untuk gambar.
- Proses sinkron per permintaan dengan progres NDJSON nyata. `QUEUE_CONNECTION=sync`; tidak ada worker antrean yang berjalan.
- Workspace sementara per proses:
  - Hanya pemilik sesi yang bisa mengunduh; tautan unduh sekali pakai.
  - Dihapus setelah dikirim atau setelah 30 menit (`konvertin:purge`).
- Semua pemrosesan dilakukan di server. pdf.js hanya dipakai untuk pratinjau.

## Tabel audit

| Fitur | Existing implementation | Status | Kekurangan | Tindakan |
|---|---|---|---|---|
| OCR: deteksi halaman tanpa teks | `needs_ocr` di OCR PDF dan Preflight | FULLY | – | Skip |
| OCR: text layer tak terlihat, tampilan asli, urutan halaman | `ocr_page` (render mode 3) | FULLY | – | Skip |
| OCR: pilih halaman tertentu | Selalu seluruh dokumen | NOT | – | **Implement** pilihan halaman + thumbnail berstatus teks |
| OCR: hasil teks, halaman perlu diperiksa, kualitas | Hanya jumlah kata | NOT | – | **Implement** teks per baris + skor keyakinan asli RapidOCR |
| OCR: koreksi manual | – | NOT | – | **Implement** koreksi per baris, diterapkan ke text layer saat proses ulang |
| OCR: halaman gagal | Satu galat menggagalkan semua | PARTIAL | – | **Enhance**: halaman gagal dilewati dan dilaporkan |
| OCR: pilihan bahasa | Model PP-OCR Latin + Tionghoa | LIMITED | Tidak ada model per bahasa; Tesseract butuh instalasi di sistem | Ditampilkan apa adanya ("huruf Latin: Indonesia/Inggris"), tanpa pilihan palsu |
| OCR di PDF to Word/Markdown | Hanya catatan "jalankan OCR dulu" | NOT | – | **Implement** opsi "OCR dulu untuk halaman scan" |
| OCR dari Preflight / Scan | Preflight hanya menyarankan; Scan punya opsi OCR | PARTIAL | Harus unggah ulang ke OCR PDF | **Enhance**: "Lanjutkan" mengirim PDF asli (bukan laporan) |
| OCR di Workflow | Langkah `ocr` | FULLY | – | Skip |
| Antrean: status per dokumen, retry, batal, unduh per berkas | Batch ZIP sinkron di Smart Compression | NOT | Satu permintaan untuk semua; satu gagal = semua gagal | **Implement** antrean di peramban (satu berkas per permintaan, berurutan) |
| Antrean: unduh semua yang berhasil (ZIP) | – | NOT | – | **Implement** `POST /unduh/zip` |
| Antrean: cegah proses ganda | Tombol dikunci di klien | PARTIAL | Server tidak menolak permintaan ulang | **Enhance**: kunci idempoten `X-Konvertin-Request` |
| Antrean: hentikan proses yang sedang berjalan | – | LIMITED | Mesin tidak bisa dihentikan aman dari peramban | Hanya antrean menunggu yang dibatalkan |
| PDF to JPG: format, DPI, kualitas, halaman | Tetap JPG 150 DPI, semua halaman | NOT | – | **Implement** JPG/PNG, 72/150/300/600 DPI, kualitas, halaman tertentu |
| PDF to JPG: pratinjau, ukuran, unduh per gambar | – | NOT | – | **Implement** thumbnail + dimensi piksel pasti, galeri hasil, unduh per gambar |
| Bookmark: lihat/buat/ubah/hapus/hierarki | Merge & Assignment Pack membuat bookmark otomatis | NOT | Tidak ada editor | **Implement** alat Bookmark PDF |
| Assignment Pack: bookmark dari section | Selalu dibuat (bagian + dokumen, halaman akhir) | PARTIAL | Tidak bisa dimatikan | **Enhance**: opsi bookmark di Assignment Pack |
| Pemrosesan di peramban | – | NOT | – | **Implement** untuk Merge, Rotate, Organize (pdf-lib di Web Worker) |
| Split / JPG to PDF / PDF to JPG di peramban | – | LIMITED | Butuh pustaka ZIP dan EXIF tambahan; PDF to JPG 600 DPI berat di memori | Tetap di server (didokumentasikan) |
| Label lokasi pemrosesan | "Diproses di server" / catatan layanan eksternal | PARTIAL | Belum ada label peramban | **Enhance** tiga label |
| Validasi hasil sebelum diunduh | – | NOT | Hasil rusak bisa ditawarkan | **Implement** pemeriksaan struktur PDF/ZIP/Office |
| Proses ulang bila hasil hilang | Halaman "kedaluwarsa" tanpa arah | PARTIAL | – | **Enhance**: tautan ke alat + "Proses lagi dengan berkas yang sama" |
| Klaim penghapusan setelah unduh | "Setelah pengiriman selesai ... dihapus" | PARTIAL | Server menghapus setelah selesai mengirim, juga bila unduhan terputus | **Enhance** teks agar akurat |
| Tautan aman, retensi, cleanup | ID acak 40 karakter, sesi pemilik, purge | FULLY | – | Skip |
| Unified pipeline | "Lanjutkan dengan alat lain" lewat URL pratinjau | PARTIAL | Tidak bisa untuk hasil lokal/berkas asli | **Enhance**: serah-terima lewat IndexedDB |
| Workflow: ekspor gambar / bookmark | – | OUT | Workflow menghasilkan PDF; bookmark perlu disunting per dokumen | Tidak diimplementasikan |

## Hasil implementasi

| Fase | Yang dibuat | Berkas utama |
|---|---|---|
| OCR | Pilih halaman; teks per baris + skor keyakinan asli mesin; tanda "perlu diperiksa" (> 20% baris di bawah 0,75 atau tanpa kata); koreksi per baris yang ditulis ke text layer; halaman gagal dilewati dan dilaporkan; halaman bertek dilewati | `op_ocr`, `ocr_page`, `lib/results.js`, `modes/thumbs.js` |
| OCR terintegrasi | PDF to Word / Markdown: opsi "OCR dulu" (Word memakai teks OCR terlihat karena pdf2docx mengabaikan teks tak terlihat; gambar halaman scan tidak ikut dan dilaporkan). Preflight: tombol "Jalankan OCR untuk PDF ini" (PDF asli diteruskan). Hasil konversi tanpa teks: tombol "Ulangi dengan OCR" | `ocr_first`, `workbench.js` |
| Antrean | Antrean di peramban untuk Smart Compression, OCR, PDF to Word/Excel/Markdown/JPG, Repair: satu permintaan per berkas, berurutan; status Menunggu/Dalam antrean/Diproses/Selesai/Gagal/Dibatalkan; ulangi, batalkan, unduh per berkas, unduh semua (ZIP); ringkasan dan perkiraan waktu dari durasi nyata; batas laju 429 ditunggu otomatis; kunci idempoten di server | `lib/queue.js`, `lib/request.js`, `DownloadController::bundle`, `ToolController` |
| Ekspor gambar | JPG/PNG, 72/150/300/600 DPI, kualitas JPG 50-100, halaman tertentu; ukuran piksel pasti sebelum proses; galeri hasil + unduh per gambar tanpa menghapus ZIP; batas 5 miliar piksel per ekspor | `op_pdf2jpg`, `DownloadController::member` |
| Bookmark | Alat Bookmark PDF: baca outline lama, tambah/ubah/hapus, halaman tujuan, urutan, tingkat; bookmark yang tidak diubah mempertahankan tujuan aslinya; Assignment Pack: opsi bookmark otomatis | `op_bookmarks`, `modes/bookmarks.js`, `validateOutline` |
| Pemrosesan lokal | Merge (dengan bookmark per berkas), Rotate, Organize dengan pdf-lib di Web Worker; tidak ada permintaan unggah (diverifikasi di Edge); batas 150 MB; label "Diproses di peramban / server Konvertin / layanan eksternal" | `lib/local.js`, `lib/local-worker.js` |
| Pemulihan unduhan | Validasi struktur hasil (PDF, ZIP, DOCX/PPTX/XLSX) sebelum ditawarkan; teks penghapusan yang akurat; "Proses lagi dengan berkas yang sama"; halaman kedaluwarsa menautkan kembali ke alatnya | `ResultValidator`, `download-gone.blade.php` |

### Dependensi baru

- `pdf-lib` 1.17.1 (npm, lisensi MIT, JavaScript murni). Hanya dimuat di Web Worker saat pemrosesan di peramban dipakai (±425 KB).
  Tidak ada dampak ke server dan tidak butuh hak administrator. Dipilih karena pdf.js hanya bisa membaca/merender, tidak bisa menulis PDF.

### Keterbatasan yang didokumentasikan

- OCR: model RapidOCR tidak punya pilihan bahasa; huruf beraksen dan aksara non-Latin bisa kurang akurat. Tesseract/OCRmyPDF
  butuh instalasi program sistem (tidak dipasang sesuai permintaan Pengguna).
- Antrean berjalan di tab peramban: menutup tab menghentikan berkas yang belum dikirim. Proses yang sudah berjalan di server
  tidak dapat dihentikan dengan aman; hanya yang menunggu yang dapat dibatalkan.
- Split, JPG to PDF, dan PDF to JPG tetap di server (butuh pustaka ZIP/EXIF tambahan; 600 DPI berat di memori peramban).
- Tautan unduh tetap sekali pakai: server menghapus hasil begitu selesai mengirim, juga bila unduhan terputus. Pemulihannya
  adalah memproses ulang berkas yang masih ada di halaman.
- Workflow tidak mendapat langkah ekspor gambar dan bookmark (hasil workflow selalu PDF; bookmark perlu disunting per dokumen).
