<?php

namespace Tests\Feature;

use App\Models\ScrollCode;
use App\Models\Store;
use App\Services\StockOpnameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Stok Opname untuk gulungan PPF/WF (2026-11-17): hasil hitung = sisa meter hasil ukur fisik; diterapkan lewat
 * ScrollCode::correctLength() sehingga rekonsiliasi tetap bersih dan tidak ada baris pemakaian palsu.
 */
class StockOpnameRollTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        return Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Test', 'is_active' => true]);
    }

    private function roll(Store $store, float $total = 15, float $remaining = 10, string $status = 'allocated'): ScrollCode
    {
        return ScrollCode::create([
            'code' => 'SC-OP-' . uniqid(),
            'store_id' => $store->id,
            'status' => $status,
            'total_length_meters' => $total,
            'remaining_length_meters' => $remaining,
        ]);
    }

    public function test_draft_snapshots_remaining_meters_and_does_not_change_the_roll(): void
    {
        $store = $this->store();
        $roll = $this->roll($store);

        $opname = app(StockOpnameService::class)->submit($store->id, now()->toDateString(), null, [
            ['item_type' => 'scroll_code', 'item_id' => $roll->id, 'actual_quantity' => 8.5],
        ], null);

        $item = $opname->items->first();
        $this->assertSame('scroll_code', $item->item_type);
        $this->assertSame('m', $item->unit);
        $this->assertEquals(10, (float) $item->system_quantity);
        $this->assertEquals(-1.5, (float) $item->delta);
        $this->assertEquals(10, (float) $roll->fresh()->remaining_length_meters);
    }

    public function test_apply_sets_remaining_meters_through_the_adjustment_column_without_fake_usage_rows(): void
    {
        $store = $this->store();
        $roll = $this->roll($store);

        $opname = app(StockOpnameService::class)->create($store->id, now()->toDateString(), null, [
            ['item_type' => 'scroll_code', 'item_id' => $roll->id, 'actual_quantity' => 8.5],
        ], null);

        $roll->refresh();
        $this->assertEquals(8.5, (float) $roll->remaining_length_meters);
        $this->assertEquals(0, $roll->usages()->count());
        $this->assertEquals(8.5, $roll->expectedRemainingLength());
        $this->assertEquals(10, (float) $opname->items->first()->applied_system_quantity);
        $this->assertSame('allocated', $roll->status);
    }

    public function test_apply_is_relative_to_meters_used_since_counting(): void
    {
        $store = $this->store();
        $roll = $this->roll($store);
        $service = app(StockOpnameService::class);

        $opname = $service->submit($store->id, now()->toDateString(), null, [
            ['item_type' => 'scroll_code', 'item_id' => $roll->id, 'actual_quantity' => 9],
        ], null);

        $roll->recordUsage(2); // sisa 8 setelah dihitung; selisih -1 tetap berlaku
        $service->apply($opname, null);

        $this->assertEquals(7, (float) $roll->fresh()->remaining_length_meters);
    }

    public function test_counting_zero_marks_the_roll_used(): void
    {
        $store = $this->store();
        $roll = $this->roll($store);

        app(StockOpnameService::class)->create($store->id, now()->toDateString(), null, [
            ['item_type' => 'scroll_code', 'item_id' => $roll->id, 'actual_quantity' => 0],
        ], null);

        $roll->refresh();
        $this->assertEquals(0, (float) $roll->remaining_length_meters);
        $this->assertSame('used', $roll->status);
    }

    public function test_roll_from_another_store_or_not_allocated_is_refused(): void
    {
        $store = $this->store();
        $other = Store::create(['city' => 'Bandung', 'address' => 'Jl. Test 2', 'name' => 'Toko Lain', 'is_active' => true]);
        $service = app(StockOpnameService::class);

        $this->expectException(RuntimeException::class);
        $service->submit($store->id, now()->toDateString(), null, [
            ['item_type' => 'scroll_code', 'item_id' => $this->roll($other)->id, 'actual_quantity' => 5],
        ], null);
    }

    public function test_used_roll_is_refused(): void
    {
        $store = $this->store();

        $this->expectException(RuntimeException::class);
        app(StockOpnameService::class)->submit($store->id, now()->toDateString(), null, [
            ['item_type' => 'scroll_code', 'item_id' => $this->roll($store, 15, 0, 'used')->id, 'actual_quantity' => 1],
        ], null);
    }

    public function test_count_sheet_lists_the_stores_rolls_and_the_import_parser_reads_them_back(): void
    {
        $store = $this->store();
        $roll = $this->roll($store);
        $this->roll($this->store(), 15, 10); // toko lain tidak ikut

        $rows = \App\Exports\StockOpnameSheetExport::rows('scroll_code', null, $store->id);
        $this->assertCount(1, $rows);
        $this->assertSame('PPF/WF (gulungan)', $rows->first()['jenis']);
        $this->assertSame($roll->id, $rows->first()['id']);
        $this->assertCount(0, \App\Exports\StockOpnameSheetExport::rows('all'));

        $path = tempnam(sys_get_temp_dir(), 'opn') . '.xlsx';
        \Maatwebsite\Excel\Facades\Excel::store(new class($rows) extends \App\Exports\StockOpnameSheetExport {
            public function array(): array
            {
                return collect(parent::array())->map(fn ($r) => array_replace($r, [6 => '8,5']))->all();
            }
        }, basename($path), 'local');
        $full = \Illuminate\Support\Facades\Storage::disk('local')->path(basename($path));

        $parsed = \App\Filament\Resources\StockOpnameResource::parseCountSheet($full);
        @unlink($full);

        $this->assertSame([], $parsed['errors']);
        $this->assertSame('scroll_code', $parsed['items'][0]['item_type']);
        $this->assertSame($roll->id, $parsed['items'][0]['item_id']);
        $this->assertEquals(8.5, $parsed['items'][0]['actual_quantity']);
    }

    public function test_panel_roll_list_only_contains_allocated_rolls_with_length_data_in_the_store(): void
    {
        $store = $this->store();
        $good = $this->roll($store);
        $this->roll($store, 15, 0, 'used');
        ScrollCode::create(['code' => 'SC-NOLEN-' . uniqid(), 'store_id' => $store->id, 'status' => 'allocated']);

        $rows = array_values(\App\Filament\Resources\StockOpnameResource::rollRows($store->id));

        $this->assertCount(1, $rows);
        $this->assertSame($good->id, $rows[0]['item_id']);
        $this->assertNull($rows[0]['actual_quantity']);
    }
}
