<?php

namespace Tests\Feature;

use App\Exports\ConsumableItemMovementExport;
use App\Filament\Resources\ConsumableItemMovementResource;
use App\Filament\Resources\ConsumableItemMovementResource\Pages\ListConsumableItemMovements;
use App\Filament\Resources\ConsumableItemMovementResource\Widgets\ConsumableItemMovementStatsOverview;
use App\Models\ConsumableItem;
use App\Models\ConsumableItemMovement;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Riwayat Barang Habis Pakai (lintas semua barang): hak akses (hanya baca), daftar + pencarian + semua filter, ringkasan
 * bulan berjalan, ekspor mengikuti filter + log, Cek Rekonsiliasi, dan "Batalkan" (hanya full-access, hanya kejadian
 * terakhir; stok dan penanda toko benar). "Hari ini" dibekukan di 8 Oktober 2026.
 */
class ConsumableItemMovementResourceTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
        ConsumableItemMovement::query()->delete();
        ConsumableItem::query()->delete();
        $this->storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
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

    private function item(string $name, string $category = 'umum', float $stock = 0): ConsumableItem
    {
        return ConsumableItem::create(['name' => $name, 'code' => 'CI-' . strtoupper(uniqid()), 'category' => $category, 'unit' => 'pcs', 'current_stock' => $stock]);
    }

    private function move(ConsumableItem $item, string $type, float $qty, ?User $user = null, ?float $cost = null, string $note = 'catatan', ?string $at = null): ConsumableItemMovement
    {
        $movement = ConsumableItemMovement::create(['consumable_item_id' => $item->id, 'type' => $type, 'quantity' => $qty, 'unit_cost' => $cost, 'note' => $note, 'user_id' => $user?->id]);

        if ($at) {
            DB::table('consumable_item_movements')->where('id', $movement->id)->update(['created_at' => $at, 'updated_at' => $at]);
        }

        return $movement->fresh();
    }

    // ------------------------------------------------------------- akses

    public function test_access_is_read_only(): void
    {
        $record = new ConsumableItemMovement();

        $this->as($this->user('super_admin'));
        $this->assertTrue(ConsumableItemMovementResource::canViewAny());
        $this->assertFalse(ConsumableItemMovementResource::canCreate());
        $this->assertFalse(ConsumableItemMovementResource::canEdit($record));
        $this->assertFalse(ConsumableItemMovementResource::canDelete($record));

        $this->as($this->user('kasir'));
        $this->assertTrue(ConsumableItemMovementResource::canViewAny());

        $this->as($this->user('kasir', ['BookingResource']));
        $this->assertFalse(ConsumableItemMovementResource::canViewAny());
    }

    // ------------------------------------------------------------- daftar & filter

    public function test_list_shows_labels_and_signs(): void
    {
        $item = $this->item('Sarung Tangan');
        $in = $this->move($item, 'in', 10, null, 15000);
        $out = $this->move($item, 'out', 3);
        $up = $this->move($item, 'adjustment', 2);
        $down = $this->move($item, 'adjustment', -1);
        $correction = $this->move($item, 'correction', 0);

        $this->as($this->user('super_admin'));
        Livewire::test(ListConsumableItemMovements::class)
            ->assertCanSeeTableRecords([$in, $out, $up, $down, $correction])
            ->assertTableColumnFormattedStateSet('type', 'Masuk', record: $in)
            ->assertTableColumnFormattedStateSet('type', 'Keluar', record: $out)
            ->assertTableColumnFormattedStateSet('type', 'Penyesuaian (Opname)', record: $up)
            ->assertTableColumnFormattedStateSet('type', 'Koreksi', record: $correction)
            ->assertTableColumnFormattedStateSet('quantity', '10.00 pcs', record: $in)
            ->assertTableColumnFormattedStateSet('quantity', '+2.00 pcs', record: $up)
            ->assertTableColumnFormattedStateSet('quantity', '-1.00 pcs', record: $down)
            ->assertTableActionHidden('reverse', $correction);

        $item->delete();
        Livewire::test(ListConsumableItemMovements::class)->assertSuccessful();
    }

    public function test_search_and_filters(): void
    {
        $gloves = $this->item('Sarung Tangan', 'APD');
        $tape = $this->item('Lakban', 'umum');
        $alice = $this->user('kasir', null, ['name' => 'Alice']);
        $bob = $this->user('kasir', null, ['name' => 'Bob']);

        $a = $this->move($gloves, 'out', 1, $alice, null, 'catatan-a', '2026-10-01 09:00:00');
        $b = $this->move($tape, 'in', 5, $bob, 2000, 'catatan-b', '2026-10-05 09:00:00');
        $c = $this->move($tape, 'out', 2, $bob, null, 'catatan-c', '2026-10-07 09:00:00');

        $this->as($this->user('super_admin'));

        Livewire::test(ListConsumableItemMovements::class)->searchTable('Sarung')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b, $c]);
        Livewire::test(ListConsumableItemMovements::class)->searchTable('catatan-b')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $c]);
        Livewire::test(ListConsumableItemMovements::class)->searchTable('Bob')->assertCanSeeTableRecords([$b, $c])->assertCanNotSeeTableRecords([$a]);

        Livewire::test(ListConsumableItemMovements::class)->filterTable('type', 'in')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $c]);
        Livewire::test(ListConsumableItemMovements::class)->filterTable('consumable_item_id', $tape->id)->assertCanSeeTableRecords([$b, $c])->assertCanNotSeeTableRecords([$a]);
        Livewire::test(ListConsumableItemMovements::class)->filterTable('user_id', $alice->id)->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b, $c]);
        Livewire::test(ListConsumableItemMovements::class)
            ->filterTable('created_at', ['from' => '2026-10-02', 'until' => '2026-10-06'])
            ->assertCanSeeTableRecords([$b])
            ->assertCanNotSeeTableRecords([$a, $c]);
    }

    // ------------------------------------------------------------- ringkasan

    public function test_the_stats_cover_the_current_month_only(): void
    {
        $gloves = $this->item('Sarung Tangan');
        $tape = $this->item('Lakban');
        $this->move($gloves, 'out', 4, null, null, 'x', '2026-10-02 09:00:00');
        $this->move($gloves, 'out', 3, null, null, 'x', '2026-10-03 09:00:00');
        $this->move($tape, 'out', 5, null, null, 'x', '2026-10-04 09:00:00');
        $this->move($tape, 'in', 9, null, 1000, 'x', '2026-10-05 09:00:00');
        $this->move($tape, 'out', 99, null, null, 'x', '2026-09-30 09:00:00');

        $this->as($this->user('super_admin'));
        Livewire::test(ConsumableItemMovementStatsOverview::class)
            ->assertSee('Keluar Bulan Ini')
            ->assertSee('Masuk Bulan Ini')
            ->assertSee('Sarung Tangan')
            ->assertSee('7.00 pcs bulan ini')
            ->assertDontSee('99.00');
    }

    // ------------------------------------------------------------- ekspor

    public function test_export_follows_the_active_filter_and_is_logged(): void
    {
        $item = $this->item('Sarung Tangan', 'APD');
        $staff = $this->user('kasir', null, ['name' => 'Alice']);
        $this->move($item, 'in', 10, $staff, 15000, 'Beli');
        $this->move($item, 'adjustment', 2, $staff, null, 'Opname');
        $this->move($item, 'correction', 0, $staff, null, 'Koreksi');

        $admin = $this->as($this->user('super_admin'));
        Excel::fake();
        Livewire::test(ListConsumableItemMovements::class)
            ->filterTable('type', 'in')
            ->callTableAction('export')
            ->assertHasNoTableActionErrors();

        Excel::assertDownloaded('riwayat-barang-habis-pakai-20261008.xlsx', function ($export) {
            $rows = $export->query()->get();

            return $rows->count() === 1
                && $export->map($rows->first()) === ['08/10/2026 10:00', 'Sarung Tangan', 'APD', 'Masuk', '10.00', 'pcs', '15,000.00', 'Alice', 'Beli'];
        });

        $log = Activity::where('log_name', 'report_export')->latest('id')->firstOrFail();
        $this->assertSame('consumable_item_movement', $log->properties['report']);
        $this->assertSame($admin->id, $log->causer_id);

        $export = new ConsumableItemMovementExport();
        $types = $export->query()->get()->map(fn ($m) => $export->map($m)[3])->all();
        $this->assertContains('Koreksi', $types);
        $this->assertContains('Penyesuaian (Opname)', $types);
    }

    // ------------------------------------------------------------- rekonsiliasi

    public function test_reconciliation_flags_only_drifting_items(): void
    {
        $clean = $this->item('Bersih', 'umum', 7);
        $this->move($clean, 'in', 10);
        $this->move($clean, 'out', 4);
        $this->move($clean, 'adjustment', 1);

        $this->as($this->user('super_admin'));
        Livewire::test(ListConsumableItemMovements::class)
            ->callTableAction('reconcile')
            ->assertNotified('Rekonsiliasi bersih');

        $drift = $this->item('Melenceng', 'umum', 50);
        $this->move($drift, 'in', 10);

        Livewire::test(ListConsumableItemMovements::class)
            ->callTableAction('reconcile')
            ->assertNotified('1 barang tidak cocok');
    }

    // ------------------------------------------------------------- batalkan

    public function test_reverse_is_for_full_access_and_the_latest_movement_only(): void
    {
        $item = $this->item('Sarung Tangan', 'umum', 10);
        $first = $this->move($item, 'in', 10);
        $latest = $this->move($item, 'out', 2);

        $this->as($this->user('kasir'));
        Livewire::test(ListConsumableItemMovements::class)->assertTableActionHidden('reverse', $latest);

        $this->as($this->user('super_admin'));
        Livewire::test(ListConsumableItemMovements::class)
            ->assertTableActionVisible('reverse', $latest)
            ->assertTableActionHidden('reverse', $first);
    }

    public function test_reversing_an_out_gives_the_stock_back_and_leaves_a_correction(): void
    {
        $admin = $this->as($this->user('super_admin'));
        $item = $this->item('Sarung Tangan');
        $item->recordMovement('in', 10, $admin->id, null, 1000);
        $out = $item->fresh()->recordMovement('out', 4, $admin->id, 'Pakai', storeId: $this->storeA->id);
        $this->assertEquals(6, (float) $item->fresh()->current_stock);

        Livewire::test(ListConsumableItemMovements::class)
            ->callTableAction('reverse', $out)
            ->assertHasNoTableActionErrors();

        $this->assertEquals(10, (float) $item->fresh()->current_stock);
        $this->assertNull(ConsumableItemMovement::find($out->id));
        $correction = $item->movements()->where('type', 'correction')->firstOrFail();
        $this->assertEquals(0, (float) $correction->quantity);
        $this->assertSame($admin->id, $correction->user_id);
        $this->assertSame($this->storeA->id, $correction->store_id, 'Koreksi mewarisi penanda toko.');
    }

    public function test_reversing_an_in_takes_the_stock_back_out(): void
    {
        $admin = $this->as($this->user('super_admin'));
        $item = $this->item('Sarung Tangan');
        $item->recordMovement('in', 5, $admin->id);
        $in = $item->fresh()->recordMovement('in', 8, $admin->id, 'Salah input');
        $this->assertEquals(13, (float) $item->fresh()->current_stock);

        Livewire::test(ListConsumableItemMovements::class)
            ->callTableAction('reverse', $in)
            ->assertHasNoTableActionErrors();

        $this->assertEquals(5, (float) $item->fresh()->current_stock);
    }

    public function test_reversing_a_stocktake_restores_the_old_stock_in_both_directions(): void
    {
        $admin = $this->as($this->user('super_admin'));
        $item = $this->item('Sarung Tangan', 'umum', 10);

        $down = $item->adjustStock(7, $admin->id, 'Opname turun');
        Livewire::test(ListConsumableItemMovements::class)->callTableAction('reverse', $down)->assertHasNoTableActionErrors();
        $this->assertEquals(10, (float) $item->fresh()->current_stock);

        $up = $item->fresh()->adjustStock(15, $admin->id, 'Opname naik');
        Livewire::test(ListConsumableItemMovements::class)->callTableAction('reverse', $up)->assertHasNoTableActionErrors();
        $this->assertEquals(10, (float) $item->fresh()->current_stock);
    }

    public function test_an_older_movement_cannot_be_reversed_by_the_model(): void
    {
        $admin = $this->user('super_admin');
        $item = $this->item('Sarung Tangan');
        $in = $item->recordMovement('in', 5, $admin->id);
        $item->fresh()->recordMovement('out', 1, $admin->id);

        $this->expectException(\InvalidArgumentException::class);
        $item->fresh()->reverseLastMovement($in, $admin->id);
    }
}
