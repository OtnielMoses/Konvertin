<?php

namespace App\Http\Controllers;

use App\Conversion\ConversionFailed;
use App\Conversion\ConversionService;
use App\Conversion\ProgressReporter;
use App\Conversion\Tool;
use App\Conversion\ToolCatalog;
use App\Conversion\UploadLimits;
use App\Conversion\Workspace;
use App\Conversion\WorkspaceManager;
use App\Http\Requests\ConvertRequest;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ToolController extends Controller
{
    public const OWNER_SESSION_KEY = 'konvertin_owner';

    public function __construct(
        private ToolCatalog $catalog,
        private ConversionService $conversions,
        private WorkspaceManager $workspaces,
        private UploadLimits $limits,
        private ProgressReporter $progress,
    ) {}

    /**
     * Halaman fitur konversi (UI-02, UI-03).
     */
    public function show(string $tool): View
    {
        $definition = $this->catalog->find($tool) ?? abort(404);

        return view('tool', [
            'tool' => $definition,
            'available' => $this->conversions->isAvailable($definition),
            'processingNote' => $this->conversions->processingNote($definition),
            'maxFileMb' => round($this->limits->fileBytes() / 1024 / 1024, 1),
            // Alat berantrean menerima banyak berkas di peramban, tetapi mengirimnya satu per satu.
            'maxFiles' => $definition->maxFiles ?? ($definition->acceptsMultiple || $definition->queue ? $this->limits->maxFiles() : 1),
            'maxFileBytes' => $this->limits->fileBytes(),
            'maxRequestBytes' => $this->limits->requestBytes(),
            'maxImageBytes' => $this->limits->imageBytes(),
            'maxPages' => (int) config('konvertin.max_pages'),
            'ttlMinutes' => (int) config('konvertin.ttl_minutes'),
            'wordReady' => $definition->mode === 'pack' && $this->conversions->isAvailable($this->catalog->find('word-to-pdf')),
            'workflowTools' => $definition->slug === 'create-workflow'
                ? collect($this->catalog->workflowSteps())->keys()->map(fn (string $slug) => $this->catalog->find($slug))
                : collect(),
        ]);
    }

    /**
     * Menjalankan konversi secara sinkron (TBD-12) dan menyajikan tautan unduh.
     */
    public function convert(ConvertRequest $request): JsonResponse|View|RedirectResponse|StreamedResponse
    {
        $definition = $request->tool();

        // Klik ganda atau permintaan yang dikirim ulang tidak memulai proses yang sama dua kali.
        $requestId = (string) $request->header('X-Konvertin-Request', '');

        if (preg_match('/^[A-Za-z0-9-]{16,64}$/', $requestId) === 1
            && ! Cache::add('konvertin:request:'.hash('sha256', $this->ownerToken($request).'|'.$requestId), true, now()->addMinutes(30))) {
            return response()->json(['message' => __('konvertin.duplicate_request'), 'failure' => 'duplicate'], 409);
        }

        set_time_limit((int) config('konvertin.process_timeout') * max(1, count($request->uploads())) + 60);

        // Pembersihan berkala tambahan, agar berkas kedaluwarsa tetap terhapus
        // meski penjadwal belum berjalan (4.14/REQ-4).
        $this->workspaces->purgeExpired();

        $run = fn () => $this->conversions->convert(
            $definition,
            $this->ownerToken($request),
            $request->uploads(),
            $definition->usesUrl ? (string) $request->validated('url') : null,
            $definition->usesUrl ? [] : $request->toolOptions(),
            $request->auxUploads(),
        );

        if ($request->expectsJson() && $request->header('X-Konvertin-Progress') === '1') {
            return $this->streamWithProgress($definition, $request, $run);
        }

        try {
            $workspace = $run();
        } catch (ConversionFailed $exception) {
            $message = $exception->failure->message();

            if ($request->expectsJson()) {
                return response()->json(['message' => $message, 'failure' => $exception->failure->value], 422);
            }

            return back()->withInput($request->only('url'))->withErrors(['conversion' => $message]);
        }

        $payload = $this->payload($definition, $workspace);

        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return view('result', [...$payload, 'tool' => $definition]);
    }

    /**
     * Respons bertahap (NDJSON): satu baris per langkah kemajuan selama proses
     * berjalan, lalu satu baris akhir berisi hasil ("done") atau galat ("failed").
     * Validasi dan batas laju sudah lolos sebelum aliran dimulai.
     *
     * @param  Closure(): Workspace  $run
     */
    private function streamWithProgress(Tool $definition, Request $request, Closure $run): StreamedResponse
    {
        // Token pemilik disimpan ke sesi sebelum header dikirim.
        $this->ownerToken($request);

        return response()->stream(function () use ($definition, $run): void {
            // Leave PHP's own output buffers so each line reaches the browser at once.
            // Tests capture the stream through a buffer of their own, so keep it there.
            if (! app()->runningUnitTests()) {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
            }

            $send = function (array $event): void {
                echo json_encode($event, JSON_UNESCAPED_UNICODE)."\n";
                flush();
            };

            $this->progress->listen(fn (?float $fraction, string $label) => $send([
                'type' => 'progress',
                'fraction' => $fraction === null ? null : round($fraction, 4),
                'label' => $label,
            ]));

            try {
                $send(['type' => 'done', 'payload' => $this->payload($definition, $run())]);
            } catch (ConversionFailed $exception) {
                $send(['type' => 'failed', 'message' => $exception->failure->message(), 'failure' => $exception->failure->value]);
            } catch (Throwable $exception) {
                report($exception);
                $send(['type' => 'failed', 'message' => __('konvertin.msg06'), 'failure' => 'generic']);
            }
        }, 200, [
            'Content-Type' => 'application/x-ndjson; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Tool $definition, Workspace $workspace): array
    {
        $result = $workspace->result();

        $bytes = (int) filesize((string) ($result['file'] ?? ''));
        $isZip = str_starts_with((string) ($result['mime'] ?? ''), 'application/zip');

        return [
            'id' => $workspace->id,
            'download_url' => route('download', $workspace->id),
            'filename' => $result['name'] ?? '',
            'size' => $this->humanFileSize($bytes),
            'bytes' => $bytes,
            // Satu berkas di dalam ZIP (misalnya satu gambar), tanpa menghapus hasil.
            'member_base' => $isZip ? url('unduh/'.$workspace->id.'/berkas') : null,
            'expires_in_minutes' => $this->workspaces->ttlMinutes(),
            'tool' => $definition->slug,
            'report' => $workspace->report(),
            'preview_url' => DownloadController::previewable((string) ($result['mime'] ?? ''))
                ? route('download.preview', $workspace->id)
                : null,
            'preview_type' => match (true) {
                str_starts_with((string) ($result['mime'] ?? ''), 'application/pdf') => 'pdf',
                str_starts_with((string) ($result['mime'] ?? ''), 'image/') => 'image',
                default => 'text',
            },
        ];
    }

    /**
     * Ukuran berkas berformat Indonesia (koma desimal) tanpa ekstensi intl.
     */
    private function humanFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',').' '.$units[$unit];
    }

    private function ownerToken(Request $request): string
    {
        $token = $request->session()->get(self::OWNER_SESSION_KEY);

        if (! is_string($token) || strlen($token) < 40) {
            $token = Str::random(64);
            $request->session()->put(self::OWNER_SESSION_KEY, $token);
        }

        return $token;
    }
}
