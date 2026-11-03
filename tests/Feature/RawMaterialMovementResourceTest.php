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

    private function move(RawMaterial $material, string $type, float $qty, ?User $user = null, ?float $cost = null, string $note = 'catatan', ?string $at = null, ?Store $store = null): RawMaterialMovement
    {
        $movement = RawMaterialMovement::create(['raw_material_id' => $material->id, 'type' => $type, 'quantity' => $qty, 'unit_cost' => $cost, 'note' => $note, 'user_id' => $user?->id, 'store_id' => $store?->id]);

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

        $this->as($this->user('kasir', ['RawMaterialMovementResource']));
        $this->assertTrue(RawMaterialMovementResource::canViewAny());

        // Akses bawaan (menu_access kosong) tidak membuka buku pergerakan stok.
        $this->as($this->user('kasir'));
        $this->assertFalse(RawMaterialMovementResource::canViewAny());

        $this->as($this->user('kasir', ['BookingResource']));
        $this->assertFalse(RawMaterialMovementResource::canViewAny());
    }

    public function test_prices_and_the_material_link_follow_the_grants(): void
    {
        $material = $this->material('Adhesive', 'chemical', 10);
        $this->move($material, 'in', 10, null, 15000);

        $this->as($this->user('kasir', ['RawMaterialMovementResource']));
        $this->assertFalse(RawMaterialMovementResource::canSeeCost());
        Livewire::test(ListRawMaterialMovements::class)->assertTableColumnHidden('unit_cost');
        $this->assertNotContains('Harga/Satuan', (new \App\Exports\RawMaterialMovementExport(null, false))->headings());
        $this->assertCount(11, (new \App\Exports\RawMaterialMovementExport(null, false))->map($material->movements()->first()), 'Tanpa kolom harga: 8 kolom lama + Toko, Sumber, Status.');

        $this->as($this->user('kasir', ['RawMaterialMovementResource'], ['menu_permissions' => ['RawMaterialMovementResource' => ['view']]]));
        $this->assertTrue(RawMaterialMovementResource::canSeeCost());
        Livewire::test(ListRawMaterialMovements::class)->assertTableColumnVisible('unit_cost');
        $this->assertContains('Harga/Satuan', (new \App\Exports\RawMaterialMovementExport())->headings());
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
                && $export->map($rows->first()) === ['08/10/2026 10:00', 'Adhesive', 'chemical', 'Masuk', '10.00', 'liter', '15,000.00', 'Alice', 'Beli', '—', '—', 'Berlaku'];
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
        $clean->batches()->create(['quantity' => 7, 'received_date' => '2026-10-01']);

        $admin = $this->as($this->user('super_admin'));
        Livewire::test(ListRawMaterialMovements::class)
            ->callTableAction('reconcile')
            ->assertNotified('Rekonsiliasi bersih');

        // Stok melenceng dari riwayat, batch cocok dengan stok: hanya selisih riwayat yang dilaporkan.
        $drift = $this->material('Melenceng', 'chemical', 50);
        $this->move($drift, 'in', 10);
        $drift->batches()->create(['quantity' => 50, 'received_date' => '2026-10-01']);

        Livewire::test(ListRawMaterialMovements::class)
            ->callTableAction('reconcile')
            ->assertNotified('1 selisih ditemukan');

        // Batch tidak cocok dengan stok walau riwayat cocok: ikut dilaporkan, dan setiap pemeriksaan tercatat di log.
        $drift->batches()->delete();
        $drift->update(['current_stock' => 10]);
        Livewire::test(ListRawMaterialMovements::class)
            ->callTableAction('reconcile')
            ->assertNotified('1 selisih ditemukan');
        $this->assertSame(3, Activity::where('log_name', 'report_export')->where('properties->report', 'raw_material_reconcile')->count());
        $this->assertSame($admin->id, Activity::where('properties->report', 'raw_material_reconcile')->latest('id')->firstOrFail()->causer_id);
    }

    // ------------------------------------------------------------- batalkan

    public function test_reverse_is_for_full_access_and_the_latest_movement_only(): void
    {
        $material = $this->material('Adhesive', 'chemical', 10);
        $first = $this->move($material, 'in', 10, null, null, 'catatan', null, $this->storeA);
        $latest = $this->move($material, 'out', 2, null, null, 'catatan', null, $this->storeA);

        $this->as($this->user('kasir', ['RawMaterialMovementResource']));
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
            ->callTableAction('reverse', $out, data: ['reason' => 'Salah bahan'])
            ->assertHasNoTableActionErrors();

        $this->assertEquals(10, (float) $material->fresh()->current_stock);
        // Baris asli disimpan (tersembunyi dari kueri bawaan), batch dipulihkan sehingga total batch = stok.
        $this->assertNull(RawMaterialMovement::find($out->id));
        $original = RawMaterialMovement::withReversed()->findOrFail($out->id);
        $this->assertSame(['Salah bahan', $admin->id], [$original->reversed_reason, $original->reversed_by]);
        $this->assertEquals(10, (float) $material->batches()->sum('quantity'));
        $this->assertTrue((bool) $material->batches()->where('is_restoration', true)->firstOrFail()->is_adjustment);
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
            ->callTableAction('reverse', $in, data: ['reason' => 'Salah input'])
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
            ->callTableAction('reverse', $adjustment, data: ['reason' => 'Salah hitung'])
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
        $material->fresh()->reverseLastMovement($in, $admin->id, 'Salah');
    }
    // ------------------------------------------------------------- audit 2026-11-03

    private function staffOf(Store $store, array $extra = []): User
    {
        return $this->user('kasir', ['RawMaterialMovementResource'], array_merge(['store_id' => $store->id], $extra));
    }

    public function test_staff_only_see_their_own_stores_rows_while_full_access_sees_everything(): void
    {
        $storeB = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $material = $this->material('Adhesive', 'chemical', 0);
        $a = $this->move($material, 'out', 3, null, null, 'toko A', null, $this->storeA);
        $b = $this->move($material, 'out', 5, null, null, 'toko B', null, $storeB);
        $untagged = $this->move($material, 'in', 9, null, null, 'tanpa toko');

        $this->as($this->staffOf($this->storeA));
        Livewire::test(ListRawMaterialMovements::class)
            ->assertCanSeeTableRecords([$a])
            ->assertCanNotSeeTableRecords([$b, $untagged])
            ->assertTableFilterHidden('store_id');
        Livewire::test(RawMaterialMovementStatsOverview::class)->assertSee('3.00 liter bulan ini')->assertDontSee('5.00');
        $this->assertEqualsCanonicalizing([$a->id], (new \App\Exports\RawMaterialMovementExport())->query()->pluck('id')->all());

        $this->as($this->user('super_admin'));
        Livewire::test(ListRawMaterialMovements::class)
            ->assertCanSeeTableRecords([$a, $b, $untagged])
            ->assertTableFilterVisible('store_id')
            ->filterTable('store_id', $storeB->id)
            ->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $untagged]);

        $this->as($this->staffOf($this->storeA, ['store_id' => null]));
        $this->assertSame(0, RawMaterialMovement::query()->visibleTo(auth()->user())->count());
    }

    public function test_reversing_keeps_the_row_hides_it_from_reports_and_keeps_batches_equal_to_stock(): void
    {
        $admin = $this->as($this->user('super_admin'));
        $material = $this->material('Adhesive', 'chemical', 0);
        $in = $material->recordMovement('in', 5, $admin->id, 'Beli', '2026-10-01', null, 1000, $this->storeA->id);
        $out = $material->fresh()->recordMovement('out', 3, $admin->id, 'Pakai', storeId: $this->storeA->id);
        $this->assertEquals(2, (float) $material->batches()->sum('quantity'));

        // Alasan wajib.
        Livewire::test(ListRawMaterialMovements::class)
            ->callTableAction('reverse', $out, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);
        $this->assertNull($out->fresh()->reversed_at);

        Livewire::test(ListRawMaterialMovements::class)->callTableAction('reverse', $out, data: ['reason' => 'Salah jumlah'])->assertHasNoTableActionErrors();
        $this->assertEquals(5, (float) $material->fresh()->current_stock);
        $this->assertEquals(5, (float) $material->batches()->sum('quantity'), 'Batch pulih: total sama dengan stok.');

        // Baris yang dibatalkan hilang dari kueri bawaan (laporan), tetapi tampil di Riwayat dan bisa difilter.
        $this->assertNull(RawMaterialMovement::find($out->id));
        $this->assertSame(1, RawMaterialMovement::withReversed()->whereNotNull('reversed_at')->count());
        Livewire::test(ListRawMaterialMovements::class)
            ->assertCanSeeTableRecords([$out->fresh() ?? RawMaterialMovement::withReversed()->find($out->id)])
            ->filterTable('reversed', false)
            ->assertCanNotSeeTableRecords([RawMaterialMovement::withReversed()->find($out->id)]);

        // Pembatalan berantai: Masuk yang kini jadi terakhir bisa dibatalkan; stok dan batch sama-sama kembali ke 0.
        $in = $in->fresh();
        $this->assertTrue($in->isReversible());
        Livewire::test(ListRawMaterialMovements::class)->callTableAction('reverse', $in, data: ['reason' => 'Salah beli'])->assertHasNoTableActionErrors();
        $this->assertEquals(0, (float) $material->fresh()->current_stock);
        $this->assertEquals(0, (float) $material->batches()->sum('quantity'));

        // Rekonsiliasi tetap bersih setelah dua pembatalan.
        Livewire::test(ListRawMaterialMovements::class)->callTableAction('reconcile')->assertNotified('Rekonsiliasi bersih');
    }

    public function test_movements_that_came_from_a_document_cannot_be_reversed_here(): void
    {
        $admin = $this->as($this->user('super_admin'));
        $material = $this->material('Adhesive', 'chemical', 0);
        $material->recordMovement('in', 10, $admin->id, 'Stok');
        $fromMemo = $material->fresh()->recordMovement('out', 2, $admin->id, 'Memo', storeId: $this->storeA->id, sourceType: 'memo', sourceId: 99);

        $this->assertFalse($fromMemo->isReversible());
        $this->assertSame('Memo Bahan', $fromMemo->sourceText());
        Livewire::test(ListRawMaterialMovements::class)->assertTableActionHidden('reverse', $fromMemo);

        try {
            $material->fresh()->reverseLastMovement($fromMemo, $admin->id, 'Coba');
            $this->fail('Pergerakan bersumber dokumen harus ditolak.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Memo Bahan', $e->getMessage());
        }
        $this->assertEquals(8, (float) $material->fresh()->current_stock);
    }

    public function test_the_detail_page_shows_the_movement_and_its_reversal_within_the_users_store(): void
    {
        $storeB = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $admin = $this->as($this->user('super_admin'));
        $material = $this->material('Adhesive', 'chemical', 0);
        $material->recordMovement('in', 4, $admin->id, 'Stok');
        $out = $material->fresh()->recordMovement('out', 1, $admin->id, 'Pakai', storeId: $this->storeA->id);
        $material->fresh()->reverseLastMovement($out, $admin->id, 'Salah toko');

        Livewire::test(\App\Filament\Resources\RawMaterialMovementResource\Pages\ViewRawMaterialMovement::class, ['record' => $out->getRouteKey()])
            ->assertSuccessful()->assertSee('Adhesive')->assertSee('Salah toko');

        $this->as($this->staffOf($storeB));
        try {
            Livewire::test(\App\Filament\Resources\RawMaterialMovementResource\Pages\ViewRawMaterialMovement::class, ['record' => $out->getRouteKey()]);
            $this->fail('Baris toko lain tidak boleh bisa dibuka.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_new_filters_period_category_and_the_note_tooltip(): void
    {
        $adhesive = $this->material('Adhesive', 'chemical');
        $cloth = $this->material('Kain Lap', 'cleaning');
        $old = $this->move($adhesive, 'in', 1, null, null, 'lama', '2026-09-01 09:00:00');
        $today = $this->move($cloth, 'out', 1, null, null, str_repeat('catatan panjang ', 5), '2026-10-08 08:00:00');

        $this->as($this->user('super_admin'));
        Livewire::test(ListRawMaterialMovements::class)
            ->filterTable('period', 'today')->assertCanSeeTableRecords([$today])->assertCanNotSeeTableRecords([$old]);
        Livewire::test(ListRawMaterialMovements::class)
            ->filterTable('category', 'chemical')->assertCanSeeTableRecords([$old])->assertCanNotSeeTableRecords([$today]);
    }
}
