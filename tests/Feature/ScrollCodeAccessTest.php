<?php

namespace Tests\Feature;

use App\Filament\Pages\SerialNumberReport;
use App\Filament\Resources\ScrollCodeResource;
use App\Filament\Resources\ScrollCodeResource\Pages\ListScrollCodes;
use App\Models\ScrollCode;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Akses Kode Gulungan (audit 2026-11-02): tidak terbuka lewat akses bawaan; harga beli butuh izin Lihat; hapus butuh izin Hapus dan status
 * Belum Dialokasi; tautan dari laporan hanya bagi yang boleh membukanya.
 */
class ScrollCodeAccessTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Role::findOrCreate('super_admin', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    private function user(string $role = 'kasir', ?array $menu = null, ?array $permissions = null): User
    {
        $user = User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'menu_access' => $menu, 'menu_permissions' => $permissions, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function roll(string $code, string $status = 'allocated'): ScrollCode
    {
        return ScrollCode::create(['code' => $code, 'store_id' => $this->store->id, 'status' => $status, 'usage_count' => 0, 'total_length_meters' => 15, 'remaining_length_meters' => 15, 'purchase_cost' => 3000000]);
    }

    public function test_default_accounts_cannot_open_kode_gulungan_but_menu_holders_and_full_access_can(): void
    {
        $roll = $this->roll('GLN-1');

        $this->actingAs($this->user(), 'web');
        $this->assertFalse(ScrollCodeResource::canViewAny());
        $this->assertFalse(ScrollCodeResource::canView($roll));

        $this->actingAs($this->user('kasir', ['ScrollCodeResource']), 'web');
        $this->assertTrue(ScrollCodeResource::canViewAny());
        $this->assertTrue(ScrollCodeResource::canView($roll));

        $this->actingAs($this->user('kasir', ['BookingResource']), 'web');
        $this->assertFalse(ScrollCodeResource::canViewAny());

        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(ScrollCodeResource::canViewAny());
    }

    public function test_the_purchase_cost_column_needs_the_view_grant(): void
    {
        $this->roll('GLN-2');

        $this->actingAs($this->user('kasir', ['ScrollCodeResource']), 'web');
        $this->assertFalse(ScrollCodeResource::canSeeCost());
        Livewire::test(ListScrollCodes::class)->assertTableColumnHidden('purchase_cost');

        $this->actingAs($this->user('kasir', ['ScrollCodeResource'], ['ScrollCodeResource' => ['view']]), 'web');
        $this->assertTrue(ScrollCodeResource::canSeeCost());
        Livewire::test(ListScrollCodes::class)->assertTableColumnVisible('purchase_cost');

        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(ScrollCodeResource::canSeeCost());
    }

    public function test_delete_needs_the_delete_grant_and_an_unallocated_roll(): void
    {
        $free = $this->roll('GLN-BEBAS', 'unallocated');
        $allocated = $this->roll('GLN-ALOK', 'allocated');

        $this->actingAs($this->user('kasir', ['ScrollCodeResource']), 'web');
        $this->assertFalse(ScrollCodeResource::canDelete($free), 'Menu dicentang saja tidak boleh menghapus.');

        $this->actingAs($this->user('kasir', ['ScrollCodeResource'], ['ScrollCodeResource' => ['delete']]), 'web');
        $this->assertTrue(ScrollCodeResource::canDelete($free));
        $this->assertFalse(ScrollCodeResource::canDelete($allocated), 'Hanya yang Belum Dialokasi.');
    }

    // ------------------------------------------------------------- audit Kode Gulungan B: hapus dan alokasi aman

    public function test_delete_blockers_list_usages_warranties_transfers_and_linked_items(): void
    {
        $clean = $this->roll('GLN-BERSIH', 'unallocated');
        $this->assertSame([], $clean->deleteBlockers());

        $used = $this->roll('GLN-PAKAI', 'unallocated');
        $used->usages()->create(['meters' => 2, 'note' => 'uji']);
        $this->assertSame(['1 riwayat pemakaian'], $used->deleteBlockers());

        $linked = $this->roll('GLN-TAUT', 'unallocated');
        \App\Models\InventoryItem::create(['code' => 'INV-ABC12345', 'name' => 'Film', 'category' => 'PPF', 'status' => 'in_stock', 'scroll_code_id' => $linked->id]);
        $this->assertSame(['terkait barang INV-ABC12345'], $linked->deleteBlockers());
    }

    public function test_deleting_a_roll_with_related_data_is_refused_and_a_clean_one_is_deleted(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $used = $this->roll('GLN-RIWAYAT', 'unallocated');
        $used->usages()->create(['meters' => 3, 'note' => 'riwayat asli']);
        $clean = $this->roll('GLN-KOSONG', 'unallocated');

        Livewire::test(ListScrollCodes::class)->callTableAction('delete', $used);
        $this->assertNotNull(ScrollCode::find($used->id), 'Gulungan dengan riwayat pemakaian tidak dihapus.');
        $this->assertSame(1, $used->usages()->count(), 'Riwayat pemakaian tetap utuh.');

        Livewire::test(ListScrollCodes::class)->callTableAction('delete', $clean);
        $this->assertNull(ScrollCode::find($clean->id));
    }

    public function test_the_bulk_delete_skips_rolls_with_related_data_and_reports_them(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $used = $this->roll('GLN-B-PAKAI', 'unallocated');
        $used->usages()->create(['meters' => 1]);
        $clean = $this->roll('GLN-B-BERSIH', 'unallocated');
        $allocated = $this->roll('GLN-B-ALOK', 'allocated');

        Livewire::test(ListScrollCodes::class)->callTableBulkAction('delete_unallocated', [$used, $clean, $allocated]);

        $this->assertNotNull(ScrollCode::find($used->id));
        $this->assertNotNull(ScrollCode::find($allocated->id));
        $this->assertNull(ScrollCode::find($clean->id));
    }

    public function test_the_bulk_allocation_skips_rolls_whose_item_is_still_in_stock(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $other = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $free = $this->roll('GLN-A-BEBAS', 'unallocated');
        $inWarehouse = $this->roll('GLN-A-GUDANG', 'unallocated');
        \App\Models\InventoryItem::create(['code' => 'INV-GUDANG01', 'name' => 'Film Gudang', 'category' => 'PPF', 'status' => 'in_stock', 'scroll_code_id' => $inWarehouse->id]);

        Livewire::test(ListScrollCodes::class)->callTableBulkAction('allocate', [$free, $inWarehouse], data: ['store_id' => $other->id]);

        $this->assertSame('allocated', $free->fresh()->status);
        $this->assertSame($other->id, $free->fresh()->store_id);
        $this->assertSame('unallocated', $inWarehouse->fresh()->status, 'Gulungan barang Ada Stok tidak dialokasikan dari sini.');
        $this->assertNull($inWarehouse->fresh()->store_id);
    }

    // ------------------------------------------------------------- audit Kode Gulungan C: daftar

    private function warranty(string $rollCode, string $plate): \App\Models\Warranty
    {
        return \App\Models\Warranty::create([
            'customer_name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999), 'car_plate' => $plate, 'car_type' => 'Raize',
            'product_series' => 'Ginnva PPF Pro', 'product_category' => 'ppf', 'installation_date' => now()->subDays(5)->toDateString(),
            'expiry_date' => now()->addYears(5)->toDateString(), 'dealer_name' => 'Toko A', 'store_id' => $this->store->id,
            'status' => 'active', 'review_status' => 'approved', 'roll_number' => $rollCode,
        ]);
    }

    public function test_the_list_filters_by_low_remaining_unlinked_missing_length_and_missing_cost(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $low = ScrollCode::create(['code' => 'GLN-TIPIS', 'status' => 'allocated', 'store_id' => $this->store->id, 'usage_count' => 0, 'total_length_meters' => 15, 'remaining_length_meters' => 2, 'purchase_cost' => 1000]);
        $full = ScrollCode::create(['code' => 'GLN-PENUH', 'status' => 'unallocated', 'usage_count' => 0, 'total_length_meters' => 15, 'remaining_length_meters' => 15, 'purchase_cost' => 1000]);
        $noLength = ScrollCode::create(['code' => 'GLN-TANPA-PANJANG', 'status' => 'unallocated', 'usage_count' => 0, 'purchase_cost' => 1000]);
        $noCost = ScrollCode::create(['code' => 'GLN-TANPA-HARGA', 'status' => 'unallocated', 'usage_count' => 0, 'total_length_meters' => 15, 'remaining_length_meters' => 15]);
        \App\Models\InventoryItem::create(['code' => 'INV-TAUT0001', 'name' => 'Film', 'category' => 'PPF', 'status' => 'out', 'scroll_code_id' => $low->id]);

        Livewire::test(ListScrollCodes::class)->filterTable('low_roll', true)
            ->assertCanSeeTableRecords([$low])->assertCanNotSeeTableRecords([$full, $noLength, $noCost]);
        Livewire::test(ListScrollCodes::class)->filterTable('unlinked', true)
            ->assertCanSeeTableRecords([$full, $noLength, $noCost])->assertCanNotSeeTableRecords([$low]);
        Livewire::test(ListScrollCodes::class)->filterTable('no_length', true)
            ->assertCanSeeTableRecords([$noLength])->assertCanNotSeeTableRecords([$low, $full, $noCost]);
        Livewire::test(ListScrollCodes::class)->filterTable('no_cost', true)
            ->assertCanSeeTableRecords([$noCost])->assertCanNotSeeTableRecords([$low, $full, $noLength]);
        Livewire::test(ListScrollCodes::class)->filterTable('status', 'used')->assertCanNotSeeTableRecords([$low, $full]);
    }

    public function test_the_cost_filter_is_hidden_from_those_who_cannot_see_cost(): void
    {
        $this->roll('GLN-X');
        $this->actingAs($this->user('kasir', ['ScrollCodeResource']), 'web');

        Livewire::test(ListScrollCodes::class)->assertTableFilterHidden('no_cost');
    }

    public function test_warranties_and_meter_records_are_counted_separately_without_a_query_per_row(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $roll = $this->roll('GLN-GARANSI', 'allocated');
        $first = $this->warranty('GLN-GARANSI', 'B 1 AA');
        $second = $this->warranty('GLN-GARANSI', 'B 2 BB');
        $roll->usages()->create(['meters' => 1]);
        $roll->usages()->create(['meters' => 2]);
        $roll->usages()->create(['meters' => 1.5]);

        $codes = collect([$first->warranty_code, $second->warranty_code])->sort()->implode(', ');
        Livewire::test(ListScrollCodes::class)
            ->assertTableColumnStateSet('warranty_total', '2', $roll->getKey())
            ->assertTableColumnStateSet('meter_usages_total', '3', $roll->getKey())
            ->assertTableColumnStateSet('warranty_code', $codes, $roll->getKey());

        $count = function () {
            \Illuminate\Support\Facades\DB::flushQueryLog();
            \Illuminate\Support\Facades\DB::enableQueryLog();
            Livewire::test(ListScrollCodes::class);

            return count(\Illuminate\Support\Facades\DB::getQueryLog());
        };

        $count();
        $few = $count();
        foreach (range(1, 6) as $i) {
            $this->roll("GLN-EXTRA-{$i}", 'unallocated');
        }

        $this->assertSame($few, $count(), 'Jumlah query daftar tidak bertambah per baris.');
    }

    public function test_status_wording_matches_the_produk_ppf_wf_menu(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $free = $this->roll('GLN-ISTILAH-1', 'unallocated');
        $allocated = $this->roll('GLN-ISTILAH-2', 'allocated');

        Livewire::test(ListScrollCodes::class)
            ->assertTableColumnFormattedStateSet('status', 'Belum dialokasikan', $free)
            ->assertTableColumnFormattedStateSet('status', 'Dialokasikan', $allocated);
    }

    // ------------------------------------------------------------- audit Kode Gulungan D: halaman Lihat

    public function test_the_view_page_summarises_length_cost_usage_and_the_linked_item(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $roll = ScrollCode::create(['code' => 'GLN-LIHAT', 'status' => 'allocated', 'store_id' => $this->store->id, 'usage_count' => 0, 'total_length_meters' => 15, 'remaining_length_meters' => 6, 'purchase_cost' => 3000000]);
        $roll->usages()->create(['meters' => 4]);
        $roll->correctLength(15.0, 8.0);   // koreksi manual: sisa fisik 8 m padahal riwayat menunjuk 11 m -> -3
        \App\Models\InventoryItem::create(['code' => 'INV-LIHAT001', 'name' => 'Film Lihat', 'category' => 'PPF', 'status' => 'out', 'scroll_code_id' => $roll->id]);

        Livewire::test(ScrollCodeResource\Pages\ViewScrollCode::class, ['record' => $roll->getKey()])
            ->assertFormSet([
                'length_summary' => '8.00 / 15.00 m',
                'length_adjustment_display' => '-3.00 m',
                'cost_display' => 'Rp3.000.000 (Rp200.000/m)',
            ])
            ->assertSee('INV-LIHAT001')
            ->assertSee('0 garansi · 1 catatan meter');
    }

    public function test_the_view_page_hides_the_cost_without_the_view_grant(): void
    {
        $roll = $this->roll('GLN-LIHAT-2');

        $this->actingAs($this->user('kasir', ['ScrollCodeResource']), 'web');
        Livewire::test(ScrollCodeResource\Pages\ViewScrollCode::class, ['record' => $roll->getKey()])
            ->assertFormFieldHidden('cost_display');

        $this->actingAs($this->user('kasir', ['ScrollCodeResource'], ['ScrollCodeResource' => ['view']]), 'web');
        Livewire::test(ScrollCodeResource\Pages\ViewScrollCode::class, ['record' => $roll->getKey()])
            ->assertFormFieldVisible('cost_display');
    }

    public function test_the_view_page_header_actions_follow_full_access_and_the_roll_status(): void
    {
        $allocated = $this->roll('GLN-LIHAT-ALOK', 'allocated');
        $free = $this->roll('GLN-LIHAT-BEBAS', 'unallocated');

        $this->actingAs($this->user('kasir', ['ScrollCodeResource']), 'web');
        Livewire::test(ScrollCodeResource\Pages\ViewScrollCode::class, ['record' => $allocated->getKey()])
            ->assertActionHidden('edit_length')
            ->assertActionHidden('transfer');

        $this->actingAs($this->user('super_admin'), 'web');
        Livewire::test(ScrollCodeResource\Pages\ViewScrollCode::class, ['record' => $allocated->getKey()])
            ->assertActionVisible('edit_length')
            ->assertActionVisible('transfer');
        Livewire::test(ScrollCodeResource\Pages\ViewScrollCode::class, ['record' => $free->getKey()])
            ->assertActionVisible('edit_length')
            ->assertActionHidden('transfer');
    }

    public function test_editing_the_length_from_the_view_page_corrects_the_roll(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $roll = $this->roll('GLN-LIHAT-EDIT', 'allocated');

        Livewire::test(ScrollCodeResource\Pages\ViewScrollCode::class, ['record' => $roll->getKey()])
            ->callAction('edit_length', data: ['total_length_meters' => 20, 'remaining_length_meters' => 12, 'purchase_cost' => 4000000]);

        $fresh = $roll->fresh();
        $this->assertEquals([20.0, 12.0, 4000000.0], [(float) $fresh->total_length_meters, (float) $fresh->remaining_length_meters, (float) $fresh->purchase_cost]);
        $this->assertEquals(12.0, $fresh->expectedRemainingLength(), 'Koreksi dari halaman Lihat tetap membuat rekonsiliasi bersih.');
    }

    public function test_the_serial_number_report_links_to_a_roll_only_for_those_who_can_open_it(): void
    {
        $roll = $this->roll('GLN-LAPORAN');

        $this->actingAs($this->user(), 'web');
        $this->assertNull((new SerialNumberReport())->scrollCodeUrl($roll->id));

        $this->actingAs($this->user('kasir', ['ScrollCodeResource']), 'web');
        $this->assertStringContainsString((string) $roll->id, (string) (new SerialNumberReport())->scrollCodeUrl($roll->id));
    }
}
