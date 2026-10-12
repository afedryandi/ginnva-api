<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Transport aman: header keamanan selalu ada; di production HTTP dialihkan ke HTTPS (308) dan HSTS dikirim hanya lewat
 * HTTPS. Di luar production (lokal/tes) tidak ada pengalihan supaya pengembangan lewat http tidak rusak.
 */
class SecureTransportTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_always_present(): void
    {
        $response = $this->getJson('/api/rewards')->assertSuccessful();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $this->assertFalse($response->headers->has('Strict-Transport-Security'), 'HSTS tidak dikirim di luar production.');
    }

    public function test_plain_http_is_not_redirected_outside_production(): void
    {
        $this->get('http://localhost/api/rewards')->assertSuccessful();
    }

    public function test_production_redirects_http_to_https_keeping_the_path_and_query(): void
    {
        $this->app['env'] = 'production';

        $response = $this->get('http://api.example.test/api/rewards?page=2');

        $response->assertStatus(308);
        $response->assertRedirect('https://api.example.test/api/rewards?page=2');
    }

    public function test_production_https_gets_hsts_and_no_redirect(): void
    {
        $this->app['env'] = 'production';

        $response = $this->get('https://api.example.test/api/rewards');

        $response->assertSuccessful();
        $response->assertHeader('Strict-Transport-Security', 'max-age=604800');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
