<?php

namespace Tests\Feature;

use App\Exports\AssetExport;
use App\Filament\Resources\AssetResource;
use App\Filament\Resources\AssetResource\Pages\CreateAsset;
use App\Filament\Resources\AssetResource\Pages\EditAsset;
use App\Filament\Resources\AssetResource\Pages\ListAssets;
use App\Models\Asset;
use App\Models\AssetTransfer;
use App\Models\ChartOfAccount;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AssetTestRowsExport implements FromArray
{
    public function __construct(private array $rows) {}

    public function array(): array
    {
        return $this->rows;
    }
}

/**
 * Aset Tetap: hak akses (hapus hanya full-access kecuali dibuka), staf hanya melihat/mengubah aset tokonya (aset kantor pusat
 * tersembunyi), daftar + pencarian + filter, nilai buku garis lurus (tidak di bawah residu, tidak di atas harga beli, kosong
 * kalau data belum lengkap), tambah (kode otomatis, toko dipaksa ke toko staf), ubah, Serah Terima resmi, hapus, ekspor, QR,
 * Import Excel. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class AssetResourceTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
        Asset::query()->delete();
        $this->storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->storeB = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?array $menuAccess = null, ?Store $store = null, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->storeA)->id, 'menu_access' => $menuAccess, 'is_active' => true], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function as(User $user): User
    {
        $this->actingAs($user, 'web');

        return $user;
    }

    private function asset(string $name, ?Store $store, array $extra = []): Asset
    {
        return Asset::create(array_merge(['asset_tag' => Asset::generateAssetTag(), 'name' => $name, 'category' => 'Mesin', 'status' => 'aktif', 'store_id' => $store?->id, 'received_date' => '2026-10-01'], $extra));
    }

    // ------------------------------------------------------------- akses & cakupan toko

    public function test_access_and_the_stricter_delete_rule(): void
    {
        $record = new Asset();

        $this->as($this->user('super_admin'));
        $this->assertTrue(AssetResource::canViewAny());
        $this->assertTrue(AssetResource::canDelete($record));
        $this->assertTrue(AssetResource::canDeleteAny());

        $this->as($this->user('kasir'));
        $this->assertTrue(AssetResource::canViewAny());
        $this->assertTrue(AssetResource::canCreate());
        $this->assertTrue(AssetResource::canEdit($record));
        $this->assertFalse(AssetResource::canDelete($record));
        $this->assertFalse(AssetResource::canDeleteAny());

        $this->as($this->user('kasir', null, null, ['menu_permissions' => ['AssetResource' => ['delete']]]));
        $this->assertTrue(AssetResource::canDelete($record));

        $this->as($this->user('kasir', ['BookingResource']));
        $this->assertFalse(AssetResource::canViewAny());
        $this->assertFalse(AssetResource::canCreate());
    }

    public function test_staff_see_only_their_store_assets_and_head_office_assets_are_hidden_from_them(): void
    {
        $mine = $this->asset('Mesin Toko A', $this->storeA);
        $theirs = $this->asset('Mesin Toko B', $this->storeB);
        $hq = $this->asset('Mesin Pusat', null);

        $this->as($this->user('kasir', null, $this->storeA));
        Livewire::test(ListAssets::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs, $hq]);

        $this->as($this->user('super_admin'));
        Livewire::test(ListAssets::class)->assertCanSeeTableRecords([$mine, $theirs, $hq]);
    }

    public function test_staff_cannot_open_an_asset_of_another_store(): void
    {
        $theirs = $this->asset('Mesin Toko B', $this->storeB);
        $this->as($this->user('kasir', null, $this->storeA));

        $this->expectException(ModelNotFoundException::class);

        Livewire::test(EditAsset::class, ['record' => $theirs->getKey()]);
    }

    // ------------------------------------------------------------- daftar

    public function test_list_search_labels_and_filters(): void
    {
        $this->as($this->user('super_admin'));
        $holder = $this->user('kasir', null, $this->storeA, ['name' => 'Rina Pemegang']);
        $a = $this->asset('Compressor Besar', $this->storeA, ['category' => 'Mesin', 'status' => 'aktif', 'assigned_to' => $holder->id, 'purchase_cost' => 10000000, 'purchase_date' => '2025-10-08', 'useful_life_years' => 5, 'salvage_value' => 0]);
        $b = $this->asset('Mobil Operasional', $this->storeB, ['category' => 'Kendaraan', 'status' => 'rusak']);
        $c = $this->asset('Laptop Admin', null, ['category' => 'Elektronik', 'status' => 'dijual']);

        Livewire::test(ListAssets::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$a, $b, $c])
            ->assertTableColumnFormattedStateSet('status', 'Aktif Dipakai', $a)
            ->assertTableColumnFormattedStateSet('status', 'Rusak', $b)
            ->assertTableColumnFormattedStateSet('status', 'Dijual', $c)
            ->assertTableColumnStateSet('assignee.name', 'Rina Pemegang', $a)
            ->assertTableColumnStateSet('store.name', 'Toko B', $b)
            ->assertTableColumnFormattedStateSet('book_value', '—', $b)
            ->searchTable($a->asset_tag)
            ->assertCanSeeTableRecords([$a])
            ->assertCanNotSeeTableRecords([$b, $c])
            ->searchTable('Mobil')
            ->assertCanSeeTableRecords([$b])
            ->assertCanNotSeeTableRecords([$a, $c]);

        Livewire::test(ListAssets::class)->filterTable('status', 'dijual')->assertCanSeeTableRecords([$c])->assertCanNotSeeTableRecords([$a, $b]);
        Livewire::test(ListAssets::class)->filterTable('category', 'Kendaraan')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $c]);
        Livewire::test(ListAssets::class)->filterTable('store_id', $this->storeA->id)->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b, $c]);
    }

    // ------------------------------------------------------------- nilai buku

    public function test_straight_line_book_value(): void
    {
        // Beli 8 Okt 2024 Rp12.000.000, umur 4 tahun, residu 2.000.000 => susut 2.500.000/tahun; hari ini tepat 2 tahun.
        $asset = $this->asset('Mesin', null, ['purchase_cost' => 12000000, 'purchase_date' => '2024-10-08', 'useful_life_years' => 4, 'salvage_value' => 2000000]);
        $this->assertEqualsWithDelta(7000000.0, $asset->currentBookValue(), 10000);

        // Sudah lewat umur ekonomis: tidak turun di bawah residu.
        $old = $this->asset('Tua', null, ['purchase_cost' => 12000000, 'purchase_date' => '2015-01-01', 'useful_life_years' => 4, 'salvage_value' => 2000000]);
        $this->assertEqualsWithDelta(2000000.0, $old->currentBookValue(), 0.01);

        // Dibeli di masa depan: tidak lebih dari harga beli.
        $future = $this->asset('Belum Dibeli', null, ['purchase_cost' => 5000000, 'purchase_date' => '2027-01-01', 'useful_life_years' => 5]);
        $this->assertEqualsWithDelta(5000000.0, $future->currentBookValue(), 0.01);

        // Tanpa residu: susut sampai 0.
        $zero = $this->asset('Habis', null, ['purchase_cost' => 1000000, 'purchase_date' => '2010-01-01', 'useful_life_years' => 2]);
        $this->assertEqualsWithDelta(0.0, $zero->currentBookValue(), 0.01);
    }

    public function test_book_value_is_null_without_complete_data_and_residue_never_exceeds_the_price(): void
    {
        $this->assertNull($this->asset('A', null, ['purchase_cost' => 100])->currentBookValue());
        $this->assertNull($this->asset('B', null, ['purchase_cost' => 100, 'purchase_date' => '2025-01-01'])->currentBookValue(), 'Tanpa umur ekonomis.');
        $this->assertNull($this->asset('C', null, ['purchase_date' => '2025-01-01', 'useful_life_years' => 3])->currentBookValue(), 'Tanpa harga beli.');

        $odd = $this->asset('Residu Kebesaran', null, ['purchase_cost' => 1000000, 'purchase_date' => '2024-01-01', 'useful_life_years' => 4, 'salvage_value' => 5000000]);
        $this->assertLessThanOrEqual(1000000.0, $odd->currentBookValue(), 'Nilai buku tidak boleh lebih besar dari harga beli.');
    }

    // ------------------------------------------------------------- tambah / ubah

    public function test_admin_creates_an_asset_with_an_automatic_tag_and_a_chosen_store(): void
    {
        $admin = $this->as($this->user('super_admin'));
        $asset = ChartOfAccount::where('code', '1210')->firstOrFail();
        $accum = ChartOfAccount::where('code', '1211')->firstOrFail();

        Livewire::test(CreateAsset::class)
            ->fillForm(['name' => 'Mesin Cutting', 'category' => 'Mesin', 'status' => 'aktif', 'store_id' => $this->storeB->id, 'received_date' => '2026-10-05', 'purchase_date' => '2026-10-01', 'purchase_cost' => 24000000, 'useful_life_years' => 8, 'salvage_value' => 4000000, 'chart_of_account_id' => $asset->id, 'accumulated_depreciation_account_id' => $accum->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = Asset::where('name', 'Mesin Cutting')->firstOrFail();
        $this->assertMatchesRegularExpression('/^ASSET-[A-Z0-9]{8}$/', $created->asset_tag);
        $this->assertSame([$admin->id, $this->storeB->id, $asset->id, $accum->id], [$created->created_by, $created->store_id, $created->chart_of_account_id, $created->accumulated_depreciation_account_id]);
        $this->assertNotNull(Activity::where('log_name', 'asset')->where('subject_id', $created->id)->where('description', 'like', '%didaftarkan%')->first());
    }

    public function test_staff_assets_are_forced_into_their_own_store(): void
    {
        $staff = $this->as($this->user('kasir', null, $this->storeA));

        Livewire::test(CreateAsset::class)
            ->fillForm(['name' => 'Kipas Toko', 'status' => 'aktif', 'received_date' => '2026-10-05', 'store_id' => $this->storeB->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = Asset::where('name', 'Kipas Toko')->firstOrFail();
        $this->assertSame($this->storeA->id, $created->store_id, 'Staf tidak bisa mendaftarkan aset ke toko lain.');

        Livewire::test(ListAssets::class)->assertCanSeeTableRecords([$created]);
    }

    public function test_create_validation(): void
    {
        $this->as($this->user('super_admin'));
        $accum = ChartOfAccount::where('code', '1211')->firstOrFail();

        Livewire::test(CreateAsset::class)
            ->fillForm(['name' => '', 'status' => null, 'received_date' => null])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required', 'status' => 'required', 'received_date' => 'required']);

        Livewire::test(CreateAsset::class)
            ->fillForm(['name' => 'Masa Depan', 'status' => 'aktif', 'received_date' => '2026-10-20'])
            ->call('create')
            ->assertHasFormErrors(['received_date']);

        Livewire::test(CreateAsset::class)
            ->fillForm(['name' => 'Status Liar', 'status' => 'dicuri', 'received_date' => '2026-10-05'])
            ->call('create')
            ->assertHasFormErrors(['status']);

        Livewire::test(CreateAsset::class)
            ->fillForm(['name' => 'Angka Salah', 'status' => 'aktif', 'received_date' => '2026-10-05', 'purchase_cost' => -1, 'useful_life_years' => 0, 'salvage_value' => -5])
            ->call('create')
            ->assertHasFormErrors(['purchase_cost', 'useful_life_years', 'salvage_value']);

        Livewire::test(CreateAsset::class)
            ->fillForm(['name' => 'Residu Kebesaran', 'status' => 'aktif', 'received_date' => '2026-10-05', 'purchase_cost' => 1000000, 'salvage_value' => 2000000])
            ->call('create')
            ->assertHasFormErrors(['salvage_value']);

        Livewire::test(CreateAsset::class)
            ->fillForm(['name' => 'Satu Akun', 'status' => 'aktif', 'received_date' => '2026-10-05', 'accumulated_depreciation_account_id' => $accum->id, 'chart_of_account_id' => null])
            ->call('create')
            ->assertHasFormErrors(['chart_of_account_id' => 'required']);

        $this->assertDatabaseMissing('assets', ['name' => 'Residu Kebesaran']);
    }

    public function test_edit_keeps_the_tag_and_staff_cannot_move_the_asset_to_another_store(): void
    {
        $staff = $this->as($this->user('kasir', null, $this->storeA));
        $asset = $this->asset('Nama Lama', $this->storeA);
        $tag = $asset->asset_tag;

        Livewire::test(EditAsset::class, ['record' => $asset->getKey()])
            ->assertFormSet(['name' => 'Nama Lama', 'asset_tag' => $tag])
            ->fillForm(['name' => 'Nama Baru', 'status' => 'diperbaiki', 'asset_tag' => 'ASSET-HACKED1', 'store_id' => $this->storeB->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $asset->refresh();
        $this->assertSame([$tag, 'Nama Baru', 'diperbaiki', $this->storeA->id], [$asset->asset_tag, $asset->name, $asset->status, $asset->store_id]);
    }

    public function test_delete_is_offered_only_to_full_access(): void
    {
        $asset = $this->asset('Untuk Dihapus', $this->storeA);

        $this->as($this->user('kasir', null, $this->storeA));
        Livewire::test(EditAsset::class, ['record' => $asset->getKey()])->assertActionHidden('delete');
        Livewire::test(ListAssets::class)->assertTableBulkActionHidden('delete');

        $this->as($this->user('super_admin'));
        Livewire::test(EditAsset::class, ['record' => $asset->getKey()])->assertActionVisible('delete')->callAction('delete');
        $this->assertDatabaseMissing('assets', ['id' => $asset->id]);
    }

    // ------------------------------------------------------------- Serah Terima

    public function test_a_transfer_records_the_handover_and_updates_the_holder(): void
    {
        $staff = $this->as($this->user('kasir', null, $this->storeA));
        $from = $this->user('kasir', null, $this->storeA, ['name' => 'Pemegang Lama']);
        $to = $this->user('kasir', null, $this->storeA, ['name' => 'Pemegang Baru']);
        $asset = $this->asset('Laptop', $this->storeA, ['assigned_to' => $from->id]);

        Livewire::test(ListAssets::class)
            ->callTableAction('transfer', $asset, data: ['to_user_id' => $to->id, 'condition_at_transfer' => 'baik', 'reason' => 'rotasi tugas'])
            ->assertHasNoTableActionErrors();

        $this->assertSame($to->id, $asset->fresh()->assigned_to);
        $transfer = AssetTransfer::where('asset_id', $asset->id)->firstOrFail();
        $this->assertSame([$from->id, $to->id, $this->storeA->id, $this->storeA->id, 'baik', 'rotasi tugas', $staff->id], [$transfer->from_user_id, $transfer->to_user_id, $transfer->from_store_id, $transfer->to_store_id, $transfer->condition_at_transfer, $transfer->reason, $transfer->performed_by]);
    }

    public function test_only_full_access_can_move_an_asset_to_another_store_by_transfer(): void
    {
        $asset = $this->asset('Compressor', $this->storeA);

        $this->as($this->user('kasir', null, $this->storeA));
        Livewire::test(ListAssets::class)
            ->callTableAction('transfer', $asset, data: ['to_store_id' => $this->storeB->id, 'condition_at_transfer' => 'baik', 'reason' => 'coba pindah toko'])
            ->assertHasNoTableActionErrors();
        $this->assertSame($this->storeA->id, $asset->fresh()->store_id, 'Staf tidak bisa memindahkan toko lewat serah terima.');

        $this->as($this->user('super_admin'));
        Livewire::test(ListAssets::class)
            ->callTableAction('transfer', $asset->fresh(), data: ['to_store_id' => $this->storeB->id, 'condition_at_transfer' => 'perlu_perhatian', 'reason' => 'pindah cabang'])
            ->assertHasNoTableActionErrors();
        $this->assertSame($this->storeB->id, $asset->fresh()->store_id);
        $last = AssetTransfer::where('asset_id', $asset->id)->latest('id')->first();
        $this->assertSame([$this->storeA->id, $this->storeB->id], [$last->from_store_id, $last->to_store_id]);
    }

    public function test_a_transfer_requires_condition_and_reason(): void
    {
        $this->as($this->user('kasir', null, $this->storeA));
        $asset = $this->asset('Laptop', $this->storeA);

        Livewire::test(ListAssets::class)
            ->callTableAction('transfer', $asset, data: ['condition_at_transfer' => null, 'reason' => ''])
            ->assertHasTableActionErrors(['condition_at_transfer' => 'required', 'reason' => 'required']);

        $this->assertSame(0, AssetTransfer::where('asset_id', $asset->id)->count());
    }

    // ------------------------------------------------------------- ekspor, QR, impor

    public function test_export_rows_are_scoped_to_the_user_and_download_actions_work(): void
    {
        $mine = $this->asset('Ekspor Toko A', $this->storeA, ['purchase_cost' => 1000000, 'purchase_date' => '2026-10-08', 'useful_life_years' => 4]);
        $theirs = $this->asset('Ekspor Toko B', $this->storeB);
        $hq = $this->asset('Ekspor Pusat', null);

        $this->as($this->user('kasir', null, $this->storeA));
        $names = (new AssetExport())->collection()->pluck(1)->all();
        $this->assertContains('Ekspor Toko A', $names);
        $this->assertNotContains('Ekspor Toko B', $names);
        $this->assertNotContains('Ekspor Pusat', $names);

        $this->as($this->user('super_admin'));
        $export = new AssetExport();
        $rows = $export->collection()->keyBy(0);
        $this->assertSame(['Kode', 'Nama Aset', 'Kategori', 'Status', 'Dipegang Oleh', 'Lokasi', 'Tanggal Beli', 'Harga Beli', 'Nilai Buku Saat Ini'], $export->headings());
        $this->assertEquals([$mine->asset_tag, 'Ekspor Toko A', 'Mesin', 'Aktif Dipakai', '-', 'Toko A', '2026-10-08', 1000000.0, 1000000.0], $rows[$mine->asset_tag]);
        $this->assertSame('Kantor Pusat', $rows[$hq->asset_tag][5]);

        Excel::fake();
        $page = Livewire::test(ListAssets::class);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();
        Excel::assertDownloaded('aset-tetap-20261008-100000.xlsx');
    }

    public function test_the_qr_pdf_downloads(): void
    {
        $this->as($this->user('kasir', null, $this->storeA));
        $asset = $this->asset('QR Aset', $this->storeA);

        Livewire::test(ListAssets::class)
            ->callTableAction('download_qr', $asset)
            ->assertFileDownloaded("QR-Aset-{$asset->asset_tag}.pdf");
    }

    public function test_import_actions_are_for_full_access_only(): void
    {
        $this->as($this->user('kasir', null, $this->storeA));
        Livewire::test(ListAssets::class)->assertTableActionHidden('import')->assertTableActionHidden('download_template');

        $this->as($this->user('super_admin'));
        Livewire::test(ListAssets::class)->assertTableActionVisible('import')->assertTableActionVisible('download_template');
    }

    public function test_excel_import_matches_people_and_stores_by_name_and_clamps_bad_values(): void
    {
        $admin = $this->as($this->user('super_admin'));
        Storage::fake('local');
        $holder = $this->user('kasir', null, $this->storeA, ['name' => 'Rina Pemegang']);

        Excel::store(new AssetTestRowsExport([
            ['Nama', 'Kategori', 'Status', 'Dipegang Oleh', 'Lokasi', 'Tgl Masuk', 'Tgl Beli', 'Harga', 'Catatan'],
            ['Printer', 'Elektronik', 'Rusak', 'Rina Pemegang', 'Toko B', '05/10/2026', '01/09/2026', 2500000, 'catatan P'],
            ['', 'Mesin', '', '', '', '', '', '', ''],
            ['Meja', 'Furnitur', 'status-aneh', 'Orang Tidak Ada', 'Toko Hantu', '', '', -100, ''],
        ]), 'asset-imports/test.xlsx', 'local');

        $method = new \ReflectionMethod(AssetResource::class, 'importAssets');
        $method->setAccessible(true);
        $method->invoke(null, 'asset-imports/test.xlsx');

        $printer = Asset::where('name', 'Printer')->firstOrFail();
        $this->assertMatchesRegularExpression('/^ASSET-/', $printer->asset_tag);
        $this->assertSame(['rusak', $holder->id, $this->storeB->id, '2026-10-05', '2026-09-01', 'catatan P', $admin->id], [$printer->status, $printer->assigned_to, $printer->store_id, $printer->received_date->toDateString(), $printer->purchase_date->toDateString(), $printer->notes, $printer->created_by]);
        $this->assertEqualsWithDelta(2500000.0, (float) $printer->purchase_cost, 0.001);

        $desk = Asset::where('name', 'Meja')->firstOrFail();
        $this->assertSame(['aktif', null, null], [$desk->status, $desk->assigned_to, $desk->store_id], 'Status aneh = aktif; nama tidak ketemu dikosongkan.');
        $this->assertEqualsWithDelta(0.0, (float) $desk->purchase_cost, 0.001, 'Harga negatif dijepit ke 0.');
        $this->assertSame(2, Asset::count(), 'Baris tanpa nama dilewati.');
        Storage::disk('local')->assertMissing('asset-imports/test.xlsx');
    }
}
