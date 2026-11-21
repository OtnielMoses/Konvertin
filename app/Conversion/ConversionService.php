<?php

namespace App\Conversion;

use App\Conversion\Converters\Converter;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Menjalankan alur bersama seluruh alat (subbab 6.4): simpan berkas ke
 * workspace, panggil mesin, catat hasil, dan bersihkan saat gagal.
 */
class ConversionService
{
    public function __construct(
        private Container $container,
        private WorkspaceManager $workspaces,
        private FileSniffer $sniffer,
        private BatchConverter $batch,
        private ProgressReporter $progress,
        private ResultValidator $results,
    ) {}

    /**
     * Mesin untuk alat ini. Bila konfigurasi berisi beberapa kandidat, yang
     * pertama tersedia dipakai; bila tidak ada, kandidat pertama dikembalikan
     * agar alat dilaporkan "Belum disiapkan".
     */
    public function converterFor(Tool $tool): Converter
    {
        $configured = config('konvertin.converters.'.$tool->slug);
        $candidates = array_map(fn (string $class): Converter => $this->container->make($class), (array) $configured);

        foreach ($candidates as $candidate) {
            if ($candidate->isAvailable($tool)) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    public function isAvailable(Tool $tool): bool
    {
        return $this->converterFor($tool)->isAvailable($tool);
    }

    public function processingNote(Tool $tool): ?string
    {
        return $this->converterFor($tool)->processingNote($tool);
    }

    /**
     * @param  list<UploadedFile>  $uploads  Berkas yang sudah lolos validasi, sesuai urutan Pengguna.
     * @param  array<string, mixed>  $options  Opsi alat yang sudah tervalidasi.
     * @param  array<string, UploadedFile>  $auxUploads  Berkas pendamping yang sudah tervalidasi.
     *
     * @throws ConversionFailed
     */
    public function convert(
        Tool $tool,
        string $ownerToken,
        array $uploads = [],
        ?string $url = null,
        array $options = [],
        array $auxUploads = [],
    ): Workspace {
        $converter = $this->converterFor($tool);

        if (! $converter->isAvailable($tool)) {
            throw ConversionFailed::because(ConversionFailure::EngineUnavailable);
        }

        $workspace = $this->workspaces->create($ownerToken, $tool->slug);
        $this->progress->report(0, 'Menyimpan berkas di ruang kerja sementara');

        try {
            $files = [];

            foreach (array_values($uploads) as $index => $upload) {
                $files[] = $this->store($upload, $workspace->inputPath($index + 1, $this->formatOf($upload)));
            }

            $aux = [];

            foreach ($auxUploads as $key => $upload) {
                $aux[$key] = $this->store($upload, $workspace->path.DIRECTORY_SEPARATOR.'aux-'.Str::slug($key).'.'.$this->formatOf($upload));
            }

            $input = new ConversionInput(
                files: $files,
                baseName: $this->baseName($uploads, $url),
                url: $url,
                options: $options,
                aux: $aux,
                names: array_map(
                    fn (UploadedFile $upload): string => $this->cleanName(pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME)),
                    array_values($uploads),
                ),
            );

            $result = $this->progress->within(0.04, 0.97, fn () => $tool->batch && count($files) > 1
                ? $this->batch->convert($converter, $tool, $input, $workspace)
                : $converter->convert($tool, $input, $workspace));
            $this->progress->report(1, 'Memeriksa dan menyiapkan hasil untuk diunduh');
            $this->results->validate($result);
            $workspace->markFinished($result);

            return $workspace;
        } catch (Throwable $exception) {
            // UC-14 AP-1: berkas masukan dan hasil parsial dihapus segera.
            $this->workspaces->destroy($workspace);

            $failure = $exception instanceof ConversionFailed
                ? $exception
                : new ConversionFailed(ConversionFailure::Generic, 'unexpected error', $exception);

            // Hanya metadata teknis yang dicatat: tanpa nama berkas, isi, maupun opsi
            // (opsi dapat memuat kata sandi) (NFR-SEC-7).
            Log::warning('konvertin.conversion_failed', [
                'tool' => $tool->slug,
                'workspace' => $workspace->id,
                'failure' => $failure->failure->value,
                'detail' => $failure->getMessage(),
                'exception' => $exception::class,
            ]);

            throw $failure;
        }
    }

    private function formatOf(UploadedFile $upload): string
    {
        return $this->sniffer->detect((string) $upload->getRealPath()) ?? 'bin';
    }

    /**
     * Disalin, bukan dipindah: berkas unggahan sementara milik PHP dihapus
     * PHP sendiri di akhir permintaan, dan di Windows berkas yang dipindah
     * dapat tetap terkunci sehingga sulit dihapus.
     *
     * @throws ConversionFailed
     */
    private function store(UploadedFile $upload, string $target): string
    {
        if (! copy((string) $upload->getRealPath(), $target)) {
            throw ConversionFailed::because(ConversionFailure::Generic, 'could not store upload');
        }

        return $target;
    }

    /**
     * Nama dasar berkas hasil (TBD-25): nama berkas pertama tanpa ekstensi,
     * atau nama host untuk HTML to PDF. Karakter Unicode dipertahankan (6.3).
     *
     * @param  list<UploadedFile>  $uploads
     */
    private function baseName(array $uploads, ?string $url): string
    {
        $raw = match (true) {
            $url !== null => (string) parse_url($url, PHP_URL_HOST),
            $uploads !== [] => pathinfo($uploads[0]->getClientOriginalName(), PATHINFO_FILENAME),
            default => 'hasil',
        };

        return $this->cleanName($raw);
    }

    /**
     * Nama yang aman dipakai sebagai nama berkas atau judul bookmark.
     */
    private function cleanName(string $raw): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', $raw) ?? '';
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '', ' .');

        return $clean === '' ? 'hasil' : Str::limit($clean, 80, '');
    }
}
