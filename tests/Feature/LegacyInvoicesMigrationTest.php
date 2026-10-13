<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migrasi pembersih tabel invoices lama (latihan rilis production 2026-10-13): tabel invoices bentuk BARU tidak boleh
 * tersentuh. (Penghapusan bentuk lama dibuktikan di salinan data production, bukan di tes, karena menghapus tabel di
 * tes akan merusak tes lain yang memakai skema yang sama.)
 */
class LegacyInvoicesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_cleanup_does_not_touch_the_new_shaped_invoices_table(): void
    {
        $this->assertTrue(Schema::hasTable('invoices'));
        $this->assertTrue(Schema::hasColumn('invoices', 'store_id'), 'bentuk baru (modul Invoice) ada');

        $migration = include database_path('migrations/2026_09_15_000001_a_drop_legacy_invoices_table.php');
        $migration->up();

        $this->assertTrue(Schema::hasTable('invoices'));
        $this->assertTrue(Schema::hasColumn('invoices', 'store_id'));
        $this->assertFalse(Schema::hasColumn('invoices', 'validated_by'));
    }

    public function test_the_cleanup_sorts_before_the_invoices_creation(): void
    {
        $files = collect(glob(database_path('migrations/2026_09_15_*.php')))->map(fn ($f) => basename($f, '.php'))->sort()->values()->all();

        $cleanup = array_search('2026_09_15_000001_a_drop_legacy_invoices_table', $files, true);
        $create = array_search('2026_09_15_000002_create_invoices_table', $files, true);

        $this->assertNotFalse($cleanup);
        $this->assertNotFalse($create);
        $this->assertLessThan($create, $cleanup);
    }
}
