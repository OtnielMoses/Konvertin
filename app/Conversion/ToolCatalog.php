<?php

namespace App\Conversion;

use Illuminate\Support\Collection;

/**
 * Daftar seluruh alat Sistem. Alat konversi mengikuti Tabel 1.3 SRS; alat
 * lain adalah perluasan platform pengolahan PDF.
 */
class ToolCatalog
{
    private const PAGE_LIST = 'regex:/^\s*\d+(\s*-\s*\d+)?(\s*,\s*\d+(\s*-\s*\d+)?)*\s*$/';

    private const HEX_COLOR = 'regex:/^#[0-9a-fA-F]{6}$/';

    /** @var array<string, Tool>|null */
    private ?array $tools = null;

    /**
     * @return Collection<string, Tool>
     */
    public function all(): Collection
    {
        return collect($this->tools ??= $this->define());
    }

    /**
     * @return Collection<string, Tool>
     */
    public function inCategory(ToolCategory $category): Collection
    {
        return $this->all()->filter(fn (Tool $tool): bool => $tool->category === $category);
    }

    public function find(string $slug): ?Tool
    {
        return $this->all()->get($slug);
    }

    /**
     * Alat yang boleh dipakai sebagai langkah Create Workflow, beserta kunci
     * operasi mesin Python-nya.
     *
     * @return array<string, string>
     */
    public function workflowSteps(): array
    {
        return [
            'merge-pdf' => 'merge',
            'unlock-pdf' => 'unlock',
            'repair-pdf' => 'repair',
            'preflight-pdf' => 'preflight',
            'rotate-pdf' => 'rotate',
            'crop-pdf' => 'crop',
            'ocr-pdf' => 'ocr',
            'compress-pdf' => 'compress',
            'page-numbers' => 'pagenumbers',
            'watermark-pdf' => 'watermark',
            'protect-pdf' => 'protect',
        ];
    }

    /**
     * Aturan Smart Compression. Assignment Pack memakai aturan yang sama,
     * wajib hanya bila kompresi diaktifkan.
     *
     * @return array<string, list<string>>
     */
    private function compressionRules(?string $enabledField = null): array
    {
        $required = $enabledField === null ? 'required' : 'required_if_accepted:'.$enabledField;

        return [
            'target_value' => ['nullable', $required, 'numeric', 'min:0.01', 'max:1000000'],
            'target_unit' => ['nullable', $required, 'in:KB,MB'],
            'strategy' => ['nullable', $required, 'in:quality,balanced,size'],
            'downsample' => ['nullable', 'boolean'],
            'min_dpi' => ['nullable', 'integer', 'min:72', 'max:300'],
            'min_quality' => ['nullable', 'integer', 'min:20', 'max:95'],
            'strip_metadata' => ['nullable', 'boolean'],
            'optimize_resources' => ['nullable', 'boolean'],
            'subset_fonts' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Cakupan halaman opsional: tanpa nilai berarti semua halaman.
     *
     * @return array<string, mixed>
     */
    private function optionalScopeRules(): array
    {
        return [
            'scope' => ['nullable', 'in:all,pages'],
            'pages' => ['nullable', 'required_if:options.scope,pages', 'string', 'max:200', self::PAGE_LIST],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scopeRules(): array
    {
        return [
            'scope' => ['required', 'in:all,pages'],
            'pages' => ['nullable', 'required_if:options.scope,pages', 'string', 'max:200', self::PAGE_LIST],
        ];
    }

    /**
     * @return array<string, Tool>
     */
    private function define(): array
    {
        $pdf = ['pdf'];
        $pdfAccept = '.pdf,application/pdf';
        $imageAccept = '.jpg,.jpeg,.png,image/jpeg,image/png';
        $protectedNote = 'Berkas yang diproteksi kata sandi perlu dibuka dulu dengan alat Unlock PDF.';
        $officeNote = 'Makro di dalam dokumen tidak dijalankan, jadi dokumen tetap aman diproses.';

        $tools = [
            // ---------------------------------------------------------- Convert to PDF
            new Tool(
                slug: 'jpg-to-pdf', name: 'JPG to PDF', summary: 'Mengubah gambar JPG atau PNG menjadi PDF.',
                category: ToolCategory::Convert, group: 'to', icon: 'image',
                inputLabel: 'JPG', outputLabel: 'PDF', inputFormats: ['jpg', 'png'], accept: $imageAccept,
                acceptsMultiple: true, actionLabel: 'Ubah ke PDF', keywords: ['gambar', 'foto', 'image', 'png', 'screenshot'],
                notes: [
                    'Pilih beberapa gambar sekaligus. Urutan kartu menjadi urutan halaman PDF.',
                    'Setiap gambar ditempatkan utuh pada satu halaman, tanpa dipotong atau diregangkan.',
                ],
                optionRules: [
                    'page_size' => ['nullable', 'in:a4,letter,legal,f4,image'],
                    'orientation' => ['nullable', 'in:auto,portrait,landscape'],
                    'margin' => ['nullable', 'in:none,small,large'],
                    'fit' => ['nullable', 'in:fit,actual'],
                    'merge' => ['nullable', 'in:one,separate'],
                ],
            ),
            new Tool(
                slug: 'word-to-pdf', name: 'Word to PDF', summary: 'Mengubah DOC/DOCX menjadi PDF.',
                category: ToolCategory::Convert, group: 'to', icon: 'doc-word',
                inputLabel: 'DOC', outputLabel: 'PDF', inputFormats: ['doc', 'docx'],
                accept: '.doc,.docx,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                actionLabel: 'Ubah ke PDF', keywords: ['docx', 'dokumen', 'makalah'],
                notes: ['Teks, gambar, dan tabel dokumen dipertahankan pada PDF hasil.', 'Dokumen berisi makro (DOCM) tidak diterima.', $officeNote],
                optionRules: ['merge' => ['nullable', 'in:one,separate']],
                acceptsMultiple: true, batch: true,
            ),
            new Tool(
                slug: 'powerpoint-to-pdf', name: 'PowerPoint to PDF', summary: 'Mengubah PPT/PPTX menjadi PDF.',
                category: ToolCategory::Convert, group: 'to', icon: 'doc-slides',
                inputLabel: 'PPT', outputLabel: 'PDF', inputFormats: ['ppt', 'pptx'],
                accept: '.ppt,.pptx,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation',
                actionLabel: 'Ubah ke PDF', keywords: ['pptx', 'slide', 'presentasi'],
                notes: ['Setiap slide menjadi satu halaman PDF.', $officeNote],
                optionRules: ['merge' => ['nullable', 'in:one,separate']],
                acceptsMultiple: true, batch: true,
            ),
            new Tool(
                slug: 'excel-to-pdf', name: 'Excel to PDF', summary: 'Mengubah XLS/XLSX menjadi PDF.',
                category: ToolCategory::Convert, group: 'to', icon: 'doc-sheet',
                inputLabel: 'XLS', outputLabel: 'PDF', inputFormats: ['xls', 'xlsx'],
                accept: '.xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                actionLabel: 'Ubah ke PDF', keywords: ['xlsx', 'spreadsheet', 'tabel'],
                notes: ['Seluruh lembar kerja yang berisi data ikut dikonversi.', $officeNote],
                optionRules: ['merge' => ['nullable', 'in:one,separate']],
                acceptsMultiple: true, batch: true,
            ),
            new Tool(
                slug: 'html-to-pdf', name: 'HTML to PDF', summary: 'Mengubah halaman web menjadi PDF.',
                category: ToolCategory::Convert, group: 'to', icon: 'globe',
                inputLabel: 'HTML', outputLabel: 'PDF', inputFormats: [], accept: '',
                usesUrl: true, mode: 'url', actionLabel: 'Ubah ke PDF', keywords: ['web', 'url', 'situs', 'halaman'],
                notes: ['Masukkan alamat halaman yang dapat dibuka tanpa login.', 'Demi keamanan, alamat jaringan lokal atau internal (misalnya alamat router) tidak dapat dibuka.'],
            ),
            new Tool(
                slug: 'scan-to-pdf', name: 'Scan to PDF', summary: 'Memotret atau mengunggah hasil pindai lalu menyatukannya menjadi PDF.',
                category: ToolCategory::Convert, group: 'to', icon: 'camera',
                inputLabel: 'Foto', outputLabel: 'PDF', inputFormats: ['jpg', 'png'], accept: $imageAccept,
                acceptsMultiple: true, mode: 'scan', actionLabel: 'Buat PDF', keywords: ['kamera', 'pindai', 'foto', 'scanner'],
                notes: [
                    'Ambil foto dengan kamera perangkat, atau unggah gambar yang sudah ada.',
                    'Geser keempat titik sudut agar pas dengan tepi kertas; perspektifnya diluruskan otomatis.',
                    'OCR bersifat opsional dan membuat teks hasil pindai dapat dicari.',
                ],
                optionRules: [
                    'transforms' => ['nullable', 'json', 'max:20000'],
                    'auto_edges' => ['nullable', 'boolean'],
                    'enhance' => ['nullable', 'in:none,color,gray,bw'],
                    'ocr' => ['nullable', 'boolean'],
                ],
            ),

            // -------------------------------------------------------- Convert from PDF
            new Tool(
                slug: 'pdf-to-jpg', name: 'PDF to JPG', summary: 'Mengubah halaman PDF menjadi gambar JPG atau PNG dengan resolusi pilihan.',
                category: ToolCategory::Convert, group: 'from', icon: 'image',
                inputLabel: 'PDF', outputLabel: 'JPG', inputFormats: $pdf, accept: $pdfAccept, actionLabel: 'Ubah ke gambar',
                mode: 'thumbs', keywords: ['gambar', 'foto', 'png', 'dpi', 'resolusi'], queue: true,
                notes: ['Setiap halaman menjadi satu gambar. Lebih dari satu gambar dikemas dalam ZIP bernomor urut.', $protectedNote],
                optionRules: [
                    'format' => ['nullable', 'in:jpg,png'],
                    'dpi' => ['nullable', 'in:72,150,300,600'],
                    'quality' => ['nullable', 'integer', 'min:50', 'max:100'],
                    ...$this->optionalScopeRules(),
                ],
            ),
            new Tool(
                slug: 'pdf-to-word', name: 'PDF to Word', summary: 'Mengubah PDF menjadi DOC/DOCX.',
                category: ToolCategory::Convert, group: 'from', icon: 'doc-word',
                inputLabel: 'PDF', outputLabel: 'DOCX', inputFormats: $pdf, accept: $pdfAccept, actionLabel: 'Ubah ke Word',
                keywords: ['docx', 'sunting'],
                notes: ['Hasil berformat DOCX. Teks yang dapat dipilih menjadi teks yang dapat disunting.', 'Untuk PDF hasil pindai, aktifkan OCR dulu agar teksnya bisa disunting.', $protectedNote],
                optionRules: ['ocr_first' => ['nullable', 'boolean']],
                queue: true,
            ),
            new Tool(
                slug: 'pdf-to-powerpoint', name: 'PDF to PowerPoint', summary: 'Mengubah PDF menjadi PPT/PPTX.',
                category: ToolCategory::Convert, group: 'from', icon: 'doc-slides',
                inputLabel: 'PDF', outputLabel: 'PPTX', inputFormats: $pdf, accept: $pdfAccept, actionLabel: 'Ubah ke PowerPoint',
                keywords: ['pptx', 'slide', 'presentasi'],
                notes: ['Setiap halaman menjadi satu slide berisi gambar halaman tersebut.', 'Teks halaman disalin ke catatan pembicara.', $protectedNote],
            ),
            new Tool(
                slug: 'pdf-to-excel', name: 'PDF to Excel', summary: 'Mengubah PDF menjadi XLS/XLSX.',
                category: ToolCategory::Convert, group: 'from', icon: 'doc-sheet',
                queue: true, inputLabel: 'PDF', outputLabel: 'XLSX', inputFormats: $pdf, accept: $pdfAccept, actionLabel: 'Ubah ke Excel',
                keywords: ['xlsx', 'tabel', 'spreadsheet'],
                notes: ['Hanya tabel yang terdeteksi yang dipindahkan. Setiap tabel menjadi satu lembar kerja.', 'Bila tidak ada tabel, Sistem memberi tahu Anda dan tidak membuat lembar kosong.', $protectedNote],
            ),
            new Tool(
                slug: 'pdf-to-pdfa', name: 'PDF to PDF/A', summary: 'Mengubah PDF menjadi format standar pengarsipan jangka panjang.',
                category: ToolCategory::Convert, group: 'from', icon: 'archive',
                inputLabel: 'PDF', outputLabel: 'PDF/A', inputFormats: $pdf, accept: $pdfAccept, actionLabel: 'Ubah ke PDF/A',
                keywords: ['arsip', 'pdfa', 'archive'],
                notes: ['Hasil ditujukan untuk PDF/A-2b.', 'Bila berkas tidak dapat memenuhi standar, konversi dinyatakan gagal dan tidak ada berkas yang diberikan.', $protectedNote],
            ),
            new Tool(
                slug: 'pdf-to-markdown', name: 'PDF to Markdown', summary: 'Mengubah isi PDF menjadi format Markdown.',
                category: ToolCategory::Convert, group: 'from', icon: 'markdown',
                inputLabel: 'PDF', outputLabel: 'MD', inputFormats: $pdf, accept: $pdfAccept, actionLabel: 'Ubah ke Markdown',
                keywords: ['md', 'teks', 'catatan'],
                notes: ['Judul, daftar, dan tabel yang dikenali diubah ke sintaks Markdown.', 'Untuk PDF hasil pindai, aktifkan OCR dulu agar teksnya terbaca.', $protectedNote],
                optionRules: ['ocr_first' => ['nullable', 'boolean']],
                queue: true,
            ),

            // ----------------------------------------------------------------- Organize
            new Tool(
                slug: 'assignment-pack', name: 'Assignment Pack Builder',
                summary: 'Menyusun sampul, isi Word, gambar, dan lampiran menjadi satu PDF siap kumpul, lengkap dengan nomor halaman akademik.',
                category: ToolCategory::Organize, icon: 'pack',
                inputLabel: 'PDF, Word, gambar', outputLabel: 'PDF', inputFormats: ['pdf', 'docx', 'doc', 'jpg', 'png'],
                accept: '.pdf,.doc,.docx,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                acceptsMultiple: true, mode: 'pack', actionLabel: 'Generate Final PDF',
                keywords: ['tugas', 'laporan', 'kumpul', 'sampul', 'cover', 'lampiran', 'skripsi', 'makalah', 'nomor halaman', 'romawi'],
                notes: [
                    'Urutan akhir mengikuti bagian: Sampul, Bagian awal, Isi utama, lalu Lampiran. Di dalam bagian, urutan kartu Anda yang dipakai.',
                    'Bila ukuran kertas diseragamkan, halaman ditempatkan utuh di kertas baru. Tautan dan field formulir di dalam PDF asli tidak ikut terbawa.',
                    'Nomor halaman juga ditulis sebagai label halaman PDF, sehingga aplikasi pembaca menampilkan i, ii, 1, 2 sesuai bagian.',
                    $protectedNote,
                ],
                optionRules: [
                    'sections' => ['required', 'json', 'max:4000'],
                    'grouping' => ['nullable', 'boolean'],
                    'bookmarks' => ['nullable', 'boolean'],
                    'title' => ['nullable', 'string', 'max:120'],
                    'paper' => ['required', 'in:original,a4,letter,legal,f4'],
                    'orientation' => ['required', 'in:auto,portrait,landscape'],
                    'margin_mm' => ['required', 'integer', 'min:0', 'max:40'],
                    'numbering' => ['required', 'json', 'max:4000'],
                    'number_position' => ['required', 'in:top,bottom'],
                    'number_align' => ['required', 'in:left,center,right'],
                    'number_size' => ['required', 'integer', 'min:8', 'max:18'],
                    'number_font' => ['required', 'in:times,helvetica'],
                    'first_page_rule' => ['required', 'in:same,hide,bottom_center'],
                    'hide_pages' => ['nullable', 'string', 'max:200', self::PAGE_LIST],
                    'compress' => ['nullable', 'boolean'],
                    ...$this->compressionRules('options.compress'),
                ],
            ),
            new Tool(
                slug: 'merge-pdf', name: 'Merge PDF', summary: 'Menggabungkan beberapa berkas PDF menjadi satu.',
                category: ToolCategory::Organize, icon: 'merge',
                inputLabel: 'PDF', outputLabel: 'PDF', inputFormats: $pdf, accept: $pdfAccept,
                acceptsMultiple: true, minFiles: 2, actionLabel: 'Gabungkan PDF', keywords: ['gabung', 'satukan', 'combine'], local: true,
                notes: ['Pilih minimal dua PDF. Seret kartu berkas atau pakai tombol geser untuk mengatur urutan.', 'Urutan halaman di dalam setiap berkas tetap.', $protectedNote],
                optionRules: ['bookmarks' => ['nullable', 'boolean']],
            ),
            new Tool(
                slug: 'split-pdf', name: 'Split PDF', summary: 'Memisahkan PDF berdasarkan rentang, halaman tertentu, atau jumlah halaman.',
                category: ToolCategory::Organize, icon: 'split',
                inputLabel: 'PDF', outputLabel: 'PDF/ZIP', inputFormats: $pdf, accept: $pdfAccept,
                mode: 'thumbs', actionLabel: 'Pisahkan PDF', keywords: ['pisah', 'potong', 'bagi', 'extract'],
                notes: ['Hasil lebih dari satu berkas dikemas dalam satu ZIP.', $protectedNote],
                optionRules: [
                    'mode' => ['required', 'in:ranges,pages,every,chunk'],
                    'ranges' => ['nullable', 'required_if:options.mode,ranges', 'string', 'max:200', self::PAGE_LIST],
                    'pages' => ['nullable', 'required_if:options.mode,pages', 'string', 'max:200', self::PAGE_LIST],
                    'chunk' => ['nullable', 'required_if:options.mode,chunk', 'integer', 'min:1', 'max:1000'],
                ],
            ),
            new Tool(
                slug: 'organize-pdf', name: 'Organize PDF', summary: 'Menyusun ulang, menghapus, memutar, dan menyisipkan halaman lewat thumbnail.',
                category: ToolCategory::Organize, icon: 'organize',
                inputLabel: 'PDF', outputLabel: 'PDF', inputFormats: $pdf, accept: $pdfAccept,
                mode: 'pages', actionLabel: 'Simpan susunan', keywords: ['urutkan', 'hapus halaman', 'sisip', 'extract pages', 'ambil halaman'], local: true,
                notes: ['Seret thumbnail untuk mengubah urutan, atau pakai tombol pada setiap halaman.', 'Tambahkan halaman dari PDF lain dan sisipkan halaman kosong.', $protectedNote],
                optionRules: ['spec' => ['required', 'json', 'max:200000']],
                auxFiles: ['extra' => ['label' => 'PDF tambahan', 'formats' => ['pdf'], 'accept' => '.pdf,application/pdf']],
            ),
            new Tool(
                slug: 'bookmark-pdf', name: 'Bookmark PDF', summary: 'Melihat, membuat, mengubah, dan menyusun bookmark bertingkat di PDF.',
                category: ToolCategory::Organize, icon: 'note',
                inputLabel: 'PDF', outputLabel: 'PDF', inputFormats: $pdf, accept: $pdfAccept,
                mode: 'bookmarks', actionLabel: 'Simpan bookmark', keywords: ['bookmark', 'outline', 'daftar isi', 'navigasi', 'penanda'],
                notes: ['Bookmark yang tidak diubah tetap memakai tujuan aslinya.', $protectedNote],
                optionRules: ['outline' => ['required', 'json', 'max:200000']],
            ),
            new Tool(
                slug: 'rotate-pdf', name: 'Rotate PDF', summary: 'Memutar seluruh halaman, halaman tertentu, atau rentang halaman.',
                category: ToolCategory::Organize, icon: 'rotate',
                inputLabel: 'PDF', outputLabel: 'PDF', inputFormats: $pdf, accept: $pdfAccept,
                mode: 'thumbs', actionLabel: 'Putar PDF', keywords: ['putar', 'orientasi', 'landscape'], local: true,
                notes: ['Rotasi ditambahkan pada orientasi halaman saat ini.', $protectedNote],
                optionRules: ['angle' => ['required', 'in:90,180,270'], ...$this->scopeRules()],
            ),
            new Tool(
                slug: 'crop-pdf', name: 'Crop PDF', summary: 'Memotong area halaman dengan seleksi atau pengaturan margin.',
                category: ToolCategory::Organize, icon: 'crop',
                inputLabel: 'PDF', outputLabel: 'PDF', inputFormats: $pdf, accept: $pdfAccept,
                mode: 'editor', actionLabel: 'Potong PDF', keywords: ['potong', 'margin', 'trim'],
                notes: ['Pemotongan mengubah area tampil halaman (CropBox); isi di luar area tidak ditampilkan pembaca PDF.', $protectedNote],
                optionRules: [
                    'mode' => ['required', 'in:margins,box'],
                    'top' => ['nullable', 'numeric', 'min:0', 'max:200'],
                    'right' => ['nullable', 'numeric', 'min:0', 'max:200'],
                    'bottom' => ['nullable', 'numeric', 'min:0', 'max:200'],
                    'left' => ['nullable', 'numeric', 'min:0', 'max:200'],
                    'box' => ['nullable', 'required_if:options.mode,box', 'json', 'max:500'],
                    ...$this->scopeRules(),
                ],
            ),

            // ----------------------------------------------------------------- Optimize
            new Tool(
                slug: 'compress-pdf', name: 'Smart Compression', summary: 'Memperkecil PDF sampai di bawah ukuran target yang Anda tentukan, tanpa menurunkan kualitas lebih dari yang perlu.',
                category: ToolCategory::Optimize, icon: 'compress',
                inputLabel: 'PDF', outputLabel: 'PDF', inputFormats: $pdf, accept: $pdfAccept,
                acceptsMultiple: true, batch: true, batchMerge: false, queue: true,
                mode: 'compress', actionLabel: 'Compress PDF', keywords: ['kecilkan', 'ukuran', 'perkecil', 'size', 'target', 'mb', 'kb', 'kompres'],
                notes: [
                    'Kompresi dicoba bertahap, dari yang paling ringan. Tahap pertama yang sudah di bawah target dipakai, jadi kualitas tidak diturunkan lebih dari yang perlu.',
                    'Bila target tidak tercapai, Anda tetap mendapat hasil terkecil yang benar-benar dicapai beserta ukurannya.',
                    'Pengurangan terbesar terjadi pada PDF yang banyak memuat gambar. Teks dan grafik vektor tidak diubah.',
                    $protectedNote,
                ],
                optionRules: $this->compressionRules(),
            ),
            new Tool(
                slug: 'preflight-pdf', name: 'Preflight Check', summary: 'Memeriksa PDF sebelum dikumpulkan: halaman kosong atau ganda, ukuran kertas, halaman scan, font, aksesibilitas, dan tanda tangan digital.',
                category: ToolCategory::Optimize, icon: 'check-circle',
                inputLabel: 'PDF', outputLabel: 'PDF', inputFormats: $pdf, accept: $pdfAccept,
                actionLabel: 'Periksa PDF', keywords: ['cek', 'periksa', 'kosong', 'duplikat', 'ganda', 'aksesibilitas', 'tanda tangan digital', 'metadata', 'ukuran kertas'],
                notes: [
                    'Tanpa opsi pembersihan, hasilnya berupa laporan PDF. Dengan opsi pembersihan, hasilnya PDF tanpa halaman kosong atau ganda.',
                    'Tanda tangan digital hanya dibaca datanya; keabsahan sertifikat tidak diverifikasi.',
                    $protectedNote,
                ],
                optionRules: [
                    'remove_blank' => ['nullable', 'boolean'],
                    'remove_duplicates' => ['nullable', 'boolean'],
                    'target_value' => ['nullable', 'numeric', 'min:0.01', 'max:1000000'],
                    'target_unit' => ['nullable', 'in:KB,MB'],
                ],
            ),
            new Tool(
                slug: 'repair-pdf', name: 'Repair PDF', summary: 'Memeriksa dan mencoba memperbaiki struktur PDF yang rusak.',
                category: ToolCategory::Optimize, icon: 'repair',
                inputLabel: 'PDF', outputLabel: 'PDF', inputFormats: $pdf, accept: $pdfAccept,
                actionLabel: 'Perbaiki PDF', keywords: ['rusak', 'corrupt', 'pulihkan'], queue: true,
                notes: ['Hasil dilaporkan apa adanya: utuh, diperbaiki, sebagian dipulihkan, atau gagal.', 'Tidak semua PDF rusak dapat dipulihkan.'],
            ),
            new Tool(
                slug: 'ocr-pdf', name: 'OCR PDF', summary: 'Membuat PDF hasil pindai memiliki lapisan teks yang bisa dicari dan disalin.',
                category: ToolCategory::Optimize, icon: 'ocr',
                inputLabel: 'PDF', outputLabel: 'PDF', inputFormats: $pdf, accept: $pdfAccept,
                mode: 'thumbs', actionLabel: 'Jalankan OCR', keywords: ['scan', 'teks', 'searchable', 'pindai'], queue: true,
                notes: [
                    'Hanya halaman berupa gambar yang diproses; tampilan halaman tidak berubah.',
                    'OCR mengenali huruf Latin, sehingga cocok untuk dokumen berbahasa Indonesia dan Inggris.',
                    'Dokumen yang panjang membutuhkan waktu lebih lama untuk diproses.',
                ],
                optionRules: [
                    ...$this->optionalScopeRules(),
                    'corrections' => ['nullable', 'json', 'max:400000'],
                ],
            ),

            // --------------------------------------------------------------------- Edit
            new Tool(
                slug: 'edit-pdf', name: 'Edit PDF', summary: 'Menambahkan teks, gambar, bentuk, highlight, komentar, dan coretan.',
                category: ToolCategory::Edit, icon: 'edit',
                inputLabel: 'PDF', outputLabel: 'PDF', inputFormats: $pdf, accept: $pdfAccept,
                mode: 'editor', actionLabel: 'Simpan perubahan', keywords: ['anotasi', 'tulis', 'gambar', 'highlight', 'komentar'],
                notes: [
                    'Alat ini menambahkan objek di atas halaman. Teks asli PDF tidak dapat diubah.',
                    'Teks, bentuk, coretan, highlight, dan komentar disimpan sebagai anotasi PDF yang masih dapat dihapus di aplikasi pembaca PDF.',
                    $protectedNote,
                ],
                optionRules: ['objects' => ['required', 'json', 'max:12000000']],
            ),
            new Tool(
                slug: 'page-numbers', name: 'Page Numbers', summary: 'Menambahkan nomor halaman dengan posisi, format, dan gaya pilihan.',
                category: ToolCategory::Edit, icon: 'numbers',
                inputLabel: 'PDF', outputLabel: 'PDF', inputFormats: $pdf, accept: $pdfAccept,
                mode: 'preview', actionLabel: 'Tambahkan nomor', keywords: ['nomor', 'halaman', 'penomoran'],
                notes: ['Pratinjau menunjukkan posisi nomor pada halaman pertama yang diberi nomor.', $protectedNote],
                optionRules: [
                    'position' => ['required', 'in:top,bottom'],
                    'align' => ['required', 'in:left,center,right'],
                    'size' => ['required', 'integer', 'min:6', 'max:48'],
                    'font' => ['required', 'in:helvetica,times,courier'],
                    'color' => ['nullable', self::HEX_COLOR],
                    'format' => ['required', 'in:n,n_of_total,page_n,page_n_of_total,roman_lower,roman_upper'],
                    'start' => ['required', 'integer', 'min:0', 'max:100000'],
                    'first_page' => ['required', 'integer', 'min:1'],
                    'last_page' => ['nullable', 'integer', 'gte:options.first_page'],
                    'prefix' => ['nullable', 'string', 'max:30'],
                    'suffix' => ['nullable', 'string', 'max:30'],
                ],
            ),
            new Tool(
                slug: 'watermark-pdf', name: 'Watermark PDF', summary: 'Menambahkan watermark teks atau gambar dengan transparansi dan rotasi.',
                category: ToolCategory::Edit, icon: 'watermark',
                inputLabel: 'PDF', outputLabel: 'PDF', inputFormats: $pdf, accept: $pdfAccept,
                mode: 'preview', actionLabel: 'Tambahkan watermark', keywords: ['tanda air', 'cap', 'rahasia', 'draf'],
                notes: ['Pratinjau menunjukkan watermark pada halaman pertama.', $protectedNote],
                optionRules: [
                    'type' => ['required', 'in:text,image'],
                    'text' => ['nullable', 'required_if:options.type,text', 'string', 'max:120'],
                    'position' => ['required', 'in:center,top-left,top-right,bottom-left,bottom-right'],
                    'opacity' => ['required', 'integer', 'min:5', 'max:100'],
                    'size' => ['required', 'numeric', 'min:5', 'max:200'],
                    'rotation' => ['required', 'integer', 'min:-180', 'max:180'],
                    'color' => ['nullable', self::HEX_COLOR],
                    ...$this->scopeRules(),
                ],
                auxFiles: ['image' => ['label' => 'Gambar watermark', 'formats' => ['png', 'jpg'], 'accept' => '.png,.jpg,.jpeg,image/png,image/jpeg']],
            ),

            // ----------------------------------------------------------------- Security
            new Tool(
                slug: 'protect-pdf', name: 'Protect PDF', summary: 'Mengunci PDF dengan kata sandi dan enkripsi AES-256.',
                category: ToolCategory::Security, icon: 'lock',
                inputLabel: 'PDF', outputLabel: 'PDF', inputFormats: $pdf, accept: $pdfAccept,
                actionLabel: 'Kunci PDF', keywords: ['password', 'sandi', 'enkripsi', 'kunci'],
                notes: [
                    'Kata sandi tidak disimpan dan tidak dicatat. Bila lupa, Konvertin tidak dapat membukanya kembali.',
                    'Izin (cetak, salin, ubah) dihormati oleh sebagian besar aplikasi pembaca PDF, tetapi tidak semua aplikasi menaatinya.',
                ],
                optionRules: [
                    'password' => ['required', 'string', 'min:6', 'max:128', 'confirmed'],
                    'owner_password' => ['nullable', 'string', 'min:6', 'max:128', 'different:options.password'],
                    'permissions' => ['nullable', 'array'],
                    'permissions.*' => ['in:print,copy,modify,annotate'],
                ],
            ),
            new Tool(
                slug: 'unlock-pdf', name: 'Unlock PDF', summary: 'Menghapus kata sandi PDF bila Anda mengetahui kata sandinya.',
                category: ToolCategory::Security, icon: 'unlock',
                inputLabel: 'PDF', outputLabel: 'PDF', inputFormats: $pdf, accept: $pdfAccept,
                actionLabel: 'Buka kunci', keywords: ['password', 'sandi', 'buka'],
                notes: [
                    'Kata sandi yang benar wajib dimasukkan. Konvertin tidak menebak atau membobol kata sandi.',
                    'PDF yang hanya dibatasi izinnya membutuhkan kata sandi pemilik (owner password).',
                ],
                optionRules: ['password' => ['required', 'string', 'max:128']],
            ),
            new Tool(
                slug: 'sign-pdf', name: 'Sign PDF', summary: 'Menambahkan tanda tangan elektronik visual: digambar, diketik, atau diunggah.',
                category: ToolCategory::Security, icon: 'sign',
                inputLabel: 'PDF', outputLabel: 'PDF', inputFormats: $pdf, accept: $pdfAccept,
                mode: 'editor', actionLabel: 'Simpan tanda tangan', keywords: ['ttd', 'tanda tangan', 'signature'],
                notes: [
                    'Ini tanda tangan visual (gambar), bukan tanda tangan digital bersertifikat. Keabsahan hukumnya tidak dijamin oleh Konvertin.',
                    $protectedNote,
                ],
                optionRules: ['objects' => ['required', 'json', 'max:12000000']],
            ),
            new Tool(
                slug: 'redact-pdf', name: 'Redact PDF', summary: 'Menghapus teks dan area sensitif secara permanen, termasuk metadata.',
                category: ToolCategory::Security, icon: 'redact',
                inputLabel: 'PDF', outputLabel: 'PDF', inputFormats: $pdf, accept: $pdfAccept,
                mode: 'editor', actionLabel: 'Terapkan redaksi', keywords: ['sensor', 'hitamkan', 'hapus data', 'privasi'],
                notes: [
                    'Teks, gambar, dan grafik di bawah area redaksi benar-benar dihapus dari isi PDF, bukan sekadar ditutup kotak hitam.',
                    'Metadata, lampiran, dan skrip ikut dibersihkan. Tindakan ini tidak dapat dibatalkan pada berkas hasil.',
                    $protectedNote,
                ],
                optionRules: [
                    'areas' => ['nullable', 'json', 'max:200000'],
                    'terms' => ['nullable', 'required_without:options.areas', 'string', 'max:2000'],
                ],
            ),
            new Tool(
                slug: 'compare-pdf', name: 'Compare PDF', summary: 'Membandingkan dua versi PDF dan menandai teks yang berubah.',
                category: ToolCategory::Security, icon: 'compare',
                inputLabel: 'PDF', outputLabel: 'Laporan', inputFormats: $pdf, accept: $pdfAccept,
                acceptsMultiple: true, minFiles: 2, maxFiles: 2, mode: 'compare', actionLabel: 'Bandingkan',
                keywords: ['beda', 'revisi', 'diff', 'versi'],
                notes: [
                    'Berkas pertama dianggap versi lama, berkas kedua versi baru.',
                    'Perbandingan berbasis teks. Perubahan pada gambar atau tata letak saja tidak terdeteksi.',
                    $protectedNote,
                ],
            ),

            // -------------------------------------------------------------------- Forms
            new Tool(
                slug: 'pdf-forms', name: 'PDF Forms', summary: 'Mengisi field formulir PDF dan menambahkan field teks atau checkbox baru.',
                category: ToolCategory::Forms, icon: 'form',
                inputLabel: 'PDF', outputLabel: 'PDF', inputFormats: $pdf, accept: $pdfAccept,
                mode: 'forms', actionLabel: 'Simpan formulir', keywords: ['isi', 'formulir', 'field', 'form'],
                notes: ['Field yang sudah ada dideteksi otomatis: teks, checkbox, radio, dan dropdown.', 'Field baru yang bisa ditambahkan: teks dan checkbox.', $protectedNote],
                optionRules: [
                    'fields' => ['nullable', 'json', 'max:200000'],
                    'new_fields' => ['nullable', 'json', 'max:200000'],
                    'flatten' => ['nullable', 'boolean'],
                ],
            ),

            // ---------------------------------------------------------------- Translate
            new Tool(
                slug: 'translate-pdf', name: 'Translate PDF', summary: 'Menerjemahkan PDF dengan mempertahankan posisi judul, paragraf, dan tabel.',
                category: ToolCategory::Translate, icon: 'translate',
                inputLabel: 'PDF', outputLabel: 'PDF', inputFormats: $pdf, accept: $pdfAccept,
                actionLabel: 'Terjemahkan', keywords: ['terjemah', 'bahasa', 'translate', 'inggris', 'english'],
                notes: [
                    'Teks dokumen dikirim ke layanan penerjemahan Claude (Anthropic) untuk diterjemahkan. Jangan gunakan untuk dokumen rahasia.',
                    'Terjemahan ditulis di posisi teks aslinya. Teks yang lebih panjang dari aslinya diperkecil agar muat, sehingga tata letak bisa sedikit berbeda.',
                    'PDF hasil pindai perlu di-OCR dulu. Maksimal 50 halaman per dokumen.',
                ],
                optionRules: [
                    'source' => ['required', 'in:auto,id,en,ms,ar,zh,ja,ko,de,fr,es,nl'],
                    'target' => ['required', 'in:id,en,ms,ar,zh,ja,ko,de,fr,es,nl', 'different:options.source'],
                ],
            ),
            new Tool(
                slug: 'create-workflow', name: 'Create Workflow', summary: 'Menjalankan beberapa alat berurutan dalam satu proses, misalnya gabung, kompres, lalu kunci.',
                category: ToolCategory::Organize, icon: 'workflow',
                inputLabel: 'PDF', outputLabel: 'PDF', inputFormats: $pdf, accept: $pdfAccept,
                acceptsMultiple: true, mode: 'workflow', actionLabel: 'Jalankan workflow',
                keywords: ['otomatis', 'alur', 'rangkaian', 'batch'],
                notes: [
                    'Setiap langkah berjalan hanya bila langkah sebelumnya berhasil.',
                    'Preset workflow hanya disimpan di peramban Anda.',
                    'Merge dan Unlock hanya bisa menjadi langkah pertama; Protect hanya bisa menjadi langkah terakhir.',
                ],
                optionRules: ['steps' => ['required', 'json', 'max:20000']],
            ),
        ];

        return collect($tools)->keyBy(fn (Tool $tool): string => $tool->slug)->all();
    }
}
