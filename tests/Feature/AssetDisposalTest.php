<?php

namespace Tests\Feature;

use App\Filament\Resources\AssetResource\Pages\ListAssets;
use App\Models\AccountingPeriod;
use App\Models\Asset;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\PaymentAccount;
use App\Models\Store;
use App\Models\User;
use App\Services\AssetDisposalService;
use App\Services\DepreciationPostingService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pelepasan aset (Lepas Aset): jurnal dijual dengan laba atau rugi, hilang/dibuang, nilai buku dari penyusutan yang benar-benar
 * diposting, berhenti disusutkan, satu kali saja, periode tertutup, dan tombol di daftar aset. Aset: Rp12.000.000, umur 5 tahun
 * (Rp200.000 per bulan), sejak 1 Jan 2025; penyusutan diposting sampai September 2026 (21 bulan = Rp4.200.000, nilai buku Rp7.800.000).
 * "Hari ini" dibekukan di 8 Oktober 2026.
 */
class AssetDisposalTest extends TestCase
{
    use RefreshDatabase;

    private ChartOfAccount $assetAccount;
    private ChartOfAccount $accumulated;
    private PaymentAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountSeeder::class);
        foreach (['super_admin', 'kasir'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
        Asset::query()->delete();
        $this->assetAccount = ChartOfAccount::where('code', '1210')->firstOrFail();
        $this->accumulated = ChartOfAccount::where('code', '1211')->firstOrFail();
        $this->bank = PaymentAccount::create(['name' => 'BCA Utama', 'kind' => 'bank', 'chart_of_account_id' => ChartOfAccount::where('code', '1102')->value('id'), 'store_id' => null, 'methods' => ['transfer'], 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        $user = User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    /** Aset dengan penyusutan diposting Jan 2025 sampai Sep 2026 (21 bulan). */
    private function asset(array $extra = [], bool $depreciate = true): Asset
    {
        $asset = Asset::create(array_merge([
            'asset_tag' => Asset::generateAssetTag(), 'name' => 'Mesin Cutting', 'category' => 'Mesin', 'status' => 'aktif', 'received_date' => '2025-01-01',
            'purchase_cost' => 12000000, 'purchase_date' => '2025-01-01', 'useful_life_years' => 5, 'salvage_value' => 0,
            'chart_of_account_id' => $this->assetAccount->id, 'accumulated_depreciation_account_id' => $this->accumulated->id,
        ], $extra));

        if ($depreciate) {
            $service = app(DepreciationPostingService::class);
            for ($month = Carbon::parse('2025-01-15'); $month->lte(Carbon::parse('2026-09-15')); $month->addMonthNoOverflow()) {
                $service->postForMonth($month->copy());
            }
        }

        return $asset->fresh();
    }

    /** Jumlah debit (positif) atau kredit (negatif) per kode akun pada jurnal pelepasan. */
    private function disposalLines(Asset $asset): array
    {
        $entry = JournalEntry::where('reference_type', 'asset_disposal')->where('reference_id', $asset->id)->with('lines.account')->sole();
        $this->assertTrue($entry->isPosted());
        $this->assertTrue($entry->isBalanced());

        $byCode = [];
        foreach ($entry->lines as $line) {
            $byCode[$line->account->code] = ($byCode[$line->account->code] ?? 0) + (float) $line->debit - (float) $line->credit;
        }

        return $byCode;
    }

    private function dispose(Asset $asset, array $data)
    {
        return app(AssetDisposalService::class)->dispose($asset, $data + ['date' => '2026-10-08'], null);
    }

    // ------------------------------------------------------------- dijual

    public function test_selling_above_book_value_records_a_gain(): void
    {
        $asset = $this->asset();

        $result = $this->dispose($asset, ['type' => 'dijual', 'proceeds' => 9000000, 'payment_account_id' => $this->bank->id]);

        $this->assertTrue($result['journaled']);
        $this->assertSame(1200000.0, $result['gain_loss']);
        $lines = $this->disposalLines($asset);
        $this->assertSame(9000000.0, $lines['1102']);
        $this->assertSame(4200000.0, $lines['1211'], 'Akumulasi penyusutan yang sudah diposting dihapus.');
        $this->assertSame(-12000000.0, $lines['1210'], 'Harga perolehan dikreditkan.');
        $this->assertSame(-1200000.0, $lines['7200'], 'Laba masuk Pendapatan Non-Operasional.');
        $this->assertArrayNotHasKey('7900', $lines);

        $asset->refresh();
        $this->assertSame('dijual', $asset->status);
        $this->assertSame('2026-10-08', $asset->disposed_at->toDateString());
        $this->assertNotNull($asset->disposal_journal_entry_id);
    }

    public function test_selling_below_book_value_records_a_loss(): void
    {
        $asset = $this->asset();

        $result = $this->dispose($asset, ['type' => 'dijual', 'proceeds' => 5000000, 'payment_account_id' => $this->bank->id]);

        $this->assertSame(-2800000.0, $result['gain_loss']);
        $lines = $this->disposalLines($asset);
        $this->assertSame(2800000.0, $lines['7900'], 'Rugi masuk Beban Non-Operasional.');
        $this->assertArrayNotHasKey('7200', $lines);
    }

    public function test_selling_at_book_value_has_neither_gain_nor_loss(): void
    {
        $asset = $this->asset();

        $result = $this->dispose($asset, ['type' => 'dijual', 'proceeds' => 7800000, 'payment_account_id' => $this->bank->id]);

        $this->assertSame(0.0, $result['gain_loss']);
        $lines = $this->disposalLines($asset);
        $this->assertArrayNotHasKey('7200', $lines);
        $this->assertArrayNotHasKey('7900', $lines);
    }

    public function test_a_lost_asset_writes_off_the_whole_book_value_and_ignores_any_proceeds(): void
    {
        $asset = $this->asset();

        $result = $this->dispose($asset, ['type' => 'hilang', 'proceeds' => 5000000]);

        $this->assertSame(-7800000.0, $result['gain_loss']);
        $lines = $this->disposalLines($asset);
        $this->assertSame(7800000.0, $lines['7900']);
        $this->assertArrayNotHasKey('1102', $lines, 'Tidak ada uang masuk.');
        $asset->refresh();
        $this->assertSame('hilang', $asset->status);
        $this->assertSame('0.00', (string) $asset->disposal_proceeds);
    }

    // ------------------------------------------------------------- aturan

    public function test_a_disposed_asset_is_no_longer_depreciated(): void
    {
        $asset = $this->asset();
        $this->dispose($asset, ['type' => 'hilang']);

        $result = app(DepreciationPostingService::class)->postForMonth(Carbon::parse('2026-10-15'));

        $this->assertSame(0, $result['posted']);
        $this->assertSame(0, JournalEntry::where('reference_type', 'asset_depreciation')->where('reference_id', $asset->id)->whereDate('entry_date', '2026-10-31')->count());
    }

    public function test_an_asset_can_only_be_disposed_once(): void
    {
        $asset = $this->asset();
        $this->dispose($asset, ['type' => 'hilang']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sudah dilepas');
        $this->dispose($asset, ['type' => 'hilang']);
    }

    public function test_selling_with_proceeds_requires_a_receiving_account_and_saves_nothing_otherwise(): void
    {
        $asset = $this->asset();

        try {
            $this->dispose($asset, ['type' => 'dijual', 'proceeds' => 1000000]);
            $this->fail('Harus gagal tanpa rekening penerima.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('rekening', $e->getMessage());
        }

        $this->assertNull($asset->fresh()->disposed_at);
        $this->assertSame(0, JournalEntry::where('reference_type', 'asset_disposal')->count());
    }

    public function test_a_closed_period_blocks_the_disposal_and_leaves_the_asset_untouched(): void
    {
        $asset = $this->asset();
        AccountingPeriod::create(['period_month' => '2026-10-01', 'closed_by' => $this->admin()->id, 'closed_at' => now()]);

        try {
            $this->dispose($asset, ['type' => 'hilang']);
            $this->fail('Harus gagal di periode tertutup.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ditutup', $e->getMessage());
        }

        $asset->refresh();
        $this->assertNull($asset->disposed_at);
        $this->assertSame('aktif', $asset->status);
    }

    public function test_the_date_cannot_be_in_the_future_or_before_the_purchase(): void
    {
        $asset = $this->asset();

        foreach ([['date' => '2026-10-20', 'msg' => 'masa depan'], ['date' => '2024-12-31', 'msg' => 'sebelum tanggal beli']] as $case) {
            try {
                $this->dispose($asset, ['type' => 'hilang', 'date' => $case['date']]);
                $this->fail('Harus ditolak: ' . $case['msg']);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($case['msg'], $e->getMessage());
            }
        }

        $this->assertNull($asset->fresh()->disposed_at);
    }

    public function test_an_asset_without_cost_or_accounts_is_only_marked_without_a_journal(): void
    {
        $asset = $this->asset(['purchase_cost' => null, 'chart_of_account_id' => null, 'accumulated_depreciation_account_id' => null], depreciate: false);

        $result = $this->dispose($asset, ['type' => 'hilang']);

        $this->assertFalse($result['journaled']);
        $this->assertSame(0, JournalEntry::where('reference_type', 'asset_disposal')->count());
        $this->assertSame('hilang', $asset->fresh()->status);
        $this->assertNotNull($asset->fresh()->disposed_at);
    }

    // ------------------------------------------------------------- pratinjau

    public function test_the_preview_shows_the_figures_without_writing_anything(): void
    {
        $asset = $this->asset();

        $preview = app(AssetDisposalService::class)->preview($asset, Carbon::parse('2026-10-08'), 9000000);

        $this->assertTrue($preview['journalable']);
        $this->assertSame(12000000.0, $preview['cost']);
        $this->assertSame(4200000.0, $preview['accumulated']);
        $this->assertSame(7800000.0, $preview['book_value']);
        $this->assertSame(1200000.0, $preview['gain_loss']);
        $this->assertSame([], $preview['warnings']);
        $this->assertNull($asset->fresh()->disposed_at);
    }

    public function test_the_preview_warns_when_earlier_depreciation_was_not_posted(): void
    {
        $asset = $this->asset(depreciate: false);

        $preview = app(AssetDisposalService::class)->preview($asset, Carbon::parse('2026-10-08'), 0);

        $this->assertNotEmpty($preview['warnings']);
        $this->assertStringContainsString('Penyusutan', $preview['warnings'][0]);
    }

    // ------------------------------------------------------------- tombol di daftar

    public function test_the_dispose_button_works_for_full_access_and_is_hidden_afterwards(): void
    {
        $this->actingAs($this->admin(), 'web');
        $asset = $this->asset();

        Livewire::test(ListAssets::class)
            ->assertTableActionVisible('dispose', $asset)
            ->callTableAction('dispose', $asset, ['type' => 'hilang', 'date' => '2026-10-08', 'notes' => 'Hilang saat pindah toko'])
            ->assertHasNoTableActionErrors()
            ->assertTableActionHidden('dispose', $asset->fresh());

        $this->assertSame('hilang', $asset->fresh()->status);
        $this->assertSame('Hilang saat pindah toko', $asset->fresh()->disposal_notes);
    }

    public function test_the_dispose_button_is_not_offered_to_staff_without_full_access(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $staff = User::create(['name' => 'Kasir', 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true, 'store_id' => $store->id]);
        $staff->assignRole('kasir');
        $this->actingAs($staff, 'web');
        $asset = $this->asset(['store_id' => $store->id]);

        Livewire::test(ListAssets::class)->assertTableActionHidden('dispose', $asset);
    }
}
