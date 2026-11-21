<?php

namespace App\Http\Controllers;

use App\Conversion\ConversionResult;
use App\Conversion\ToolCatalog;
use App\Conversion\Workspace;
use App\Conversion\WorkspaceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class DownloadController extends Controller
{
    public function __construct(private WorkspaceManager $workspaces) {}

    /**
     * Mengirim berkas hasil satu kali, lalu menghapus workspace setelah
     * pengiriman selesai (UC-14 Basic Path).
     */
    public function __invoke(Request $request, string $workspace): BinaryFileResponse|Response
    {
        $found = $this->workspaces->find($workspace);
        $result = $found?->result();

        $unavailable = $found === null
            || $found->status() !== 'finished'
            || $result === null
            || ! is_file($result['file'])
            || $this->workspaces->isExpired($found);

        if ($unavailable) {
            if ($found !== null && $found->status() !== 'downloading') {
                $this->workspaces->destroy($found);
            }

            // EP-2: MSG-07, tidak ada berkas yang dikirim. Arahkan kembali ke alatnya.
            $slug = $found?->meta()['tool'] ?? null;
            $tool = is_string($slug) ? app(ToolCatalog::class)->find($slug) : null;

            return response()->view('download-gone', ['tool' => $tool], 410);
        }

        $owner = $request->session()->get(ToolController::OWNER_SESSION_KEY);

        // NFR-SEC-4: hanya sesi yang menjalankan proses yang dapat mengunduh.
        if (! is_string($owner) || ! $this->workspaces->belongsTo($found, $owner)) {
            abort(404);
        }

        $found->markDownloading();

        app()->terminating(fn () => $this->workspaces->destroy($found));

        $response = response()->download($result['file'], $result['name'], [
            'Content-Type' => $result['mime'],
            'X-Content-Type-Options' => 'nosniff',
        ]);

        // Hasil konversi tidak boleh tersimpan di cache peramban atau proxy.
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->setMaxAge(0);

        return $response;
    }

    /**
     * Pratinjau hasil sebelum diunduh. Hanya untuk pemilik sesi, hanya untuk
     * format yang dapat ditampilkan di peramban (PDF dan Markdown), dan tidak
     * menghapus workspace: tautan unduh tetap berlaku satu kali.
     */
    public function preview(Request $request, string $workspace): BinaryFileResponse|Response
    {
        $found = $this->workspaces->find($workspace);
        $result = $found?->result();

        if ($found === null
            || $found->status() !== 'finished'
            || $result === null
            || ! is_file($result['file'])
            || $this->workspaces->isExpired($found)
            || ! self::previewable($result['mime'])) {
            abort(404);
        }

        $owner = $request->session()->get(ToolController::OWNER_SESSION_KEY);

        if (! is_string($owner) || ! $this->workspaces->belongsTo($found, $owner)) {
            abort(404);
        }

        $response = response()->download($result['file'], 'pratinjau', [
            'Content-Type' => $result['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);

        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->setMaxAge(0);

        return $response;
    }

    /**
     * Satu berkas di dalam hasil ZIP (misalnya satu gambar PDF to JPG) untuk
     * pratinjau atau unduhan per berkas. Hanya untuk pemilik sesi, dan tidak
     * menghapus hasil: ZIP lengkap tetap dapat diunduh.
     */
    public function member(Request $request, string $workspace, int $index): StreamedResponse
    {
        $found = $this->ownedFinished($request, $workspace);
        $result = $found?->result();

        if ($result === null || ! str_starts_with($result['mime'], 'application/zip')) {
            abort(404);
        }

        $zip = new ZipArchive;

        if ($zip->open($result['file'], ZipArchive::RDONLY) !== true || $index < 0 || $index >= $zip->numFiles) {
            abort(404);
        }

        $name = basename((string) $zip->getNameIndex($index));
        $zip->close();
        $mime = match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => 'application/pdf',
            default => abort(404),
        };
        $disposition = $request->boolean('unduh') ? 'attachment' : 'inline';
        $safeName = preg_replace('/[^\w .()-]+/u', '_', $name) ?? 'berkas';

        return response()->stream(function () use ($result, $index): void {
            $zip = new ZipArchive;
            $zip->open($result['file'], ZipArchive::RDONLY);
            $stream = $zip->getStreamIndex($index);

            if ($stream !== false) {
                fpassthru($stream);
                fclose($stream);
            }

            $zip->close();
        }, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => $disposition.'; filename="'.$safeName.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * Antrean: semua hasil yang berhasil dan belum diunduh dikemas menjadi satu ZIP.
     * Hasil asli disalin, bukan dipindah, jadi tautan per berkas tetap berlaku
     * sampai diunduh atau kedaluwarsa.
     */
    public function bundle(Request $request): JsonResponse
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.(int) config('konvertin.max_files')],
            'ids.*' => ['required', 'string', 'regex:/^'.WorkspaceManager::ID_PATTERN.'$/'],
        ])['ids'];

        $owner = $request->session()->get(ToolController::OWNER_SESSION_KEY);
        $sources = collect($ids)->unique()
            ->map(fn (string $id): ?Workspace => $this->ownedFinished($request, $id))
            ->filter()
            ->values();

        if (! is_string($owner) || $sources->isEmpty()) {
            return response()->json(['message' => __('konvertin.bundle_empty')], 410);
        }

        $bundle = $this->workspaces->create($owner, 'bundle');
        $target = $bundle->outputDirectory().DIRECTORY_SEPARATOR.'result.zip';
        $zip = new ZipArchive;
        $zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $used = [];

        foreach ($sources as $source) {
            $result = $source->result();
            $stem = pathinfo($result['name'], PATHINFO_FILENAME);
            $extension = pathinfo($result['name'], PATHINFO_EXTENSION);
            $name = $stem;
            $number = 1;

            while (isset($used[mb_strtolower($name.'.'.$extension)])) {
                $number++;
                $name = $stem.' ('.$number.')';
            }

            $used[mb_strtolower($name.'.'.$extension)] = true;
            $zip->addFile($result['file'], $name.'.'.$extension);
        }

        $zip->close();
        $bundle->markFinished(new ConversionResult($target, 'hasil-konvertin.zip', 'application/zip', ['files' => $sources->count()]));

        return response()->json([
            'download_url' => route('download', $bundle->id),
            'filename' => 'hasil-konvertin.zip',
            'files' => $sources->count(),
            'skipped' => count($ids) - $sources->count(),
            'bytes' => (int) filesize($target),
        ]);
    }

    /**
     * Workspace selesai milik sesi ini yang hasilnya masih ada, atau null.
     */
    private function ownedFinished(Request $request, string $id): ?Workspace
    {
        $found = $this->workspaces->find($id);
        $result = $found?->result();
        $owner = $request->session()->get(ToolController::OWNER_SESSION_KEY);

        if ($found === null
            || $found->status() !== 'finished'
            || $result === null
            || ! is_file($result['file'])
            || $this->workspaces->isExpired($found)
            || ! is_string($owner)
            || ! $this->workspaces->belongsTo($found, $owner)) {
            return null;
        }

        return $found;
    }

    public static function previewable(string $mime): bool
    {
        return str_starts_with($mime, 'application/pdf') || str_starts_with($mime, 'text/markdown')
            || $mime === 'image/jpeg' || $mime === 'image/png';
    }
}
