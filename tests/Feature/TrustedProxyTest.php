<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Tests\TestCase;

/**
 * Di balik reverse proxy, HTTPS diakhiri di proxy. Tanpa proxy tepercaya,
 * pemaksaan HTTPS mengalihkan tanpa henti dan semua pengguna berbagi satu IP.
 */
class TrustedProxyTest extends TestCase
{
    protected function tearDown(): void
    {
        TrustProxies::flushState();

        parent::tearDown();
    }

    private function bootWith(?string $trustedProxies): void
    {
        config(['konvertin.force_https' => true, 'konvertin.trusted_proxies' => $trustedProxies]);
        TrustProxies::flushState();
        $this->app->getProvider(AppServiceProvider::class)->boot();
    }

    public function test_https_forwarded_by_a_trusted_proxy_is_served_without_a_redirect(): void
    {
        $this->bootWith('*');

        $this->get('http://konvertin.test/', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7'])
            ->assertOk()
            ->assertHeader('Strict-Transport-Security');
    }

    public function test_forwarded_headers_are_ignored_without_trusted_proxies(): void
    {
        $this->bootWith(null);

        $this->get('http://konvertin.test/', ['X-Forwarded-Proto' => 'https'])
            ->assertRedirect('https://konvertin.test')
            ->assertStatus(301);
    }

    public function test_only_listed_proxy_addresses_are_trusted(): void
    {
        $this->bootWith('10.0.0.1, 10.0.0.2');

        $this->get('http://konvertin.test/', ['X-Forwarded-Proto' => 'https'])
            ->assertStatus(301);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->get('http://konvertin.test/', ['X-Forwarded-Proto' => 'https'])
            ->assertOk();
    }
}
