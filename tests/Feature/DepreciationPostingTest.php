<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Store;
use App\Models\User;
use App\Services\DepreciationPostingService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Penyusutan Aset Tetap otomatis (garis lurus, satu jurnal per aset per bulan): syarat aset, jumlah per bulan (memperhitungkan
 * nilai residu), idempoten, tidak menyusutkan sebelum aset dibeli, bulan terakhir menghabiskan sisa pembulatan lalu berhenti,
 * aset belum terhubung ke akun dilaporkan, dan command bulanan (bulan lalu secara bawaan, opsi --month, pemberitahuan ke
 * full-access). "Hari ini" dibekukan di 8 Oktober 2026.
 */
class DepreciationPostingTest extends TestCase
{
    use RefreshDatabase;

    private ChartOfAccount $assetAccount;
    private ChartOfAccount $accumulated;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountSeeder::class);
        Role::findOrCreate('super_admin', 'web');
        Carbon::setTestNow('2026-10-08 10:00:00');
        Asset::query()->delete();
        $this->assetAccount = ChartOfAccount::where('code', '1210')->firstOrFail();
        $this->accumulated = ChartOfAccount::where('code', '1211')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function asset(string $name, array $extra = []): Asset
    {
        return Asset::create(array_merge([
            'asset_tag' => Asset::generateAssetTag(), 'name' => $name, 'category' => 'Mesin', 'status' => 'aktif', 'received_date' => '2025-01-01',
            'purchase_cost' => 12000000, 'purchase_date' => '2025-01-01', 'useful_life_years' => 5, 'salvage_value' => 0,
            'chart_of_account_id' => $this->assetAccount->id, 'accumulated_depreciation_account_id' => $this->accumulated->id,
        ], $extra));
    }

    private function journals(Asset $asset)
    {
        return JournalEntry::where('reference_type', 'asset_depreciation')->where('reference_id', $asset->id)->orderBy('entry_date')->get();
    }

    // ------------------------------------------------------------- jurnal bulanan

    public function test_one_posted_balanced_journal_per_eligible_asset(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $asset = $this->asset('Mesin Cutting', ['store_id' => $store->id]);

        $result = app(DepreciationPostingService::class)->postForMonth(Carbon::parse('2026-09-15'));

        $this->assertSame(['posted' => 1, 'skipped' => 0, 'messages' => []], $result);
        $entry = $this->journals($asset)->sole()->load('lines');
        $this->assertTrue($entry->isPosted());
        $this->assertTrue($entry->isBalanced());
        $this->assertSame('2026-09-30', $entry->entry_date->toDateString());
        $this->assertSame($store->id, $entry->store_id);
        $this->assertStringContainsString('Penyusutan Mesin Cutting', $entry->description);

        $debit = $entry->lines->firstWhere('chart_of_account_id', ChartOfAccount::where('code', '6420')->value('id'));
        $credit = $entry->lines->firstWhere('chart_of_account_id', $this->accumulated->id);
        $this->assertEquals(200000, (float) $debit->debit, '12.000.000 / 60 bulan.');
        $this->assertEquals(200000, (float) $credit->credit);
    }

    public function test_the_salvage_value_reduces_the_monthly_amount(): void
    {
        $asset = $this->asset('Mesin Residu', ['purchase_cost' => 12000000, 'salvage_value' => 2400000, 'useful_life_years' => 4]);

        app(DepreciationPostingService::class)->postForMonth(Carbon::parse('2026-09-01'));

        $line = $this->journals($asset)->sole()->lines()->where('debit', '>', 0)->firstOrFail();
        $this->assertEquals(200000, (float) $line->debit, '(12.000.000 - 2.400.000) / 48 bulan.');
    }

    public function test_running_the_same_month_twice_posts_once(): void
    {
        $asset = $this->asset('Mesin Cutting');
        $service = app(DepreciationPostingService::class);

        $first = $service->postForMonth(Carbon::parse('2026-09-01'));
        $second = $service->postForMonth(Carbon::parse('2026-09-20'));

        $this->assertSame(1, $first['posted']);
        $this->assertSame(0, $second['posted']);
        $this->assertSame(1, $second['skipped']);
        $this->assertCount(1, $this->journals($asset));
    }

    // ------------------------------------------------------------- syarat aset

    public function test_ineligible_assets_are_left_out(): void
    {
        $sold = $this->asset('Terjual', ['status' => 'dijual']);
        $noCost = $this->asset('Tanpa Harga', ['purchase_cost' => null]);
        $noDate = $this->asset('Tanpa Tanggal', ['purchase_date' => null]);
        $noLife = $this->asset('Tanpa Umur', ['useful_life_years' => null]);
        $zeroLife = $this->asset('Umur Nol', ['useful_life_years' => 0]);
        $fullSalvage = $this->asset('Residu Penuh', ['salvage_value' => 12000000]);

        $result = app(DepreciationPostingService::class)->postForMonth(Carbon::parse('2026-09-01'));

        $this->assertSame(0, $result['posted']);
        $this->assertSame([], $result['messages']);
        $this->assertSame(0, JournalEntry::where('reference_type', 'asset_depreciation')->count());
    }

    public function test_an_asset_not_yet_bought_in_that_month_is_not_depreciated(): void
    {
        $later = $this->asset('Dibeli Oktober', ['purchase_date' => '2026-10-05', 'received_date' => '2026-10-05']);
        $sameMonth = $this->asset('Dibeli September', ['purchase_date' => '2026-09-25', 'received_date' => '2026-09-25']);

        $result = app(DepreciationPostingService::class)->postForMonth(Carbon::parse('2026-09-01'));

        $this->assertSame(1, $result['posted']);
        $this->assertCount(0, $this->journals($later), 'Belum ada pada bulan September.');
        $this->assertCount(1, $this->journals($sameMonth));
    }

    public function test_an_asset_without_linked_accounts_is_reported_not_silently_dropped(): void
    {
        $unlinked = $this->asset('Belum Terhubung', ['chart_of_account_id' => null, 'accumulated_depreciation_account_id' => null]);
        $half = $this->asset('Setengah Terhubung', ['accumulated_depreciation_account_id' => null]);

        $result = app(DepreciationPostingService::class)->postForMonth(Carbon::parse('2026-09-01'));

        $this->assertSame(0, $result['posted']);
        $this->assertSame(2, $result['skipped']);
        $this->assertCount(2, $result['messages']);
        $this->assertStringContainsString('Belum Terhubung', implode(' ', $result['messages']));
        $this->assertStringContainsString('belum dihubungkan ke akun', $result['messages'][0]);
    }

    public function test_a_missing_expense_account_stops_everything_with_a_message(): void
    {
        $this->asset('Mesin Cutting');
        // Lewat query langsung: model menolak menghapus akun sistem.
        \DB::table('chart_of_accounts')->where('code', '6420')->delete();

        $result = app(DepreciationPostingService::class)->postForMonth(Carbon::parse('2026-09-01'));

        $this->assertSame(0, $result['posted']);
        $this->assertStringContainsString('6420', $result['messages'][0]);
    }

    public function test_a_posting_error_is_reported_per_asset_and_does_not_block_the_others(): void
    {
        $good = $this->asset('Bagus');
        $otherAccumulated = ChartOfAccount::where('code', '1221')->firstOrFail();
        $bad = $this->asset('Akun Nonaktif', ['accumulated_depreciation_account_id' => $otherAccumulated->id]);
        $otherAccumulated->update(['is_active' => false]);

        $result = app(DepreciationPostingService::class)->postForMonth(Carbon::parse('2026-09-01'));

        $this->assertSame(1, $result['posted']);
        $this->assertCount(1, $this->journals($good));
        $this->assertCount(0, $this->journals($bad));
        $this->assertStringContainsString('Akun Nonaktif', $result['messages'][0]);
    }

    // ------------------------------------------------------------- umur ekonomis

    public function test_the_total_depreciation_equals_the_depreciable_amount_exactly_and_then_stops(): void
    {
        $asset = $this->asset('Mesin Setahun', ['purchase_cost' => 1000000, 'purchase_date' => '2025-01-01', 'useful_life_years' => 1]);
        $service = app(DepreciationPostingService::class);

        foreach (range(1, 12) as $month) {
            $result = $service->postForMonth(Carbon::create(2025, $month, 1));
            $this->assertSame(1, $result['posted'], "Bulan {$month} diposting.");
        }

        $entries = $this->journals($asset);
        $this->assertCount(12, $entries);
        $total = $entries->sum(fn ($entry) => (float) $entry->lines()->where('debit', '>', 0)->value('debit'));
        $this->assertEqualsWithDelta(1000000.0, $total, 0.001, 'Total akumulasi tepat sama dengan harga beli - residu (selisih pembulatan masuk bulan terakhir).');
        $this->assertEquals(83333.33, (float) $entries->first()->lines()->where('debit', '>', 0)->value('debit'));
        $this->assertEquals(83333.37, (float) $entries->last()->lines()->where('debit', '>', 0)->value('debit'));

        $extra = $service->postForMonth(Carbon::create(2026, 1, 1));
        $this->assertSame(0, $extra['posted'], 'Umur ekonomis sudah lewat: tidak ada jurnal sisa pembulatan.');
        $this->assertCount(12, $this->journals($asset));
    }

    public function test_a_month_that_divides_evenly_ends_on_the_same_total(): void
    {
        $asset = $this->asset('Mesin Genap', ['purchase_cost' => 1200000, 'useful_life_years' => 1, 'purchase_date' => '2025-01-01']);
        $service = app(DepreciationPostingService::class);

        foreach (range(1, 12) as $month) {
            $service->postForMonth(Carbon::create(2025, $month, 1));
        }
        $service->postForMonth(Carbon::create(2026, 1, 1));

        $this->assertCount(12, $this->journals($asset));
        $this->assertEquals(1200000.0, $this->journals($asset)->sum(fn ($e) => (float) $e->lines()->where('debit', '>', 0)->value('debit')));
    }

    // ------------------------------------------------------------- command bulanan

    public function test_the_command_defaults_to_last_month(): void
    {
        $asset = $this->asset('Mesin Cutting');

        $this->artisan('assets:post-depreciation')->assertSuccessful();

        $this->assertSame('2026-09-30', $this->journals($asset)->sole()->entry_date->toDateString());
    }

    public function test_the_command_accepts_an_explicit_month_and_rejects_a_bad_one(): void
    {
        $asset = $this->asset('Mesin Cutting');

        $this->artisan('assets:post-depreciation --month=2026-03')->assertSuccessful();
        $this->assertSame('2026-03-31', $this->journals($asset)->sole()->entry_date->toDateString());

        $this->artisan('assets:post-depreciation --month=bukan-bulan')->assertFailed();
        $this->assertCount(1, $this->journals($asset), 'Opsi yang salah tidak memposting apa pun.');
    }

    public function test_full_access_is_notified_when_an_asset_was_skipped(): void
    {
        $active = tap(User::create(['name' => 'Admin Aktif', 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]), fn (User $u) => $u->assignRole('super_admin'));
        $inactive = tap(User::create(['name' => 'Admin Nonaktif', 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => false]), fn (User $u) => $u->assignRole('super_admin'));
        $this->asset('Belum Terhubung', ['chart_of_account_id' => null, 'accumulated_depreciation_account_id' => null]);

        $this->artisan('assets:post-depreciation')->assertSuccessful();

        $notes = $active->fresh()->notifications;
        $this->assertCount(1, $notes);
        $this->assertSame('Penyusutan Aset Tetap: ada yang dilewati', $notes[0]->data['title']);
        $this->assertCount(0, $inactive->fresh()->notifications);
    }

    public function test_no_notification_when_everything_posted_cleanly(): void
    {
        $admin = tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]), fn (User $u) => $u->assignRole('super_admin'));
        $this->asset('Mesin Cutting');

        $this->artisan('assets:post-depreciation')->assertSuccessful();

        $this->assertCount(0, $admin->fresh()->notifications);
    }
}
