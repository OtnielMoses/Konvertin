<?php

namespace App\Conversion;

use Closure;

/**
 * Kemajuan satu proses konversi untuk antarmuka. Setiap bagian pekerjaan dapat
 * berjalan pada potongan rentang 0..1 milik induknya, sehingga kemajuan
 * keseluruhan selalu naik meskipun pekerjaannya bersarang (misalnya satu berkas
 * dari banyak berkas). Bila tidak ada yang mendengarkan, laporan diabaikan.
 *
 * Keterangan hanya berisi langkah umum ("Menempatkan foto 2 dari 5"), tidak
 * pernah isi dokumen, dan tidak dicatat ke log.
 */
class ProgressReporter
{
    /** @var (Closure(?float, string): void)|null */
    private ?Closure $sink = null;

    /** @var list<array{0: float, 1: float}> */
    private array $scopes = [[0.0, 1.0]];

    /** @var list<string> */
    private array $prefixes = [];

    private float $last = 0.0;

    /**
     * @param  Closure(?float, string): void  $sink  Menerima kemajuan keseluruhan (0..1, atau null bila tidak terukur) dan keterangan.
     */
    public function listen(Closure $sink): void
    {
        $this->sink = $sink;
    }

    /**
     * @param  float|null  $fraction  Kemajuan di dalam rentang saat ini, atau null bila tahap ini tidak dapat diukur.
     */
    public function report(?float $fraction, string $label): void
    {
        if ($this->sink === null) {
            return;
        }

        $overall = null;

        if ($fraction !== null) {
            [$start, $end] = end($this->scopes);
            // Never move backwards, even if a nested step reports out of order.
            $overall = max($this->last, $start + ($end - $start) * max(0.0, min(1.0, $fraction)));
            $this->last = $overall;
        }

        ($this->sink)($overall, implode('', $this->prefixes).$label);
    }

    /**
     * Menjalankan pekerjaan pada potongan [from, to] dari rentang saat ini. Awalan
     * keterangan (misalnya "Berkas 2 dari 3 · ") ditambahkan ke setiap laporan di dalamnya.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function within(float $from, float $to, Closure $work, ?string $prefix = null): mixed
    {
        [$start, $end] = end($this->scopes);
        $this->scopes[] = [$start + ($end - $start) * $from, $start + ($end - $start) * $to];

        if ($prefix !== null) {
            $this->prefixes[] = $prefix;
        }

        try {
            return $work();
        } finally {
            array_pop($this->scopes);

            if ($prefix !== null) {
                array_pop($this->prefixes);
            }
        }
    }

    /**
     * Baris kemajuan dari mesin Python: "@@progress {"fraction": 0.5, "label": "..."}".
     */
    public function engineLine(string $line): void
    {
        if (! str_starts_with($line, '@@progress ')) {
            return;
        }

        $data = json_decode(substr($line, 11), true);

        if (is_array($data) && is_string($data['label'] ?? null)) {
            $fraction = isset($data['fraction']) && is_numeric($data['fraction']) ? (float) $data['fraction'] : null;
            $this->report($fraction, mb_substr($data['label'], 0, 120));
        }
    }
}
