<?php

namespace Tests\Feature;

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * data:cek-kosong: daftar menu panel admin yang tabelnya masih kosong (alat bantu sebelum demo).
 */
class CheckEmptyMenusTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_empty_menus_and_summarises_the_count(): void
    {
        Artisan::call('data:cek-kosong');
        $output = Artisan::output();

        $this->assertStringContainsString('menu diperiksa', $output);
        $this->assertStringContainsString('Banner / Carousel', $output, 'Menu tanpa data muncul di daftar kosong.');
    }

    public function test_a_menu_with_rows_is_hidden_by_default_and_shown_with_the_all_option(): void
    {
        Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);

        Artisan::call('data:cek-kosong');
        $this->assertStringNotContainsString('Toko/Dealer', Artisan::output());

        Artisan::call('data:cek-kosong', ['--semua' => true]);
        $this->assertStringContainsString('Toko/Dealer', Artisan::output());
    }
}
