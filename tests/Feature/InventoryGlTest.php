<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\ChartOfAccount;
use App\Models\ConsumableItem;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\MaterialMemo;
use App\Models\RawMaterial;
use App\Models\ScrollCode;
use App\Models\ScrollCodeUsage;
use App\Models\Store;
use App\Models\User;
use App\Services\AccountingPeriodService;
use App\Services\BookingCogsPostingService;
use App\Services\BookingPostingService;
use App\Services\FinancialStatementService;
use App\Services\InventorySyncService;
use App\Services\LedgerReconciliationService;
use App\Services\StockOpnameService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Keuangan Fase 2 (2026-11-06): HPP booking dijurnal otomatis dari pemakaian bahan (di balik FEATURE_INVENTORY_GL), selisih Stok Opname dijurnal,
 * dan Sinkronisasi Persediaan ke sub-buku. Waktu dibekukan: Rabu 7 Okt 2026.
 */
class InventoryGlTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['super_admin', 'direksi'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->seed(ChartOfAccountSeeder::class);
        Carbon::setTestNow('2026-10-07 09:10:00');
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enable(bool $on = true): void
    {
        config(['features.inventory_gl' => $on]);
    }

    private function balance(string $code): float
    {
        return app(FinancialStatementService::class)->balanceAsOf(ChartOfAccount::where('code', $code)->firstOrFail(), now());
    }

    /** Booking selesai dengan pendapatan dijurnal, film 3 m @ Rp100.000/m, bahan baku 2 L @ Rp50.000, barang habis pakai 4 pcs @ Rp10.000. */
    private function bookingWithUsage(): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
        $booking = Booking::create([
            'booking_number' => 'BKG-T-' . uniqid(), 'customer_id' => $customer->id, 'store_id' => $this->store->id, 'service_type' => 'PPF',
            'product_ppf' => true, 'preferred_date' => now()->toDateString(), 'status' => 'completed', 'transaction_amount' => 5000000, 'amount_received' => 5000000,
        ]);

        $roll = ScrollCode::create(['code' => 'SC-' . uniqid(), 'status' => 'allocated', 'total_length_meters' => 15, 'remaining_length_meters' => 12, 'purchase_cost' => 1500000]);
        ScrollCodeUsage::create(['scroll_code_id' => $roll->id, 'booking_id' => $booking->id, 'meters' => 3]);

        $glue = RawMaterial::create(['name' => 'Lem', 'code' => 'RM-' . uniqid(), 'category' => 'chemical', 'unit' => 'liter', 'current_stock' => 10, 'unit_cost' => 50000]);
        $gloves = ConsumableItem::create(['name' => 'Sarung tangan', 'code' => 'CI-' . uniqid(), 'category' => 'APD', 'unit' => 'pcs', 'current_stock' => 20, 'unit_cost' => 10000]);

        $memo = MaterialMemo::create([
            'memo_number' => 'MEMO-' . uniqid(), 'store_id' => $this->store->id, 'booking_id' => $booking->id,
            'created_by' => User::create(['name' => 'Staf', 'email' => uniqid() . '@test.local', 'password' => 'x'])->id,
        ]);
        $memo->items()->create(['item_type' => 'raw_material', 'item_id' => $glue->id, 'item_name' => 'Lem', 'unit' => 'liter', 'qty_taken' => 2]);
        $memo->items()->create(['item_type' => 'consumable_item', 'item_id' => $gloves->id, 'item_name' => 'Sarung tangan', 'unit' => 'pcs', 'qty_taken' => 4]);

        return $booking;
    }

    private function activeCogs(Booking $booking): ?JournalEntry
    {
        return app(BookingCogsPostingService::class)->activeEntry($booking->id);
    }

    // ------------------------------------------------------------- HPP booking

    public function test_nothing_is_posted_while_the_feature_is_off(): void
    {
        $booking = $this->bookingWithUsage();

        app(BookingPostingService::class)->sync($booking);

        $this->assertNull($this->activeCogs($booking));
        $this->assertEquals(0, $this->balance('5100'));
    }

    public function test_cogs_is_posted_per_source_when_the_feature_is_on(): void
    {
        $this->enable();
        $booking = $this->bookingWithUsage();

        app(BookingPostingService::class)->sync($booking);

        $entry = $this->activeCogs($booking);
        $this->assertNotNull($entry);
        $this->assertEquals($entry->lines->sum('debit'), $entry->lines->sum('credit'));
        $this->assertEquals(400000, $this->balance('5100'), 'Film 300.000 + bahan baku 100.000.');
        $this->assertEquals(40000, $this->balance('5200'));
        $this->assertEquals(-300000, $this->balance('1132'));
        $this->assertEquals(-100000, $this->balance('1130'));
        $this->assertEquals(-40000, $this->balance('1131'));
    }

    public function test_sync_is_idempotent_and_replaces_the_entry_when_usage_changes(): void
    {
        $this->enable();
        $booking = $this->bookingWithUsage();
        $service = app(BookingCogsPostingService::class);
        app(BookingPostingService::class)->sync($booking);

        $first = $this->activeCogs($booking);
        $this->assertSame($first->id, $service->sync($booking->fresh())->id, 'Angka sama: tidak membuat jurnal baru.');
        $this->assertSame(1, JournalEntry::where('reference_type', 'booking_cogs')->count());

        ScrollCodeUsage::create(['scroll_code_id' => ScrollCode::first()->id, 'booking_id' => $booking->id, 'meters' => 1]);
        $second = $service->sync($booking->fresh());

        $this->assertNotSame($first->id, $second->id);
        $this->assertTrue($first->fresh()->reversal()->exists(), 'Jurnal lama dibalik, bukan diedit.');
        $this->assertEquals(500000, $this->balance('5100'), 'Film menjadi 4 m = 400.000 + bahan baku 100.000.');
        $this->assertSame($second->id, $this->activeCogs($booking)->id);
    }

    public function test_cogs_is_reversed_when_revenue_is_cleared_and_not_posted_without_revenue(): void
    {
        $this->enable();
        $booking = $this->bookingWithUsage();

        $this->assertNull(app(BookingCogsPostingService::class)->sync($booking), 'Belum ada jurnal pendapatan.');

        app(BookingPostingService::class)->sync($booking);
        $this->assertNotNull($this->activeCogs($booking));

        $booking->refresh()->update(['transaction_amount' => 0]);
        app(BookingPostingService::class)->sync($booking->fresh());

        $this->assertNull($this->activeCogs($booking));
        $this->assertEquals(0, $this->balance('5100'));
    }

    public function test_missing_cost_is_flagged_in_the_description(): void
    {
        $this->enable();
        $booking = $this->bookingWithUsage();
        RawMaterial::query()->update(['unit_cost' => null]);

        app(BookingPostingService::class)->sync($booking);

        $this->assertStringContainsString('perkiraan', $this->activeCogs($booking)->description);
    }

    public function test_the_sync_command_catches_up_completed_bookings(): void
    {
        $booking = $this->bookingWithUsage();
        app(BookingPostingService::class)->sync($booking); // fitur mati: belum ada HPP
        $this->assertNull($this->activeCogs($booking));

        $this->enable();
        Artisan::call('cogs:sync');

        $this->assertNotNull($this->activeCogs($booking));
        $this->assertStringContainsString('1 booking disinkronkan', Artisan::output());
    }

    public function test_period_checklist_warns_about_bookings_without_cogs(): void
    {
        $booking = $this->bookingWithUsage();
        app(BookingPostingService::class)->sync($booking);

        $this->enable();
        $keys = collect(app(AccountingPeriodService::class)->checklist(Carbon::parse('2026-10-01')))->pluck('key');
        $this->assertTrue($keys->contains('cogs'));

        app(BookingCogsPostingService::class)->sync($booking->fresh());
        $keys = collect(app(AccountingPeriodService::class)->checklist(Carbon::parse('2026-10-01')))->pluck('key');
        $this->assertFalse($keys->contains('cogs'));
    }

    // ------------------------------------------------------------- stok opname

    public function test_opname_difference_is_journaled_to_inventory_loss_only_when_enabled(): void
    {
        $material = RawMaterial::create(['name' => 'Lem', 'code' => 'RM-' . uniqid(), 'category' => 'chemical', 'unit' => 'liter', 'current_stock' => 10, 'unit_cost' => 50000]);
        $service = app(StockOpnameService::class);

        $service->create($this->store->id, '2026-10-06', null, [['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 9]], null);
        $this->assertEquals(0, $this->balance('6520'), 'Fitur mati: tidak ada jurnal.');

        $this->enable();
        $service->create($this->store->id, '2026-10-06', null, [['item_type' => 'raw_material', 'item_id' => $material->fresh()->id, 'actual_quantity' => 7]], null);

        $this->assertEquals(100000, $this->balance('6520'), 'Kurang 2 liter x 50.000.');
        $this->assertEquals(-100000, $this->balance('1130'));
        $this->assertSame(1, JournalEntry::where('reference_type', 'stock_opname')->count());
    }

    // ------------------------------------------------------------- sinkronisasi

    public function test_inventory_sync_posts_one_adjustment_and_then_reconciles(): void
    {
        RawMaterial::create(['name' => 'Lem', 'code' => 'RM-' . uniqid(), 'category' => 'chemical', 'unit' => 'liter', 'current_stock' => 10, 'unit_cost' => 50000]);
        ConsumableItem::create(['name' => 'Sarung tangan', 'code' => 'CI-' . uniqid(), 'category' => 'APD', 'unit' => 'pcs', 'current_stock' => 10, 'unit_cost' => 5000]);
        ScrollCode::create(['code' => 'SC-' . uniqid(), 'status' => 'allocated', 'total_length_meters' => 15, 'remaining_length_meters' => 15, 'purchase_cost' => 1500000]);

        $service = app(InventorySyncService::class);
        $plan = $service->plan();

        $this->assertEqualsCanonicalizing(['raw_material', 'consumable', 'film_roll'], $plan->pluck('key')->all());
        $this->assertEquals(1500000 + 500000 + 50000, $plan->sum('adjustment'));

        $retained = ChartOfAccount::where('code', '3200')->firstOrFail();
        $entry = $service->post(['raw_material', 'consumable', 'film_roll'], $retained->id, 'Saldo awal persediaan', null);

        $this->assertEquals($entry->lines->sum('debit'), $entry->lines->sum('credit'));
        $this->assertEquals(500000, $this->balance('1130'));
        $this->assertEquals(50000, $this->balance('1131'));
        $this->assertEquals(1500000, $this->balance('1132'));
        $this->assertEquals(2050000, $this->balance('3200'));

        $rows = app(LedgerReconciliationService::class)->run()->keyBy('key');
        foreach (['raw_material', 'consumable', 'film_roll'] as $key) {
            $this->assertTrue($rows[$key]['ok'], "{$key} harus cocok setelah sinkronisasi.");
        }

        $this->expectException(RuntimeException::class);
        $service->post(['raw_material'], $retained->id, null, null);
    }

    public function test_inventory_sync_rejects_a_counter_account_outside_the_allowed_list(): void
    {
        RawMaterial::create(['name' => 'Lem', 'code' => 'RM-' . uniqid(), 'category' => 'chemical', 'unit' => 'liter', 'current_stock' => 10, 'unit_cost' => 50000]);

        $this->expectException(RuntimeException::class);
        app(InventorySyncService::class)->post(['raw_material'], ChartOfAccount::where('code', '4400')->value('id'), null, null);
    }
}
