<?php

namespace Tests\Feature;

use App\Filament\Resources\RawMaterialMovementResource;
use App\Filament\Resources\RawMaterialMovementResource\Pages\ListRawMaterialMovements;
use App\Filament\Resources\RawMaterialMovementResource\Widgets\RawMaterialMovementStatsOverview;
use App\Models\RawMaterial;
use App\Models\RawMaterialMovement;
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
 * Riwayat Bahan Baku (lintas semua bahan): hak akses (hanya baca), daftar + pencarian + semua filter, ringkasan bulan
 * berjalan, ekspor mengikuti filter + log, Cek Rekonsiliasi, dan "Batalkan" (hanya full-access, hanya kejadian terakhir;
 * batch yang benar ikut terhapus). "Hari ini" dibekukan di 8 Oktober 2026.
 */
class RawMaterialMovementResourceTest extends TestCase
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
        RawMaterialMovement::query()->delete();
        RawMaterial::query()->delete();
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

    private function material(string $name, string $category = 'chemical', float $stock = 0): RawMaterial
    {
        return RawMaterial::create(['name' => $name, 'code' => 'RM-' . strtoupper(uniqid()), 'category' => $category, 'unit' => 'liter', 'current_stock' => $stock]);
    }

    private function move(RawMaterial $material, string $type, float $qty, ?User $user = null, ?float $cost = null, string $note = 'catatan', ?string $at = null): RawMaterialMovement
    {
        $movement = RawMaterialMovement::create(['raw_material_id' => $material->id, 'type' => $type, 'quantity' => $qty, 'unit_cost' => $cost, 'note' => $note, 'user_id' => $user?->id]);

        if ($at) {
            DB::table('raw_material_movements')->where('id', $movement->id)->update(['created_at' => $at, 'updated_at' => $at]);
        }

        return $movement->fresh();
    }

    // ------------------------------------------------------------- akses

    public function test_access_is_read_only(): void
    {
        $record = new RawMaterialMovement();

        $this->as($this->user('super_admin'));
        $this->assertTrue(RawMaterialMovementResource::canViewAny());
        $this->assertFalse(RawMaterialMovementResource::canCreate());
        $this->assertFalse(RawMaterialMovementResource::canEdit($record));
        $this->assertFalse(RawMaterialMovementResource::canDelete($record));

        $this->as($this->user('kasir'));
        $this->assertTrue(RawMaterialMovementResource::canViewAny());

        $this->as($this->user('kasir', ['BookingResource']));
        $this->assertFalse(RawMaterialMovementResource::canViewAny());
    }

    // ------------------------------------------------------------- daftar & filter

    public function test_list_shows_labels_and_signs(): void
    {
        $material = $this->material('Adhesive');
        $in = $this->move($material, 'in', 10, null, 15000);
        $out = $this->move($material, 'out', 3);
        $up = $this->move($material, 'adjustment', 2);
        $down = $this->move($material, 'adjustment', -1);
        $correction = $this->move($material, 'correction', 0);

        $this->as($this->user('super_admin'));
        Livewire::test(ListRawMaterialMovements::class)
            ->assertCanSeeTableRecords([$in, $out, $up, $down, $correction])
            ->assertTableColumnFormattedStateSet('type', 'Masuk', record: $in)
            ->assertTableColumnFormattedStateSet('type', 'Keluar', record: $out)
            ->assertTableColumnFormattedStateSet('type', 'Penyesuaian (Opname)', record: $up)
            ->assertTableColumnFormattedStateSet('type', 'Koreksi', record: $correction)
            ->assertTableColumnFormattedStateSet('quantity', '10.00 liter', record: $in)
            ->assertTableColumnFormattedStateSet('quantity', '+2.00 liter', record: $up)
            ->assertTableColumnFormattedStateSet('quantity', '-1.00 liter', record: $down)
            ->assertTableActionHidden('reverse', $correction);

        $material->delete();
        Livewire::test(ListRawMaterialMovements::class)->assertSuccessful();
    }

    public function test_search_and_filters(): void
    {
        $adhesive = $this->material('Adhesive', 'chemical');
        $cloth = $this->material('Kain Lap', 'cleaning');
        $alice = $this->user('kasir', null, ['name' => 'Alice']);
        $bob = $this->user('kasir', null, ['name' => 'Bob']);

        $a = $this->move($adhesive, 'out', 1, $alice, null, 'catatan-a', '2026-10-01 09:00:00');
        $b = $this->move($cloth, 'in', 5, $bob, 2000, 'catatan-b', '2026-10-05 09:00:00');
        $c = $this->move($cloth, 'out', 2, $bob, null, 'catatan-c', '2026-10-07 09:00:00');

        $this->as($this->user('super_admin'));

        Livewire::test(ListRawMaterialMovements::class)->searchTable('Adhesive')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b, $c]);
        Livewire::test(ListRawMaterialMovements::class)->searchTable('catatan-b')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $c]);
        Livewire::test(ListRawMaterialMovements::class)->searchTable('Bob')->assertCanSeeTableRecords([$b, $c])->assertCanNotSeeTableRecords([$a]);

        Livewire::test(ListRawMaterialMovements::class)->filterTable('type', 'in')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $c]);
        Livewire::test(ListRawMaterialMovements::class)->filterTable('raw_material_id', $cloth->id)->assertCanSeeTableRecords([$b, $c])->assertCanNotSeeTableRecords([$a]);
        Livewire::test(ListRawMaterialMovements::class)->filterTable('user_id', $alice->id)->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b, $c]);
        Livewire::test(ListRawMaterialMovements::class)
            ->filterTable('created_at', ['from' => '2026-10-02', 'until' => '2026-10-06'])
            ->assertCanSeeTableRecords([$b])
            ->assertCanNotSeeTableRecords([$a, $c]);
    }

    // ------------------------------------------------------------- ringkasan

    public function test_the_stats_cover_the_current_month_only(): void
    {
        $adhesive = $this->material('Adhesive');
        $cloth = $this->material('Kain Lap');
        $this->move($adhesive, 'out', 4, null, null, 'x', '2026-10-02 09:00:00');
        $this->move($adhesive, 'out', 3, null, null, 'x', '2026-10-03 09:00:00');
        $this->move($cloth, 'out', 5, null, null, 'x', '2026-10-04 09:00:00');
        $this->move($cloth, 'in', 9, null, 1000, 'x', '2026-10-05 09:00:00');
        $this->move($cloth, 'out', 99, null, null, 'x', '2026-09-30 09:00:00');

        $this->as($this->user('super_admin'));
        Livewire::test(RawMaterialMovementStatsOverview::class)
            ->assertSee('Keluar Bulan Ini')
            ->assertSee('Masuk Bulan Ini')
            ->assertSee('Adhesive')
            ->assertSee('7.00 liter bulan ini')
            ->assertDontSee('99.00');
    }

    // ------------------------------------------------------------- ekspor

    public function test_export_follows_the_active_filter_and_is_logged(): void
    {
        $material = $this->material('Adhesive', 'chemical');
        $staff = $this->user('kasir', null, ['name' => 'Alice']);
        $this->move($material, 'in', 10, $staff, 15000, 'Beli');
        $this->move($material, 'adjustment', 2, $staff, null, 'Opname');
        $this->move($material, 'correction', 0, $staff, null, 'Koreksi');

        $admin = $this->as($this->user('super_admin'));
        Excel::fake();
        Livewire::test(ListRawMaterialMovements::class)
            ->filterTable('type', 'in')
            ->callTableAction('export')
            ->assertHasNoTableActionErrors();

        Excel::assertDownloaded('riwayat-bahan-baku-20261008.xlsx', function ($export) {
            $rows = $export->query()->get();

            return $rows->count() === 1
                && $export->map($rows->first()) === ['08/10/2026 10:00', 'Adhesive', 'chemical', 'Masuk', '10.00', 'liter', '15,000.00', 'Alice', 'Beli'];
        });

        $log = Activity::where('log_name', 'report_export')->latest('id')->firstOrFail();
        $this->assertSame('raw_material_movement', $log->properties['report']);
        $this->assertSame($admin->id, $log->causer_id);

        $export = new \App\Exports\RawMaterialMovementExport();
        $types = $export->query()->get()->map(fn ($m) => $export->map($m)[3])->all();
        $this->assertContains('Koreksi', $types, 'Baris koreksi punya label sendiri, bukan kata mentah.');
        $this->assertContains('Penyesuaian (Opname)', $types);
    }

    // ------------------------------------------------------------- rekonsiliasi

    public function test_reconciliation_flags_only_drifting_materials(): void
    {
        $clean = $this->material('Bersih', 'chemical', 7);
        $this->move($clean, 'in', 10);
        $this->move($clean, 'out', 4);
        $this->move($clean, 'adjustment', 1);

        $this->as($this->user('super_admin'));
        Livewire::test(ListRawMaterialMovements::class)
            ->callTableAction('reconcile')
            ->assertNotified('Rekonsiliasi bersih');

        $drift = $this->material('Melenceng', 'chemical', 50);
        $this->move($drift, 'in', 10);

        Livewire::test(ListRawMaterialMovements::class)
            ->callTableAction('reconcile')
            ->assertNotified('1 bahan baku tidak cocok');
    }

    // ------------------------------------------------------------- batalkan

    public function test_reverse_is_for_full_access_and_the_latest_movement_only(): void
    {
        $material = $this->material('Adhesive', 'chemical', 10);
        $first = $this->move($material, 'in', 10);
        $latest = $this->move($material, 'out', 2);

        $this->as($this->user('kasir'));
        Livewire::test(ListRawMaterialMovements::class)->assertTableActionHidden('reverse', $latest);

        $this->as($this->user('super_admin'));
        Livewire::test(ListRawMaterialMovements::class)
            ->assertTableActionVisible('reverse', $latest)
            ->assertTableActionHidden('reverse', $first);
    }

    public function test_reversing_an_out_gives_the_stock_back_and_leaves_a_correction(): void
    {
        $admin = $this->as($this->user('super_admin'));
        $material = $this->material('Adhesive', 'chemical', 0);
        $material->recordMovement('in', 10, $admin->id, null, null, null, 1000);
        $out = $material->fresh()->recordMovement('out', 4, $admin->id, 'Pakai', storeId: $this->storeA->id);
        $this->assertEquals(6, (float) $material->fresh()->current_stock);

        Livewire::test(ListRawMaterialMovements::class)
            ->callTableAction('reverse', $out)
            ->assertHasNoTableActionErrors();

        $this->assertEquals(10, (float) $material->fresh()->current_stock);
        $this->assertNull(RawMaterialMovement::find($out->id));
        $correction = $material->movements()->where('type', 'correction')->firstOrFail();
        $this->assertEquals(0, (float) $correction->quantity);
        $this->assertSame($admin->id, $correction->user_id);
        $this->assertSame($this->storeA->id, $correction->store_id, 'Koreksi mewarisi penanda toko.');
    }

    public function test_reversing_an_in_deletes_the_batch_it_created_not_the_oldest_one(): void
    {
        $admin = $this->as($this->user('super_admin'));
        $material = $this->material('Adhesive', 'chemical', 0);
        $material->recordMovement('in', 5, $admin->id, null, '2026-01-01');
        $in = $material->fresh()->recordMovement('in', 8, $admin->id, 'Salah input', '2026-10-08');
        $this->assertSame(2, $material->batches()->count());

        Livewire::test(ListRawMaterialMovements::class)
            ->callTableAction('reverse', $in)
            ->assertHasNoTableActionErrors();

        $this->assertEquals(5, (float) $material->fresh()->current_stock);
        $batches = $material->batches()->get();
        $this->assertCount(1, $batches);
        $this->assertEquals(5, (float) $batches->first()->quantity, 'Batch lama (Januari) tetap utuh; yang terhapus batch dari pencatatan ini.');
    }

    public function test_reversing_an_upward_stocktake_removes_its_batch(): void
    {
        $admin = $this->as($this->user('super_admin'));
        $material = $this->material('Adhesive', 'chemical', 0);
        $material->recordMovement('in', 5, $admin->id, null, '2026-01-01');
        $adjustment = $material->fresh()->adjustStock(8, $admin->id, 'Opname');
        $this->assertSame(2, $material->batches()->count());

        Livewire::test(ListRawMaterialMovements::class)
            ->callTableAction('reverse', $adjustment)
            ->assertHasNoTableActionErrors();

        $this->assertEquals(5, (float) $material->fresh()->current_stock);
        $this->assertSame(1, $material->batches()->count());
        $this->assertEquals(5, (float) $material->batches()->firstOrFail()->quantity);
    }

    public function test_an_older_movement_cannot_be_reversed_by_the_model(): void
    {
        $admin = $this->user('super_admin');
        $material = $this->material('Adhesive', 'chemical', 0);
        $in = $material->recordMovement('in', 5, $admin->id);
        $material->fresh()->recordMovement('out', 1, $admin->id);

        $this->expectException(\InvalidArgumentException::class);
        $material->fresh()->reverseLastMovement($in, $admin->id);
    }
}
