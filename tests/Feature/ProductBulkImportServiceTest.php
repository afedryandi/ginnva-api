<?php

namespace Tests\Feature;

use App\Models\FilmProduct;
use App\Services\ProductBulkImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class TestRowsExport implements FromArray
{
    public function __construct(private array $rows) {}

    public function array(): array
    {
        return $this->rows;
    }
}

/**
 * Audit Majoo f27 ("Impor/Ekspor bulk data produk + riwayat impor"),
 * dibangun 2026-09-22. Fokus test: hanya UPDATE produk yang sudah ada
 * (SKU tidak ditemukan dilewati, bukan dibuat baru), kolom kosong tidak
 * menimpa data yang ada, dan hasilnya dicatat ke ProductImportLog.
 */
class ProductBulkImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private function writeTestExcel(array $rows, string $relativePath): string
    {
        Excel::store(new TestRowsExport($rows), $relativePath, 'local');

        return Storage::disk('local')->path($relativePath);
    }

    public function test_updates_existing_product_by_sku_and_logs_result(): void
    {
        $product = FilmProduct::create([
            'sku' => 'PPF-TEST-01',
            'name' => 'PPF Test Lama',
            'product_type' => 'ppf',
            'base_price' => 1_000_000,
            'is_active' => true,
        ]);

        $rows = [
            ['SKU', 'Nama Produk', 'Harga Dasar (Rp)', 'Aktif (Y/T)'],
            ['PPF-TEST-01', 'PPF Test Baru', '1500000', 'Y'],
            ['SKU-TIDAK-ADA', 'Produk Fiktif', '999999', 'Y'],
        ];

        $absolutePath = $this->writeTestExcel($rows, 'test-import-' . uniqid() . '.xlsx');

        $log = app(ProductBulkImportService::class)->importFromFile($absolutePath, 'test-import.xlsx', null);

        $this->assertEquals(1, $log->updated_count);
        $this->assertEquals(1, $log->skipped_count);
        $this->assertEquals(2, $log->total_rows);
        $this->assertNotEmpty($log->errors);

        $product->refresh();
        $this->assertEquals('PPF Test Baru', $product->name);
        $this->assertEquals(1_500_000.00, (float) $product->base_price);

        $this->assertDatabaseMissing('film_products', ['sku' => 'SKU-TIDAK-ADA']);
    }

    public function test_blank_columns_do_not_overwrite_existing_data(): void
    {
        $product = FilmProduct::create([
            'sku' => 'PPF-TEST-02',
            'name' => 'PPF Test Nama Asli',
            'product_type' => 'ppf',
            'base_price' => 2_000_000,
            'is_active' => true,
        ]);

        $rows = [
            ['SKU', 'Nama Produk', 'Harga Dasar (Rp)', 'Aktif (Y/T)'],
            ['PPF-TEST-02', '', '2500000', ''],
        ];

        $absolutePath = $this->writeTestExcel($rows, 'test-import-blank-' . uniqid() . '.xlsx');

        app(ProductBulkImportService::class)->importFromFile($absolutePath, 'test-import-blank.xlsx', null);

        $product->refresh();
        $this->assertEquals('PPF Test Nama Asli', $product->name); // tidak berubah
        $this->assertEquals(2_500_000.00, (float) $product->base_price); // berubah
        $this->assertTrue($product->is_active); // tidak berubah
    }
}
