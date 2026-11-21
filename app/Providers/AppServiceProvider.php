<?php

namespace App\Providers;

use App\Console\Commands\ServeCommand;
use App\Conversion\ProgressReporter;
use App\Conversion\ToolCatalog;
use App\Conversion\WorkspaceManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Console\ServeCommand as BaseServeCommand;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ToolCatalog::class);
        $this->app->scoped(ProgressReporter::class);

        $this->app->singleton(WorkspaceManager::class, fn ($app): WorkspaceManager => new WorkspaceManager(
            $app->make(Filesystem::class),
            (string) config('konvertin.workspace_path'),
            (int) config('konvertin.ttl_minutes'),
        ));

        // `php artisan serve` (dan `php artisan dev`) memakai batas unggah Konvertin.
        $this->app->extend(BaseServeCommand::class, fn ($command, $app) => $app->make(ServeCommand::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->trustConfiguredProxies();

        if (config('konvertin.force_https')) {
            URL::forceScheme('https');
        }

        // NFR-SEC-8: batas laju konversi per alamat klien (TBD-06).
        RateLimiter::for('conversions', fn (Request $request): Limit => Limit::perMinute(
            max(1, (int) config('konvertin.rate_limit_per_minute')),
        )->by($request->ip()));
    }

    /**
     * Mempercayai header X-Forwarded-* dari proxy yang disebut di konfigurasi,
     * agar skema HTTPS dan alamat klien terbaca benar di balik proxy.
     */
    private function trustConfiguredProxies(): void
    {
        $proxies = trim((string) config('konvertin.trusted_proxies'));

        if ($proxies === '') {
            return;
        }

        TrustProxies::at($proxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)))));
    }
}
