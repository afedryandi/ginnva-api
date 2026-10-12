<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Semua route harus bisa di-cache (`php artisan route:cache`, mempercepat tiap permintaan di production): tidak boleh ada
 * route berbasis closure. Link "Ajak Teman" (/r/{code}) tetap mengarah ke Play Store dengan kode yang dibersihkan.
 */
class RouteCacheTest extends TestCase
{
    public function test_no_route_uses_a_closure(): void
    {
        $closures = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => $route->getAction('uses') instanceof \Closure)
            ->map(fn ($route) => $route->methods()[0] . ' /' . ltrim($route->uri(), '/'))
            ->values()
            ->all();

        $this->assertSame([], $closures, 'Route berbasis closure tidak bisa di-cache: pindahkan ke controller.');
    }

    public function test_referral_link_redirects_to_the_play_store_with_a_clean_code(): void
    {
        $this->get('/r/ab12cd')
            ->assertSuccessful()
            ->assertSee('referrer=ref%3DAB12CD', false);
    }

    public function test_referral_link_rejects_bad_codes(): void
    {
        $this->get('/r/abc-123')->assertNotFound();
        $this->get('/r/abcdefghijk')->assertNotFound();
    }
}
