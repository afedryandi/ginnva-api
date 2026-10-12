<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Link "Ajak Teman" (/r/{code}) tetap mengarah ke Play Store dengan kode yang dibersihkan setelah dipindah dari closure
 * di routes/web.php ke ReferralRedirectController. (Kemampuan route di-cache dibuktikan langsung di server dengan
 * `php artisan route:cache`, bukan di tes: perintah itu menulis berkas cache yang akan memengaruhi tes lain.)
 */
class RouteCacheTest extends TestCase
{
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
