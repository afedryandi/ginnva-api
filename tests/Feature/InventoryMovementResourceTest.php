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

    private function move(InventoryItem $item, string $type, ?User $user = null, ?Store $store = null, string $note = 'catatan', ?string $at = null, ?Store $actor = null): InventoryMovement
    {
        $movement = InventoryMovement::create(['inventory_item_id' => $item->id, 'type' => $type, 'note' => $note, 'destination_store_id' => $store?->id, 'store_id' => $actor?->id, 'user_id' => $user?->id]);

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

        // Akses eksplisit: akun dengan menu kosong (akses bawaan) tidak ikut.
        $this->as($this->user('kasir'));
        $this->assertFalse(InventoryMovementResource::canViewAny());

        $this->as($this->user('kasir', ['InventoryMovementResource']));
        $this->assertTrue(InventoryMovementResource::canViewAny());
        $this->assertTrue(InventoryMovementResource::canView($record));

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
                && $export->map($rows->first()) === ['08/10/2026 10:00', $rows->first()->inventoryItem->code, 'Film Alpha', 'PPF', 'Keluar', 'Toko B', 'Alice', 'Kirim', '—', '—', 'Berlaku'];
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
        $latest = $this->move($item, 'in', null, null, 'catatan', null, $this->storeA);
        $item->update(['status' => 'in_stock']);

        $this->as($this->user('kasir', ['InventoryMovementResource']));
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
            ->callTableAction('reverse', $movement, data: ['reason' => 'Salah scan barang'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('in_stock', $item->fresh()->status);
        $scroll = $scroll->fresh();
        $this->assertSame('unallocated', $scroll->status);
        $this->assertNull($scroll->store_id);

        // Baris asli disimpan (ditandai dibatalkan), bukan dihapus.
        $original = InventoryMovement::findOrFail($movement->id);
        $this->assertNotNull($original->reversed_at);
        $this->assertSame($admin->id, $original->reversed_by);
        $this->assertSame('Salah scan barang', $original->reversed_reason);
        $correction = $item->movements()->where('type', 'correction')->firstOrFail();
        $this->assertSame($admin->id, $correction->user_id);
        $this->assertStringContainsString('Keluar', $correction->note);
        $this->assertStringContainsString('Salah scan barang', $correction->note);
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
            ->callTableAction('reverse', $in, data: ['reason' => 'Salah pilih jenis'])
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
        $item->fresh()->reverseLastMovement($out, $admin->id, 'Salah');
    }
    // ------------------------------------------------------------- audit 2026-11-03

    private function staffOf(Store $store): User
    {
        return $this->user('kasir', ['InventoryMovementResource'], ['store_id' => $store->id]);
    }

    public function test_staff_only_see_their_own_stores_rows_while_full_access_sees_everything(): void
    {
        $item = $this->item('Film Alpha');
        $outA = $this->move($item, 'out', null, $this->storeA, 'ke A', null, $this->storeA);
        $inA = $this->move($item, 'in', null, null, 'balik A', null, $this->storeA);
        $outB = $this->move($item, 'out', null, $this->storeB, 'ke B', null, $this->storeB);
        $adminOutB = $this->move($item, 'out', null, $this->storeA, 'dikirim admin ke A', null, null);
        $legacy = $this->move($item, 'in', null, null, 'tanpa toko', null, null);

        $this->as($this->staffOf($this->storeA));
        Livewire::test(ListInventoryMovements::class)
            ->assertCanSeeTableRecords([$outA, $inA, $adminOutB])
            ->assertCanNotSeeTableRecords([$outB, $legacy])
            ->assertTableFilterHidden('store_id');
        Livewire::test(InventoryMovementStatsOverview::class)->assertSee('2 unit keluar bulan ini');

        $rows = (new \App\Exports\InventoryMovementExport())->query()->get();
        $this->assertEqualsCanonicalizing([$outA->id, $inA->id, $adminOutB->id], $rows->pluck('id')->all());

        $this->as($this->user('super_admin'));
        Livewire::test(ListInventoryMovements::class)
            ->assertCanSeeTableRecords([$outA, $inA, $outB, $adminOutB, $legacy])
            ->assertTableFilterVisible('store_id')
            ->filterTable('store_id', $this->storeB->id)
            ->assertCanSeeTableRecords([$outB])->assertCanNotSeeTableRecords([$outA, $inA]);

        // Akun tanpa toko dan bukan akses penuh tidak melihat apa pun.
        $this->as($this->user('kasir', ['InventoryMovementResource'], ['store_id' => null]));
        $this->assertSame(0, InventoryMovement::query()->visibleTo(auth()->user())->count());
    }

    public function test_recording_a_movement_stores_the_acting_store_for_every_type(): void
    {
        $item = $this->item('Film Alpha');
        $actor = $this->user('kasir');

        $out = $item->recordMovement('out', $actor->id, 'kirim', $this->storeB->id, $this->storeA->id);
        $in = $item->fresh()->recordMovement('in', $actor->id, 'balik', null, $this->storeA->id);

        $this->assertSame([$this->storeB->id, $this->storeA->id], [$out->destination_store_id, $out->store_id]);
        $this->assertNull($in->destination_store_id);
        $this->assertSame($this->storeA->id, $in->store_id, 'Masuk kini punya toko pencatat.');
    }

    public function test_reversing_keeps_the_row_requires_a_reason_and_allows_a_chain(): void
    {
        $item = $this->item('Film Alpha');
        $admin = $this->as($this->user('super_admin'));
        $out = $item->recordMovement('out', $admin->id, 'kirim', $this->storeA->id);
        $in = $item->fresh()->recordMovement('in', $admin->id, 'balik');

        // Alasan wajib.
        Livewire::test(ListInventoryMovements::class)
            ->callTableAction('reverse', $in, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);
        $this->assertNull($in->fresh()->reversed_at);

        Livewire::test(ListInventoryMovements::class)
            ->callTableAction('reverse', $in, data: ['reason' => 'Salah scan'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('out', $item->fresh()->status);

        // Yang sudah dibatalkan tidak bisa dibatalkan lagi, dan baris sebelumnya kini jadi yang terakhir yang berlaku.
        $in = $in->fresh();
        $this->assertTrue($in->isReversed());
        $this->assertFalse($in->isReversible());
        $this->assertTrue($out->fresh()->isReversible());
        Livewire::test(ListInventoryMovements::class)
            ->assertTableActionHidden('reverse', $in)
            ->assertTableActionVisible('reverse', $out->fresh())
            ->callTableAction('reverse', $out->fresh(), data: ['reason' => 'Salah kirim']);
        $this->assertSame('in_stock', $item->fresh()->status);
        $this->assertSame(2, $item->movements()->where('type', 'correction')->count());

        // Ringkasan hanya menghitung yang masih berlaku.
        Livewire::test(InventoryMovementStatsOverview::class)->assertSee('Keluar Bulan Ini')->assertDontSee('Toko A');

        // Filter dibatalkan.
        Livewire::test(ListInventoryMovements::class)
            ->filterTable('reversed', true)->assertCanSeeTableRecords([$in, $out->fresh()])
            ->filterTable('reversed', false)->assertCanNotSeeTableRecords([$in, $out->fresh()]);
    }

    public function test_the_model_refuses_a_blank_reason_and_a_second_reversal(): void
    {
        $item = $this->item('Film Alpha');
        $admin = $this->user('super_admin');
        $out = $item->recordMovement('out', $admin->id, null, $this->storeA->id);

        try {
            $item->fresh()->reverseLastMovement($out, $admin->id, '  ');
            $this->fail('Alasan kosong harus ditolak.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('alasan', $e->getMessage());
        }

        $item->fresh()->reverseLastMovement($out, $admin->id, 'Salah');
        $this->expectException(\InvalidArgumentException::class);
        $item->fresh()->reverseLastMovement($out->fresh(), $admin->id, 'Lagi');
    }

    public function test_the_detail_page_shows_the_movement_roll_and_reversal(): void
    {
        $scroll = ScrollCode::create(['code' => 'SC-VIEW-1', 'status' => 'unallocated', 'usage_count' => 0, 'total_length_meters' => 15, 'remaining_length_meters' => 15]);
        $item = $this->item('Film Alpha', 'PPF', $scroll);
        $admin = $this->as($this->user('super_admin'));
        $out = $item->recordMovement('out', $admin->id, 'Kirim ke A', $this->storeA->id, $this->storeA->id);
        $item->fresh()->reverseLastMovement($out, $admin->id, 'Salah toko');

        Livewire::test(\App\Filament\Resources\InventoryMovementResource\Pages\ViewInventoryMovement::class, ['record' => $out->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('SC-VIEW-1')
            ->assertSee('Salah toko');

        $this->as($this->user('kasir', ['InventoryMovementResource'], ['store_id' => $this->storeB->id]));
        try {
            Livewire::test(\App\Filament\Resources\InventoryMovementResource\Pages\ViewInventoryMovement::class, ['record' => $out->getRouteKey()]);
            $this->fail('Baris toko lain tidak boleh bisa dibuka.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_the_mobile_history_is_scoped_filtered_and_needs_inventory_access(): void
    {
        $item = $this->item('Film Alpha');
        $this->move($item, 'out', null, $this->storeA, 'ke A', '2026-10-08 09:00:00', $this->storeA);
        $this->move($item, 'in', null, null, 'balik A', '2026-10-01 09:00:00', $this->storeA);
        $this->move($item, 'out', null, $this->storeB, 'ke B', '2026-10-08 08:00:00', $this->storeB);

        $staff = $this->user('kasir', ['InventoryItemResource'], ['store_id' => $this->storeA->id]);
        $this->getJson('/api/staff/inventory-movements')->assertStatus(401);

        $all = $this->actingAs($staff, 'api')->getJson('/api/staff/inventory-movements')->assertSuccessful();
        $this->assertSame(['ke A', 'balik A'], array_column($all->json('data'), 'note'));
        $this->assertSame(['out_today' => 1, 'in_today' => 0], $all->json('summary'));
        // Ringkasan periode: seluruh riwayat akun ini bila tanpa from; mengikuti from; TIDAK mengikuti filter jenis.
        $this->assertSame(['out' => 1, 'in' => 1], $all->json('period_summary'));
        $this->assertSame(['out' => 1, 'in' => 0], $this->actingAs($staff, 'api')->getJson('/api/staff/inventory-movements?from=2026-10-05')->json('period_summary'));
        $this->assertSame(['out' => 1, 'in' => 1], $this->actingAs($staff, 'api')->getJson('/api/staff/inventory-movements?type=in')->json('period_summary'));
        $this->assertFalse($all->json('has_more'));

        $this->assertSame(['balik A'], array_column($this->actingAs($staff, 'api')->getJson('/api/staff/inventory-movements?type=in')->json('data'), 'note'));
        $this->assertSame(['ke A'], array_column($this->actingAs($staff, 'api')->getJson('/api/staff/inventory-movements?from=2026-10-05')->json('data'), 'note'));
        $this->assertSame(['balik A'], array_column($this->actingAs($staff, 'api')->getJson('/api/staff/inventory-movements?search=balik')->json('data'), 'note'));
        // Staf tidak bisa mengintip toko lain lewat store_id.
        $this->assertSame(['ke A', 'balik A'], array_column($this->actingAs($staff, 'api')->getJson('/api/staff/inventory-movements?store_id=' . $this->storeB->id)->json('data'), 'note'));
        $this->actingAs($staff, 'api')->getJson('/api/staff/inventory-movements?type=bogus')->assertStatus(422);

        $admin = $this->user('super_admin');
        $this->assertCount(3, $this->actingAs($admin, 'api')->getJson('/api/staff/inventory-movements')->json('data'));
        $this->assertSame(['ke B'], array_column($this->actingAs($admin, 'api')->getJson('/api/staff/inventory-movements?store_id=' . $this->storeB->id)->json('data'), 'note'));

        $this->actingAs($this->user('kasir', ['BookingResource'], ['store_id' => $this->storeA->id]), 'api')->getJson('/api/staff/inventory-movements')->assertStatus(403);
    }
}
