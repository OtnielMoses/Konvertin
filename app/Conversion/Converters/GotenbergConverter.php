<?php

namespace App\Conversion\Converters;

use App\Conversion\ConversionFailed;
use App\Conversion\ConversionFailure;
use App\Conversion\ConversionInput;
use App\Conversion\ConversionResult;
use App\Conversion\ProgressReporter;
use App\Conversion\Tool;
use App\Conversion\Workspace;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Konversi lewat layanan Gotenberg 9 milik sendiri (open-source, MIT), yang
 * menjalankan LibreOffice di container terpisah. Dengan begitu mesin yang
 * menjalankan Konvertin tidak perlu memasang LibreOffice atau Ghostscript.
 *
 * - Word, PowerPoint, Excel ke PDF: POST /forms/libreoffice/convert
 * - PDF ke PDF/A-2b: POST /forms/pdf/remediate dengan profile=PDF/A-2b.
 *   Gotenberg melaporkan pelanggaran yang tidak dapat diperbaiki pada header
 *   Gotenberg-Remediation-Residual; nilai di atas nol berarti hasil tidak
 *   memenuhi standar dan ditolak (4.11/REQ-13, NFR-SAF-2).
 */
class GotenbergConverter implements Converter
{
    public const PDFA_PROFILE = 'PDF/A-2b';

    public const RESIDUAL_HEADER = 'Gotenberg-Remediation-Residual';

    private const OFFICE_TOOLS = ['word-to-pdf', 'powerpoint-to-pdf', 'excel-to-pdf'];

    public function isAvailable(Tool $tool): bool
    {
        $supported = in_array($tool->slug, self::OFFICE_TOOLS, true) || $tool->slug === 'pdf-to-pdfa';

        return $supported && $this->baseUrl() !== null;
    }

    public function processingNote(Tool $tool): ?string
    {
        return 'Konversi ini dijalankan oleh layanan konversi milik Konvertin melalui koneksi terenkripsi. '
            .'Layanan itu tidak menyimpan berkas dan membuangnya setelah proses selesai.';
    }

    public function convert(Tool $tool, ConversionInput $input, Workspace $workspace): ConversionResult
    {
        app(ProgressReporter::class)->report(null, $tool->slug === 'pdf-to-pdfa' ? 'Mengubah ke standar arsip PDF/A' : 'Mengubah dokumen menjadi PDF');

        if ($this->baseUrl() === null) {
            throw ConversionFailed::because(ConversionFailure::EngineUnavailable);
        }

        $source = $input->firstFile();
        $output = $workspace->outputDirectory().DIRECTORY_SEPARATOR.'result.pdf';

        $request = $this->client()->sink($output);
        $handle = fopen($source, 'rb');

        try {
            if ($tool->slug === 'pdf-to-pdfa') {
                $request = $request->asMultipart()->attach('files', $handle, 'input.pdf');
                $response = $this->send(fn () => $request->post('/forms/pdf/remediate', ['profile' => self::PDFA_PROFILE]));
            } else {
                $request = $request->attach('files', $handle, basename($source));
                $response = $this->send(fn () => $request->post('/forms/libreoffice/convert'));
            }
        } finally {
            // Berkas yang masih terbuka tidak dapat dihapus di Windows (BR-03).
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if ($response->failed()) {
            throw ConversionFailed::because($this->failureFor($response, $output), 'gotenberg status '.$response->status());
        }

        if ($tool->slug === 'pdf-to-pdfa' && (int) $response->header(self::RESIDUAL_HEADER) > 0) {
            throw ConversionFailed::because(ConversionFailure::PdfaNotCompliant, 'residual violations '.$response->header(self::RESIDUAL_HEADER));
        }

        if (! $this->isPdf($output)) {
            throw ConversionFailed::because(ConversionFailure::Generic, 'gotenberg returned no pdf');
        }

        return new ConversionResult(
            path: $output,
            downloadName: $input->baseName.($tool->slug === 'pdf-to-pdfa' ? '-pdfa.pdf' : '.pdf'),
            mimeType: 'application/pdf',
        );
    }

    /**
     * Alamat layanan. Selain localhost, hanya HTTPS yang diterima agar berkas
     * Pengguna tidak pernah dikirim tanpa enkripsi (NFR-SEC-1).
     */
    private function baseUrl(): ?string
    {
        $url = rtrim((string) config('konvertin.engines.gotenberg.url'), '/');

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true))) {
            return $url;
        }

        return null;
    }

    private function client(): PendingRequest
    {
        $caBundle = config('konvertin.engines.ca_bundle');
        $username = (string) config('konvertin.engines.gotenberg.username');

        $client = Http::baseUrl((string) $this->baseUrl())
            ->connectTimeout(15)
            // Layanan gratis dapat "tidur" dan butuh waktu untuk bangun.
            ->timeout(max(30, (int) config('konvertin.process_timeout')))
            ->withOptions(['verify' => is_string($caBundle) && is_file($caBundle) ? $caBundle : true]);

        if ($username !== '') {
            $client = $client->withBasicAuth($username, (string) config('konvertin.engines.gotenberg.password'));
        }

        return $client;
    }

    /**
     * @param  callable(): Response  $request
     *
     * @throws ConversionFailed
     */
    private function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $exception) {
            $failure = str_contains(strtolower($exception->getMessage()), 'timed out')
                ? ConversionFailure::Timeout
                : ConversionFailure::ServiceUnreachable;

            throw new ConversionFailed($failure, 'gotenberg connection failed', $exception);
        }
    }

    /**
     * Respons diarahkan ke berkas hasil, sehingga pesan galat Gotenberg dibaca
     * dari berkas itu. Pesannya tidak dicatat karena dapat memuat nama berkas.
     */
    private function failureFor(Response $response, string $output): ConversionFailure
    {
        $message = strtolower((string) @file_get_contents($output, length: 2048));

        return match (true) {
            in_array($response->status(), [401, 403, 404, 502, 503, 504], true) => ConversionFailure::ServiceUnreachable,
            str_contains($message, 'password') || str_contains($message, 'encrypted') => ConversionFailure::Encrypted,
            default => ConversionFailure::Generic,
        };
    }

    private function isPdf(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = (string) fread($handle, 1024);
        fclose($handle);

        return str_contains($head, '%PDF-');
    }
}
