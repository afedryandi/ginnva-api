<?php

namespace Tests\Feature;

use App\Filament\Widgets\BookingRevenueByCategoryChart;
use App\Models\Booking;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Store;
use App\Models\User;
use App\Services\BookingPostingService;
use App\Services\BookingRevenueSplitter;
use App\Services\JournalEntryService;
use App\Services\RefundService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use ReflectionMethod;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pembagian pendapatan booking ke akun (keputusan 2026-10-08: Detailing 4500 dan Premium Wash 4600 punya akun
 * sendiri): aturan bagi rata untuk satu sampai empat jenis, pembulatan yang selalu menjumlah persis ke nominal,
 * "tanpa jenis" ke 4400, jurnal pendapatan yang benar, refund yang dibalik proporsional dengan jurnal ASLI
 * (termasuk booking lama yang dijurnal sebelum akun baru ada), akun sistem yang tidak boleh dihapus/dinonaktifkan,
 * migrasi yang aman diulang, dan grafik kategori di dashboard.
 */
class BookingRevenueSplitterTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function flags(string ...$types): array
    {
        $all = ['product_ppf' => false, 'product_kaca_film' => false, 'product_detailing' => false, 'product_premium_wash' => false];

        foreach ($types as $type) {
            $all['product_' . $type] = true;
        }

        return $all;
    }

    private function booking(array $flags, float $amount = 1000000, array $overrides = []): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);

        return Booking::create(array_merge($flags, [
            'booking_number' => 'BKG-TEST-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $this->store->id,
            'service_type' => 'PPF', 'preferred_date' => '2026-10-03', 'status' => 'completed',
            'transaction_amount' => $amount, 'amount_received' => $amount,
        ], $overrides));
    }

    private function posted(array $flags, float $amount = 1000000): Booking
    {
        $booking = $this->booking($flags, $amount);
        app(BookingPostingService::class)->sync($booking);

        return $booking->fresh();
    }

    /** @return array<string, array{debit: float, credit: float}> kode akun => total */
    private function lines(JournalEntry $entry): array
    {
        return $entry->lines()->with('account')->get()->groupBy(fn ($l) => $l->account->code)
            ->map(fn ($g) => ['debit' => (float) $g->sum('debit'), 'credit' => (float) $g->sum('credit')])->all();
    }

    private function refundEntry(Booking $booking): JournalEntry
    {
        return JournalEntry::where('reference_type', 'refund')->where('reference_id', $booking->id)->latest('id')->firstOrFail();
    }

    // ------------------------------------------------------------- aturan pembagian

    public function test_a_single_type_goes_to_its_own_account_and_no_type_goes_to_other_income(): void
    {
        $this->assertSame(['4100' => 1000.0], BookingRevenueSplitter::splits(new Booking($this->flags('ppf')), 1000));
        $this->assertSame(['4200' => 1000.0], BookingRevenueSplitter::splits(new Booking($this->flags('kaca_film')), 1000));
        $this->assertSame(['4500' => 1000.0], BookingRevenueSplitter::splits(new Booking($this->flags('detailing')), 1000));
        $this->assertSame(['4600' => 1000.0], BookingRevenueSplitter::splits(new Booking($this->flags('premium_wash')), 1000));
        $this->assertSame(['4400' => 1000.0], BookingRevenueSplitter::splits(new Booking($this->flags()), 1000));
    }

    public function test_several_types_are_split_equally_with_the_rounding_on_the_last_one(): void
    {
        $twoOdd = BookingRevenueSplitter::splits(new Booking($this->flags('ppf', 'kaca_film')), 100.01);
        $this->assertSame(['4100' => 50.01, '4200' => 50.0], $twoOdd, 'Sama dengan aturan lama PPF + Kaca Film.');

        $three = BookingRevenueSplitter::splits(new Booking($this->flags('ppf', 'detailing', 'premium_wash')), 100);
        $this->assertSame(['4100' => 33.33, '4500' => 33.33, '4600' => 33.34], $three);

        $four = BookingRevenueSplitter::splits(new Booking($this->flags('ppf', 'kaca_film', 'detailing', 'premium_wash')), 1000);
        $this->assertSame(['4100' => 250.0, '4200' => 250.0, '4500' => 250.0, '4600' => 250.0], $four);
    }

    public function test_the_parts_always_add_up_to_the_amount_for_every_combination(): void
    {
        $combos = [['ppf'], ['ppf', 'kaca_film'], ['ppf', 'detailing'], ['kaca_film', 'premium_wash'], ['ppf', 'kaca_film', 'detailing'], ['ppf', 'kaca_film', 'detailing', 'premium_wash'], []];

        foreach ($combos as $combo) {
            foreach ([0.01, 0.07, 1, 99.99, 100.01, 333.33, 1000000.01, 600001] as $amount) {
                $sum = array_sum(BookingRevenueSplitter::splits(new Booking($this->flags(...$combo)), $amount));

                $this->assertEqualsWithDelta($amount, $sum, 0.0001, implode('+', $combo) . " @ {$amount}");
            }
        }
    }

    public function test_shares_expose_the_same_split_by_service_type_for_the_reports(): void
    {
        $shares = BookingRevenueSplitter::shares(new Booking($this->flags('ppf', 'detailing')), 100000);

        $this->assertSame(['ppf' => 50000.0, 'detailing' => 50000.0], $shares);
        $this->assertSame([BookingRevenueSplitter::NO_PRODUCT => 70000.0], BookingRevenueSplitter::shares(new Booking($this->flags()), 70000));
        $this->assertSame(['ppf', 'premium_wash'], BookingRevenueSplitter::productKeys(new Booking($this->flags('premium_wash', 'ppf'))));
    }

    // ------------------------------------------------------------- jurnal pendapatan

    public function test_posting_credits_the_right_revenue_accounts(): void
    {
        $detailing = $this->posted($this->flags('detailing'));
        $premium = $this->posted($this->flags('premium_wash'));
        $mixed = $this->posted($this->flags('ppf', 'detailing'));
        $none = $this->posted($this->flags());

        $this->assertEquals(1000000.0, $this->lines($detailing->journalEntry)['4500']['credit']);
        $this->assertEquals(1000000.0, $this->lines($premium->journalEntry)['4600']['credit']);
        $mixedLines = $this->lines($mixed->journalEntry);
        $this->assertEquals([500000.0, 500000.0], [$mixedLines['4100']['credit'], $mixedLines['4500']['credit']]);
        $this->assertEquals(1000000.0, $this->lines($none->journalEntry)['4400']['credit']);
        $this->assertTrue($detailing->journalEntry->isBalanced());
    }

    public function test_the_new_revenue_accounts_exist_and_are_protected_system_accounts(): void
    {
        foreach (['4500' => 'Pendapatan Jasa Detailing', '4600' => 'Pendapatan Jasa Premium Wash'] as $code => $name) {
            $account = ChartOfAccount::where('code', $code)->firstOrFail();

            $this->assertSame($name, $account->name);
            $this->assertSame('pendapatan', $account->type);
            $this->assertSame('kredit', $account->normal_balance);
            $this->assertTrue($account->is_postable && $account->is_active);
            $this->assertTrue($account->isSystem(), 'Dipakai posting otomatis, jadi tidak boleh dihapus/dinonaktifkan.');
            $this->assertNotNull($account->deletionBlocker());
        }
    }

    public function test_system_protection_blocks_deactivating_or_deleting_them_for_a_logged_in_user(): void
    {
        $admin = tap(User::create(['name' => 'Admin', 'email' => 'a@test.local', 'password' => 'x']), fn (User $u) => $u->assignRole('super_admin'));
        $this->actingAs($admin, 'web');
        $account = ChartOfAccount::where('code', '4500')->firstOrFail();

        try {
            $account->update(['is_active' => false]);
            $this->fail('Akun sistem tidak boleh dinonaktifkan.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('dipakai posting otomatis', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        ChartOfAccount::where('code', '4600')->firstOrFail()->delete();
    }

    public function test_the_migration_adds_missing_accounts_once_and_never_overwrites_existing_ones(): void
    {
        $migration = require base_path('database/migrations/2026_10_08_000002_add_detailing_and_premium_wash_revenue_accounts.php');
        DB::table('chart_of_accounts')->whereIn('code', ['4500', '4600'])->delete();

        $migration->up();
        $migration->up();

        $this->assertSame(1, ChartOfAccount::where('code', '4500')->count());
        $this->assertSame(1, ChartOfAccount::where('code', '4600')->count());
        $this->assertSame('pendapatan', ChartOfAccount::where('code', '4500')->value('type'));

        ChartOfAccount::where('code', '4500')->update(['name' => 'Nama Diubah Admin']);
        $migration->up();
        $this->assertSame('Nama Diubah Admin', ChartOfAccount::where('code', '4500')->value('name'));
    }

    // ------------------------------------------------------------- refund

    public function test_a_refund_reverses_the_revenue_accounts_in_proportion_to_the_posted_journal(): void
    {
        $booking = $this->posted($this->flags('ppf', 'detailing'));

        app(RefundService::class)->process($booking, 100000, 'Batal sebagian', null);

        $lines = $this->lines($this->refundEntry($booking));
        $this->assertEquals([50000.0, 50000.0], [$lines['4100']['debit'], $lines['4500']['debit']]);
        $this->assertEquals(100000.0, $lines['1101']['credit']);
    }

    public function test_a_refund_on_a_legacy_booking_goes_back_to_the_account_that_was_actually_credited(): void
    {
        // Booking lama: PPF + Detailing dulu dijurnal seluruhnya ke PPF (4100), sebelum akun 4500 ada.
        $booking = $this->booking($this->flags('ppf', 'detailing'));
        $entry = app(JournalEntryService::class)->create(['entry_date' => '2026-10-03', 'store_id' => $this->store->id, 'description' => 'Jurnal lama', 'reference_type' => 'booking', 'reference_id' => $booking->id], [
            ['chart_of_account_id' => ChartOfAccount::where('code', '1101')->value('id'), 'debit' => 1000000],
            ['chart_of_account_id' => ChartOfAccount::where('code', '4100')->value('id'), 'credit' => 1000000],
        ]);
        app(JournalEntryService::class)->post($entry, null);
        $booking->update(['journal_entry_id' => $entry->id]);

        app(RefundService::class)->process($booking->fresh(), 100000, null, null);

        $lines = $this->lines($this->refundEntry($booking));
        $this->assertEquals(100000.0, $lines['4100']['debit']);
        $this->assertArrayNotHasKey('4500', $lines, 'Tidak mendebit akun yang tidak pernah menerima pendapatan booking ini.');
        $this->assertEquals(900000.0, ChartOfAccount::where('code', '4100')->firstOrFail()->balance());
        $this->assertEquals(0.0, ChartOfAccount::where('code', '4500')->firstOrFail()->balance());
    }

    public function test_refund_parts_add_up_exactly_for_an_awkward_amount(): void
    {
        $booking = $this->posted($this->flags('ppf', 'detailing', 'premium_wash'), 999999.99);

        app(RefundService::class)->process($booking, 10.00, null, null);

        $lines = $this->lines($this->refundEntry($booking));
        $this->assertEqualsWithDelta(10.00, array_sum(array_column($lines, 'debit')), 0.0001);
        $this->assertEqualsWithDelta(10.00, array_sum(array_column($lines, 'credit')), 0.0001);
    }

    public function test_refund_splits_fall_back_to_the_current_rule_without_a_posted_journal(): void
    {
        $booking = $this->booking($this->flags('ppf', 'detailing'));

        $this->assertSame(BookingRevenueSplitter::splits($booking, 1000), BookingRevenueSplitter::refundSplits($booking, 1000));
    }

    // ------------------------------------------------------------- grafik kategori

    public function test_the_category_chart_shows_all_four_types_and_other_only_when_present(): void
    {
        $this->posted($this->flags('ppf', 'detailing'), 100000);
        $this->posted($this->flags('premium_wash'), 40000);
        $admin = tap(User::create(['name' => 'Admin', 'email' => 'b@test.local', 'password' => 'x']), fn (User $u) => $u->assignRole('super_admin'));
        $this->actingAs($admin, 'web');

        $method = new ReflectionMethod(BookingRevenueByCategoryChart::class, 'getData');
        $method->setAccessible(true);
        $data = $method->invoke(Livewire::test(BookingRevenueByCategoryChart::class)->instance());

        $this->assertSame(['Kaca Film', 'PPF', 'Detailing', 'Premium Wash'], $data['labels']);
        $this->assertEquals([0, 50000, 50000, 40000], $data['datasets'][0]['data']);

        $this->posted($this->flags(), 25000);
        $withOther = $method->invoke(Livewire::test(BookingRevenueByCategoryChart::class)->instance());
        $this->assertSame(['Kaca Film', 'PPF', 'Detailing', 'Premium Wash', 'Lainnya'], $withOther['labels']);
        $this->assertEquals(25000, $withOther['datasets'][0]['data'][4]);
    }
}
