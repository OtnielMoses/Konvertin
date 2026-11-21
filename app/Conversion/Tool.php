<?php

namespace App\Conversion;

/**
 * Satu alat pengolahan PDF beserta aturan masukan, opsi, dan tampilannya.
 */
final readonly class Tool
{
    /**
     * @param  list<string>  $inputFormats  Format hasil deteksi isi berkas yang diterima.
     * @param  list<string>  $notes  Catatan perilaku alat yang ditampilkan kepada Pengguna.
     * @param  array<string, mixed>  $optionRules  Aturan validasi Laravel untuk field options.*.
     * @param  array<string, array{label: string, formats: list<string>, accept: string, required?: bool}>  $auxFiles  Berkas pendamping, misalnya gambar watermark.
     * @param  list<string>  $keywords  Kata kunci pencarian tambahan.
     * @param  bool  $batch  Mesin mengubah satu berkas sekali jalan; banyak berkas diproses bergantian lalu digabung atau dikemas ZIP.
     * @param  bool  $batchMerge  Hasil batch boleh digabung menjadi satu PDF. Bila tidak, selalu dikemas ZIP dengan laporan per berkas.
     * @param  bool  $queue  Di peramban, banyak berkas diproses bergantian (satu permintaan per berkas) dengan status, ulang, dan batal per berkas.
     * @param  bool  $local  Dapat diproses langsung di peramban (pdf-lib) tanpa mengunggah berkas.
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $summary,
        public ToolCategory $category,
        public string $icon,
        public string $inputLabel,
        public string $outputLabel,
        public array $inputFormats,
        public string $accept,
        public bool $acceptsMultiple = false,
        public int $minFiles = 1,
        public ?int $maxFiles = null,
        public bool $usesUrl = false,
        public string $actionLabel = 'Proses',
        public string $mode = 'simple',
        public ?string $group = null,
        public array $notes = [],
        public array $optionRules = [],
        public array $auxFiles = [],
        public array $keywords = [],
        public bool $batch = false,
        public bool $batchMerge = true,
        public bool $queue = false,
        public bool $local = false,
    ) {}

    public function isMerge(): bool
    {
        return $this->slug === 'merge-pdf';
    }

    /**
     * Ekstensi yang diperiksa peramban sebelum unggah. Pemeriksaan isi tetap
     * dilakukan di server (NFR-SEC-2).
     *
     * @return list<string>
     */
    public function clientExtensions(): array
    {
        return self::extensionsFor($this->inputFormats);
    }

    /**
     * @param  list<string>  $formats
     * @return list<string>
     */
    public static function extensionsFor(array $formats): array
    {
        return collect($formats)
            ->flatMap(fn (string $format): array => $format === 'jpg' ? ['jpg', 'jpeg'] : [$format])
            ->values()
            ->all();
    }

    /**
     * Keterangan format masukan untuk Pengguna, misalnya "DOC atau DOCX".
     */
    public function inputFormatsText(): string
    {
        $labels = array_map('strtoupper', $this->inputFormats);

        return count($labels) > 1
            ? implode(', ', array_slice($labels, 0, -1)).' atau '.end($labels)
            : ($labels[0] ?? '');
    }

    /**
     * Teks yang dicocokkan oleh pencarian alat di Beranda.
     */
    public function searchText(): string
    {
        return mb_strtolower(implode(' ', [$this->name, $this->summary, $this->category->label(), ...$this->keywords]));
    }

    public function usesPreview(): bool
    {
        return in_array($this->mode, ['pages', 'editor', 'compare', 'forms', 'preview'], true);
    }
}
