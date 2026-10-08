<?php

namespace Tests\Feature;

use App\Filament\Resources\InventoryMovementResource;
use App\Filament\Resources\InventoryMovementResource\Pages\ListInventoryMovements;
use App\Filament\Resources\InventoryMovementResource\Widgets\InventoryMovementStatsOverview;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ScrollCode;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Riwayat Keluar/Masuk (lintas semua barang PPF/WF): hak akses (hanya baca), daftar + pencarian + semua filter, ringkasan
 * bulan berjalan, ekspor mengikuti filter yang aktif + log, dan "Batalkan" (hanya full-access, hanya kejadian terakhir,
 * status barang + gulungan dikembalikan, toko gulungan dipulihkan). "Hari ini" dibekukan di 8 Oktober 2026.
 */
class InventoryMovementResourceTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
        InventoryMovement::query()->delete();
        $this->storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->storeB = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?array $menuAccess = null, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->storeA->id, 'menu_access' => $menuAccess, 'is_active' => true], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function as(User $user): User
    {
        $this->actingAs($user, 'web');

        return $user;
    }

    private function item(string $name, string $category = 'PPF', ?ScrollCode $scroll = null): InventoryItem
    {
        return InventoryItem::create(['code' => 'INV-' . strtoupper(uniqid()), 'name' => $name, 'category' => $category, 'status' => 'in_stock', 'scroll_code_id' => $scroll?->id]);
    }

    private function move(InventoryItem $item, string $type, ?User $user = null, ?Store $store = null, string $note = 'catatan', ?string $at = null): InventoryMovement
    {
        $movement = InventoryMovement::create(['inventory_item_id' => $item->id, 'type' => $type, 'note' => $note, 'destination_store_id' => $store?->id, 'user_id' => $user?->id]);

        if ($at) {
            \DB::table('inventory_movements')->where('id', $movement->id)->update(['created_at' => $at, 'updated_at' => $at]);
        }

        return $movement->fresh();
    }

    // ------------------------------------------------------------- akses

    public function test_access_is_read_only(): void
    {
        $record = new InventoryMovement();

        $this->as($this->user('super_admin'));
        $this->assertTrue(InventoryMovementResource::canViewAny());
        $this->assertFalse(InventoryMovementResource::canCreate());
        $this->assertFalse(InventoryMovementResource::canEdit($record));
        $this->assertFalse(InventoryMovementResource::canDelete($record));

        $this->as($this->user('kasir'));
        $this->assertTrue(InventoryMovementResource::canViewAny());

        $this->as($this->user('kasir', ['BookingResource']));
        $this->assertFalse(InventoryMovementResource::canViewAny());
    }

    // ------------------------------------------------------------- daftar & filter

    public function test_list_shows_rows_with_labels_and_missing_items(): void
    {
        $item = $this->item('Film Alpha');
        $staff = $this->user('kasir');
        $in = $this->move($item, 'in', $staff);
        $out = $this->move($item, 'out', $staff, $this->storeB, 'Kirim ke B');
        $correction = $this->move($item, 'correction');

        $this->as($this->user('super_admin'));
        Livewire::test(ListInventoryMovements::class)
            ->assertCanSeeTableRecords([$in, $out, $correction])
            ->assertTableColumnFormattedStateSet('type', 'Masuk', record: $in)
            ->assertTableColumnFormattedStateSet('type', 'Keluar', record: $out)
            ->assertTableColumnFormattedStateSet('type', 'Koreksi', record: $correction)
            ->assertTableColumnStateSet('destinationStore.name', 'Toko B', record: $out)
            ->assertTableColumnStateSet('inventoryItem.name', 'Film Alpha', record: $out)
            ->assertTableActionHidden('reverse', $correction);

        $item->delete();
        Livewire::test(ListInventoryMovements::class)->assertSuccessful();
    }

    public function test_search_and_filters(): void
    {
        $ppf = $this->item('Film Alpha', 'PPF');
        $wf = $this->item('Film Beta', 'Window Film');
        $alice = $this->user('kasir', null, ['name' => 'Alice']);
        $bob = $this->user('kasir', null, ['name' => 'Bob']);

        $a = $this->move($ppf, 'out', $alice, $this->storeA, 'catatan-a', '2026-10-01 09:00:00');
        $b = $this->move($wf, 'in', $bob, null, 'catatan-b', '2026-10-05 09:00:00');
        $c = $this->move($wf, 'out', $bob, $this->storeB, 'catatan-c', '2026-10-07 09:00:00');

        $this->as($this->user('super_admin'));

        Livewire::test(ListInventoryMovements::class)->searchTable('Alpha')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b, $c]);
        Livewire::test(ListInventoryMovements::class)->searchTable('catatan-b')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $c]);
        Livewire::test(ListInventoryMovements::class)->searchTable('Bob')->assertCanSeeTableRecords([$b, $c])->assertCanNotSeeTableRecords([$a]);

        Livewire::test(ListInventoryMovements::class)->filterTable('type', 'in')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $c]);
        Livewire::test(ListInventoryMovements::class)->filterTable('destination_store_id', $this->storeB->id)->assertCanSeeTableRecords([$c])->assertCanNotSeeTableRecords([$a, $b]);
        Livewire::test(ListInventoryMovements::class)->filterTable('user_id', $alice->id)->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b, $c]);
        Livewire::test(ListInventoryMovements::class)->filterTable('category', 'Window Film')->assertCanSeeTableRecords([$b, $c])->assertCanNotSeeTableRecords([$a]);
        Livewire::test(ListInventoryMovements::class)
            ->filterTable('created_at', ['from' => '2026-10-02', 'until' => '2026-10-06'])
            ->assertCanSeeTableRecords([$b])
            ->assertCanNotSeeTableRecords([$a, $c]);
        Livewire::test(ListInventoryMovements::class)
            ->filterTable('created_at', ['from' => '2026-10-05', 'until' => '2026-10-05'])
            ->assertCanSeeTableRecords([$b])
            ->assertCanNotSeeTableRecords([$a, $c]);
    }

    // ------------------------------------------------------------- ringkasan

    public function test_the_stats_cover_the_current_month_only(): void
    {
        $item = $this->item('Film Alpha');
        $this->move($item, 'out', null, $this->storeA, 'x', '2026-10-02 09:00:00');
        $this->move($item, 'out', null, $this->storeA, 'x', '2026-10-03 09:00:00');
        $this->move($item, 'out', null, $this->storeB, 'x', '2026-10-04 09:00:00');
        $this->move($item, 'in', null, null, 'x', '2026-10-05 09:00:00');
        $this->move($item, 'out', null, $this->storeB, 'x', '2026-09-30 09:00:00');
        $this->move($item, 'out', null, $this->storeB, 'x', '2026-09-29 09:00:00');
        $this->move($item, 'correction', null, null, 'x', '2026-10-06 09:00:00');

        $this->as($this->user('super_admin'));
        Livewire::test(InventoryMovementStatsOverview::class)
            ->assertSee('Keluar Bulan Ini')
            ->assertSee('Masuk Bulan Ini')
            ->assertSee('Toko A')
            ->assertSee('2 unit keluar bulan ini')
            ->assertDontSee('Toko B');
    }

    // ------------------------------------------------------------- ekspor

    public function test_export_follows_the_active_filter_and_is_logged(): void
    {
        $item = $this->item('Film Alpha');
        $staff = $this->user('kasir', null, ['name' => 'Alice']);
        $this->move($item, 'out', $staff, $this->storeB, 'Kirim');
        $this->move($item, 'in', $staff, null, 'Balik');

        $admin = $this->as($this->user('super_admin'));
        Excel::fake();
        Livewire::test(ListInventoryMovements::class)
            ->filterTable('type', 'out')
            ->callTableAction('export')
            ->assertHasNoTableActionErrors();

        Excel::assertDownloaded('riwayat-inventaris-20261008.xlsx', function ($export) {
            $rows = $export->query()->get();

            return $rows->count() === 1
                && $export->map($rows->first()) === ['08/10/2026 10:00', $rows->first()->inventoryItem->code, 'Film Alpha', 'PPF', 'Keluar', 'Toko B', 'Alice', 'Kirim'];
        });

        $log = Activity::where('log_name', 'report_export')->latest('id')->firstOrFail();
        $this->assertSame('inventory_movement', $log->properties['report']);
        $this->assertSame($admin->id, $log->causer_id);
    }

    // ------------------------------------------------------------- batalkan

    public function test_reverse_is_for_full_access_and_the_latest_movement_only(): void
    {
        $item = $this->item('Film Alpha');
        $first = $this->move($item, 'out', null, $this->storeA);
        $latest = $this->move($item, 'in');
        $item->update(['status' => 'in_stock']);

        $this->as($this->user('kasir'));
        Livewire::test(ListInventoryMovements::class)->assertTableActionHidden('reverse', $latest);

        $this->as($this->user('super_admin'));
        Livewire::test(ListInventoryMovements::class)
            ->assertTableActionVisible('reverse', $latest)
            ->assertTableActionHidden('reverse', $first);
    }

    public function test_reversing_an_out_restores_the_item_and_leaves_a_correction(): void
    {
        $scroll = ScrollCode::create(['code' => 'SC-REV-1', 'status' => 'unallocated', 'usage_count' => 0, 'total_length_meters' => 15, 'remaining_length_meters' => 15]);
        $item = $this->item('Film Alpha', 'PPF', $scroll);
        $admin = $this->as($this->user('super_admin'));

        $movement = $item->recordMovement('out', $admin->id, 'Kirim ke A', $this->storeA->id);
        $this->assertSame('out', $item->fresh()->status);
        $this->assertSame('allocated', $scroll->fresh()->status);
        $this->assertSame($this->storeA->id, $scroll->fresh()->store_id);

        Livewire::test(ListInventoryMovements::class)
            ->callTableAction('reverse', $movement)
            ->assertHasNoTableActionErrors();

        $this->assertSame('in_stock', $item->fresh()->status);
        $scroll = $scroll->fresh();
        $this->assertSame('unallocated', $scroll->status);
        $this->assertNull($scroll->store_id);
        $this->assertNull(InventoryMovement::find($movement->id));
        $correction = $item->movements()->firstOrFail();
        $this->assertSame('correction', $correction->type);
        $this->assertSame($admin->id, $correction->user_id);
        $this->assertStringContainsString('Keluar', $correction->note);
    }

    public function test_reversing_an_in_puts_the_roll_back_to_its_previous_store(): void
    {
        $scroll = ScrollCode::create(['code' => 'SC-REV-2', 'status' => 'unallocated', 'usage_count' => 0, 'total_length_meters' => 15, 'remaining_length_meters' => 15]);
        $item = $this->item('Film Alpha', 'PPF', $scroll);
        $admin = $this->as($this->user('super_admin'));

        $item->recordMovement('out', $admin->id, 'Kirim ke B', $this->storeB->id);
        $in = $item->fresh()->recordMovement('in', $admin->id, 'Balik');
        $this->assertSame('unallocated', $scroll->fresh()->status);

        Livewire::test(ListInventoryMovements::class)
            ->callTableAction('reverse', $in)
            ->assertHasNoTableActionErrors();

        $this->assertSame('out', $item->fresh()->status);
        $scroll = $scroll->fresh();
        $this->assertSame('allocated', $scroll->status);
        $this->assertSame($this->storeB->id, $scroll->store_id, 'Toko tujuan semula dipulihkan.');
    }

    public function test_an_older_movement_cannot_be_reversed_by_the_model(): void
    {
        $item = $this->item('Film Alpha');
        $admin = $this->user('super_admin');
        $out = $item->recordMovement('out', $admin->id, null, $this->storeA->id);
        $item->fresh()->recordMovement('in', $admin->id);

        $this->expectException(\InvalidArgumentException::class);
        $item->fresh()->reverseLastMovement($out, $admin->id);
    }
}
