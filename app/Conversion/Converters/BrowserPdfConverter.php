<?php

namespace App\Conversion\Converters;

use App\Conversion\ConversionFailed;
use App\Conversion\ConversionFailure;
use App\Conversion\ConversionInput;
use App\Conversion\ConversionResult;
use App\Conversion\EngineLocator;
use App\Conversion\Tool;
use App\Conversion\UrlGuard;
use App\Conversion\Workspace;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Konversi halaman web ke PDF memakai Chrome, Chromium, atau Edge headless.
 *
 * Sebelum peramban dijalankan, alamat diperiksa sendiri oleh Sistem: setiap
 * pengalihan (redirect) divalidasi ulang dan host dikunci ke alamat IP
 * publik hasil resolusi, sehingga halaman utama tidak dapat diarahkan ke
 * jaringan internal (NFR-SEC-6).
 */
class BrowserPdfConverter extends ProcessConverter
{
    private const MAX_REDIRECTS = 5;

    public function __construct(EngineLocator $engines, private UrlGuard $guard)
    {
        parent::__construct($engines);
    }

    public function isAvailable(Tool $tool): bool
    {
        return $this->engines->browser() !== null;
    }

    public function convert(Tool $tool, ConversionInput $input, Workspace $workspace): ConversionResult
    {
        $this->stage('Membuka dan merekam halaman web');

        $binary = $this->engines->browser()
            ?? throw ConversionFailed::because(ConversionFailure::EngineUnavailable);

        [$url, $address] = $this->resolveFinalUrl((string) $input->url);

        $host = (string) parse_url($url, PHP_URL_HOST);
        $output = $workspace->outputDirectory().DIRECTORY_SEPARATOR.'result.pdf';

        $command = [
            $binary,
            '--headless=new',
            '--disable-gpu',
            '--no-first-run',
            '--no-default-browser-check',
            '--disable-extensions',
            '--disable-sync',
            '--hide-scrollbars',
            '--no-pdf-header-footer',
            '--run-all-compositor-stages-before-draw',
            '--virtual-time-budget=10000',
            '--user-data-dir='.$workspace->path.DIRECTORY_SEPARATOR.'browser-profile',
            '--print-to-pdf='.$output,
        ];

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            $command[] = '--host-resolver-rules=MAP '.$host.' '.$this->formatAddress($address);
        }

        $command[] = $url;

        $result = $this->run($command, $workspace->path);

        if (! is_file($output) || ! $this->isPdf($output)) {
            throw ConversionFailed::because(ConversionFailure::Generic, 'browser exit code '.$result->exitCode());
        }

        return $this->result($tool, $input, $output);
    }

    /**
     * Mengikuti pengalihan secara manual dan memvalidasi setiap tujuannya.
     *
     * @return array{0: string, 1: string} Alamat akhir dan IP publiknya.
     *
     * @throws ConversionFailed
     */
    private function resolveFinalUrl(string $url): array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $address = $this->guard->resolvePublicAddress($url);

            if ($address === null) {
                throw ConversionFailed::because(ConversionFailure::Unreachable, 'address rejected or unresolvable');
            }

            $parts = parse_url($url);
            $scheme = strtolower($parts['scheme'] ?? 'http');
            $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
            $host = trim((string) ($parts['host'] ?? ''), '[]');

            try {
                $caBundle = config('konvertin.engines.ca_bundle');

                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.$this->formatAddress($address)]],
                    'verify' => is_string($caBundle) && is_file($caBundle) ? $caBundle : true,
                ])
                    ->withUserAgent('Mozilla/5.0 (compatible; Konvertin HTML to PDF)')
                    ->connectTimeout(10)
                    ->timeout(20)
                    ->get($url);
            } catch (ConnectionException $exception) {
                throw new ConversionFailed(ConversionFailure::Unreachable, 'connection failed', $exception);
            }

            if ($response->redirect()) {
                $location = (string) $response->header('Location');

                if ($location === '') {
                    throw ConversionFailed::because(ConversionFailure::Unreachable, 'redirect without location');
                }

                $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));

                continue;
            }

            if ($response->failed()) {
                throw ConversionFailed::because(ConversionFailure::Unreachable, 'http status '.$response->status());
            }

            return [$url, $address];
        }

        throw ConversionFailed::because(ConversionFailure::Unreachable, 'too many redirects');
    }

    private function formatAddress(string $address): string
    {
        return str_contains($address, ':') ? '['.$address.']' : $address;
    }
}
