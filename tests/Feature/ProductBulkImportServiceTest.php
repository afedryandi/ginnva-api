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

    // ------------------------------------------------------------- perubahan tercatat, harga dibaca benar

    private function product(string $sku, float $price = 1_000_000, string $name = 'Produk Uji'): FilmProduct
    {
        return FilmProduct::create(['sku' => $sku, 'name' => $name, 'product_type' => 'ppf', 'base_price' => $price, 'is_active' => true]);
    }

    private function import(array $dataRows): \App\Models\ProductImportLog
    {
        $rows = array_merge([['SKU', 'Nama Produk', 'Harga Dasar (Rp)', 'Aktif (Y/T)']], $dataRows);

        return app(ProductBulkImportService::class)->importFromFile($this->writeTestExcel($rows, 'test-import-' . uniqid() . '.xlsx'), 'uji.xlsx', null);
    }

    public function test_the_log_keeps_the_real_changes_with_old_and_new_values(): void
    {
        $this->product('IMP-1', 1_000_000, 'Nama Lama');

        $log = $this->import([['IMP-1', 'Nama Baru', 1_100_000, 'T']]);

        $changes = collect($log->changes)->keyBy('field');
        $this->assertSame(['Nama Produk', 'Harga Dasar', 'Status'], collect($log->changes)->pluck('field')->all());
        $this->assertSame(['Nama Lama', 'Nama Baru'], [$changes['Nama Produk']['old'], $changes['Nama Produk']['new']]);
        $this->assertSame(['Rp1.000.000', 'Rp1.100.000'], [$changes['Harga Dasar']['old'], $changes['Harga Dasar']['new']]);
        $this->assertSame(['Aktif', 'Nonaktif'], [$changes['Status']['old'], $changes['Status']['new']]);
        $this->assertSame('IMP-1', $changes['Harga Dasar']['sku']);
        $this->assertNull($changes['Harga Dasar']['note']);
    }

    public function test_values_that_did_not_change_are_not_listed(): void
    {
        $this->product('IMP-2', 500_000, 'Sama');

        $log = $this->import([['IMP-2', 'Sama', 500_000, 'Y']]);

        $this->assertSame(1, $log->updated_count);
        $this->assertNull($log->changes, 'Tidak ada perubahan nyata.');
    }

    public function test_a_base_price_change_on_a_size_priced_product_carries_a_note(): void
    {
        $product = $this->product('IMP-3', 0);
        \App\Models\FilmProductPrice::create(['film_product_id' => $product->id, 'vehicle_size' => 'M', 'price' => 800_000]);

        $log = $this->import([['IMP-3', '', 900_000, '']]);

        $this->assertSame(ProductBulkImportService::SIZE_PRICE_NOTE, $log->changes[0]['note']);
        $this->assertEquals(900_000.0, (float) $product->fresh()->base_price, 'Harga dasar tetap tersimpan.');
    }

    public function test_numeric_cells_keep_their_decimals_and_text_prices_follow_indonesian_or_english_format(): void
    {
        foreach (['A', 'B', 'C', 'D', 'E'] as $s) {
            $this->product("PRC-{$s}", 1);
        }

        $this->import([
            ['PRC-A', '', 1_250_000.5, ''],          // sel angka: tidak boleh jadi 12.500.005
            ['PRC-B', '', '1.500.000', ''],          // teks format Indonesia
            ['PRC-C', '', '1.500.000,50', ''],       // Indonesia dengan sen
            ['PRC-D', '', '1,500,000.50', ''],       // format Inggris
            ['PRC-E', '', 'Rp 2.000.000', ''],
        ]);

        $price = fn (string $s) => (float) FilmProduct::where('sku', "PRC-{$s}")->value('base_price');
        $this->assertEqualsWithDelta(1_250_000.5, $price('A'), 0.01);
        $this->assertEqualsWithDelta(1_500_000.0, $price('B'), 0.01);
        $this->assertEqualsWithDelta(1_500_000.5, $price('C'), 0.01);
        $this->assertEqualsWithDelta(1_500_000.5, $price('D'), 0.01);
        $this->assertEqualsWithDelta(2_000_000.0, $price('E'), 0.01);
    }

    public function test_a_text_that_is_not_a_number_is_reported_instead_of_becoming_zero(): void
    {
        $this->product('IMP-4', 750_000, 'Nama Lama');

        $log = $this->import([['IMP-4', 'Nama Baru', 'abc', '']]);

        $this->assertEquals(750_000.0, (float) FilmProduct::where('sku', 'IMP-4')->value('base_price'), 'Harga tidak berubah menjadi 0.');
        $this->assertSame('Nama Baru', FilmProduct::where('sku', 'IMP-4')->value('name'), 'Kolom lain di baris itu tetap diproses.');
        $this->assertStringContainsString('harga "abc" tidak valid', $log->errors[0]);
    }
}
