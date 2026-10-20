<?php

namespace Tests\Feature;

use App\Filament\Pages\CashBalanceReport;
use App\Filament\Resources\CashTransferResource\Pages\ListCashTransfers;
use App\Filament\Resources\PaymentAccountResource;
use App\Filament\Resources\PaymentAccountResource\Pages\ListPaymentAccounts;
use App\Models\AccountingPeriod;
use App\Models\Booking;
use App\Models\BookingDownPayment;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\PaymentAccount;
use App\Models\Store;
use App\Models\User;
use App\Services\BookingPostingService;
use App\Services\CashTransferService;
use App\Services\DownPaymentService;
use App\Services\FinanceTransactionPostingService;
use App\Services\FinancialStatementService;
use App\Services\PaymentAccountResolver;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Keuangan Fase 1 (2026-11-05): rekening kas/bank, pemilihan akun kas menurut metode bayar dan toko, DP/transaksi/booking yang masuk ke
 * rekening yang tepat, pemindahan kas antar akun, dan halaman saldo. Tanpa rekening terdaftar semuanya tetap ke akun 1101.
 * Waktu dibekukan: Rabu 7 Okt 2026.
 */
class PaymentAccountsTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['super_admin', 'direksi'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->seed(ChartOfAccountSeeder::class);
        Carbon::setTestNow('2026-10-07 09:10:00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->storeB = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function acct(string $code): ChartOfAccount
    {
        return ChartOfAccount::where('code', $code)->firstOrFail();
    }

    private function cashCoa(string $code, string $name): ChartOfAccount
    {
        return ChartOfAccount::create([
            'code' => $code, 'name' => $name, 'type' => 'aset', 'normal_balance' => 'debit', 'parent_id' => $this->acct('1100')->id,
            'is_postable' => true, 'is_active' => true, 'is_cash' => true, 'is_contra' => false,
        ]);
    }

    private function paymentAccount(array $over = []): PaymentAccount
    {
        return PaymentAccount::create(array_merge(['name' => 'Rekening', 'kind' => 'bank', 'is_active' => true], $over));
    }

    private function balance(string $code): float
    {
        return app(FinancialStatementService::class)->balanceAsOf($this->acct($code), now());
    }

    private function setup2Accounts(): array
    {
        $kasA = $this->paymentAccount(['name' => 'Kas Toko A', 'kind' => 'kas', 'chart_of_account_id' => $this->cashCoa('1103', 'Kas Toko A')->id, 'store_id' => $this->storeA->id, 'methods' => ['tunai'], 'is_default' => true]);
        $bank = $this->paymentAccount(['name' => 'BCA Operasional', 'chart_of_account_id' => $this->cashCoa('1104', 'BCA Operasional')->id, 'store_id' => null, 'methods' => ['transfer', 'edc']]);

        return [$kasA, $bank];
    }

    // ------------------------------------------------------------- resolver

    public function test_without_payment_accounts_everything_falls_back_to_cash_1101(): void
    {
        $resolver = app(PaymentAccountResolver::class);

        $this->assertSame('1101', $resolver->resolve($this->storeA->id, 'transfer')->code);
        $this->assertSame('1101', $resolver->resolve(null, null)->code);
    }

    public function test_method_store_default_and_fallback_rules(): void
    {
        $this->setup2Accounts();
        $resolver = app(PaymentAccountResolver::class);

        $this->assertSame('1104', $resolver->resolve($this->storeA->id, 'transfer')->code, 'Transfer masuk ke bank pusat.');
        $this->assertSame('1103', $resolver->resolve($this->storeA->id, 'tunai')->code, 'Tunai ke kas toko.');
        $this->assertSame('1103', $resolver->resolve($this->storeA->id, null)->code, 'Tanpa metode: bawaan toko.');
        $this->assertSame('1103', $resolver->resolve($this->storeA->id, 'qris')->code, 'Metode tak dikenal: bawaan toko.');
        $this->assertSame('1104', $resolver->resolve($this->storeB->id, 'edc')->code, 'Toko B memakai rekening pusat.');
        $this->assertSame('1101', $resolver->resolve($this->storeB->id, 'tunai')->code, 'Toko B tanpa bawaan: kembali ke 1101.');
    }

    public function test_store_account_takes_priority_over_the_company_account_for_the_same_method(): void
    {
        [$kasA, $bank] = $this->setup2Accounts();
        $this->paymentAccount(['name' => 'BCA Toko A', 'chart_of_account_id' => $this->cashCoa('1105', 'BCA Toko A')->id, 'store_id' => $this->storeA->id, 'methods' => ['transfer']]);

        $this->assertSame('1105', app(PaymentAccountResolver::class)->resolve($this->storeA->id, 'transfer')->code);
        $this->assertSame('1104', app(PaymentAccountResolver::class)->resolve($this->storeB->id, 'transfer')->code);
    }

    public function test_inactive_accounts_and_accounts_with_unusable_coa_are_ignored(): void
    {
        [$kasA] = $this->setup2Accounts();
        $kasA->update(['is_active' => false]);

        $this->assertSame('1101', app(PaymentAccountResolver::class)->resolve($this->storeA->id, 'tunai')->code);

        $kasA->update(['is_active' => true]);
        $kasA->chartAccount->update(['is_active' => false]);

        $this->assertSame('1101', app(PaymentAccountResolver::class)->resolve($this->storeA->id, 'tunai')->code);
    }

    public function test_only_one_default_per_store_scope(): void
    {
        $first = $this->paymentAccount(['name' => 'Kas 1', 'chart_of_account_id' => $this->cashCoa('1103', 'Kas 1')->id, 'store_id' => $this->storeA->id, 'is_default' => true]);
        $other = $this->paymentAccount(['name' => 'Kas Toko B', 'chart_of_account_id' => $this->cashCoa('1104', 'Kas B')->id, 'store_id' => $this->storeB->id, 'is_default' => true]);
        $second = $this->paymentAccount(['name' => 'Kas 2', 'chart_of_account_id' => $this->cashCoa('1105', 'Kas 2')->id, 'store_id' => $this->storeA->id, 'is_default' => true]);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
        $this->assertTrue($other->fresh()->is_default, 'Toko lain tidak terpengaruh.');
    }

    public function test_account_numbers_are_stored_encrypted(): void
    {
        $account = $this->paymentAccount(['chart_of_account_id' => $this->cashCoa('1103', 'BCA')->id, 'account_number' => '1234567890']);

        $this->assertSame('1234567890', $account->fresh()->account_number);
        $this->assertNotSame('1234567890', \Illuminate\Support\Facades\DB::table('payment_accounts')->where('id', $account->id)->value('account_number'));
    }

    // ------------------------------------------------------------- booking, DP, transaksi

    private function booking(array $over = []): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);

        return Booking::create(array_merge([
            'booking_number' => 'BKG-T-' . uniqid(), 'customer_id' => $customer->id, 'store_id' => $this->storeA->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => now()->toDateString(), 'status' => 'completed',
        ], $over));
    }

    public function test_booking_cash_goes_to_the_account_for_its_payment_method(): void
    {
        $this->setup2Accounts();

        app(BookingPostingService::class)->sync($this->booking(['transaction_amount' => 1000000, 'amount_received' => 1000000, 'payment_method' => 'transfer']));
        app(BookingPostingService::class)->sync($this->booking(['transaction_amount' => 500000, 'amount_received' => 500000, 'payment_method' => 'tunai']));

        $this->assertEquals(1000000, $this->balance('1104'), 'Transfer ke BCA.');
        $this->assertEquals(500000, $this->balance('1103'), 'Tunai ke kas Toko A.');
        $this->assertEquals(0, $this->balance('1101'), 'Akun lama tidak tersentuh.');
    }

    public function test_down_payment_is_received_into_and_refunded_from_the_same_account(): void
    {
        [$kasA] = $this->setup2Accounts();
        $booking = $this->booking(['status' => 'confirmed']);
        $service = app(DownPaymentService::class);

        $dp = $service->receive($booking, 300000, null, null);

        $this->assertSame($kasA->id, $dp->payment_account_id, 'Memakai rekening bawaan toko.');
        $this->assertEquals(300000, $this->balance('1103'));

        $service->refund($dp, null, 'Batal');
        $this->assertEquals(0, $this->balance('1103'), 'Dikembalikan dari rekening yang sama.');
    }

    public function test_an_old_down_payment_without_account_is_refunded_from_1101_even_after_accounts_exist(): void
    {
        $booking = $this->booking(['status' => 'confirmed']);
        $service = app(DownPaymentService::class);

        $dp = $service->receive($booking, 300000, null, null); // belum ada rekening: masuk 1101
        $this->assertNull($dp->payment_account_id);
        $this->assertEquals(300000, $this->balance('1101'));

        $this->setup2Accounts();
        $service->refund($dp->fresh(), null, 'Batal');

        $this->assertEquals(0, $this->balance('1101'), 'Uang lama keluar dari 1101, bukan dari rekening baru.');
        $this->assertEquals(0, $this->balance('1103'));
    }

    public function test_a_down_payment_can_target_a_chosen_account_but_not_an_unusable_one(): void
    {
        [, $bank] = $this->setup2Accounts();
        $booking = $this->booking(['status' => 'confirmed']);
        $service = app(DownPaymentService::class);

        $dp = $service->receive($booking, 200000, null, null, $bank->id);
        $this->assertSame($bank->id, $dp->payment_account_id);
        $this->assertEquals(200000, $this->balance('1104'));

        $bank->update(['is_active' => false]);
        $this->expectException(RuntimeException::class);
        $service->receive($booking, 100000, null, null, $bank->id);
    }

    public function test_finance_transactions_use_the_chosen_or_default_account_and_legacy_otherwise(): void
    {
        [$kasA, $bank] = $this->setup2Accounts();
        $category = FinanceCategory::create(['name' => 'Bensin', 'type' => 'out', 'chart_of_account_id' => $this->acct('6440')->id, 'is_active' => true]);
        $post = fn (array $over) => app(FinanceTransactionPostingService::class)->post(
            FinanceTransaction::create(array_merge(['type' => 'out', 'finance_category_id' => $category->id, 'store_id' => $this->storeA->id, 'amount' => 50000, 'transaction_date' => '2026-10-05', 'description' => 'x'], $over))->load('category'),
            null
        );

        $post([]);                                         // bawaan toko A -> kas A
        $post(['payment_account_id' => $bank->id]);        // dipilih -> bank
        $post(['store_id' => $this->storeB->id]);          // toko B tanpa bawaan -> 1101

        $this->assertEquals(-50000, $this->balance('1103'));
        $this->assertEquals(-50000, $this->balance('1104'));
        $this->assertEquals(-50000, $this->balance('1101'));
    }

    // ------------------------------------------------------------- pemindahan kas

    public function test_transfer_moves_money_between_cash_accounts_and_can_be_voided(): void
    {
        [$kasA, $bank] = $this->setup2Accounts();
        $service = app(CashTransferService::class);

        // Saldo awal kas toko A lewat DP.
        app(DownPaymentService::class)->receive($this->booking(['status' => 'confirmed']), 1000000, null, null);

        $transfer = $service->create($kasA->chart_of_account_id, $bank->chart_of_account_id, 600000, now(), $this->storeA->id, 'Setor tunai', null);

        $this->assertEquals(400000, $this->balance('1103'));
        $this->assertEquals(600000, $this->balance('1104'));
        $this->assertStringStartsWith('TRF-202610-', $transfer->transfer_number);

        $service->void($transfer, null, 'Salah rekening');

        $this->assertEquals(1000000, $this->balance('1103'));
        $this->assertEquals(0, $this->balance('1104'));
        $this->assertTrue($transfer->fresh()->isVoided());

        $this->expectException(RuntimeException::class);
        $service->void($transfer->fresh(), null, 'Lagi');
    }

    public function test_transfer_validation(): void
    {
        [$kasA, $bank] = $this->setup2Accounts();
        $service = app(CashTransferService::class);
        $make = fn (int $from, int $to, float $amount, ?Carbon $date = null) => $service->create($from, $to, $amount, $date ?? now(), null, null, null);

        foreach ([
            fn () => $make($kasA->chart_of_account_id, $kasA->chart_of_account_id, 1000),                      // sama
            fn () => $make($kasA->chart_of_account_id, $bank->chart_of_account_id, 0),                         // nol
            fn () => $make($kasA->chart_of_account_id, $this->acct('1110')->id, 1000),                         // bukan kas
            fn () => $make($kasA->chart_of_account_id, $bank->chart_of_account_id, 1000, now()->addDay()),    // masa depan
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Seharusnya ditolak.');
            } catch (RuntimeException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }

        $this->assertSame(0, \App\Models\CashTransfer::count());
    }

    public function test_a_transfer_in_a_closed_period_is_rejected(): void
    {
        [$kasA, $bank] = $this->setup2Accounts();
        $closer = User::create(['name' => 'Penutup', 'email' => 'c@test.local', 'password' => 'x']);
        AccountingPeriod::create(['period_month' => '2026-09-01', 'closed_by' => $closer->id, 'closed_at' => now()]);

        $this->expectException(RuntimeException::class);
        app(CashTransferService::class)->create($kasA->chart_of_account_id, $bank->chart_of_account_id, 1000, Carbon::parse('2026-09-15'), null, null, null);
    }

    // ------------------------------------------------------------- halaman

    public function test_pages_render_for_admin_and_accounts_cannot_be_deleted(): void
    {
        [$kasA] = $this->setup2Accounts();
        $admin = User::create(['name' => 'Admin', 'email' => 'a@test.local', 'password' => 'x']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');

        Livewire::test(ListPaymentAccounts::class)->assertSuccessful()->assertCanSeeTableRecords([$kasA]);
        Livewire::test(ListCashTransfers::class)->assertSuccessful();
        Livewire::test(CashBalanceReport::class)->assertSuccessful()->assertSee('Kas Toko A')->assertSee('Total kas dan bank');
        $this->assertFalse(PaymentAccountResource::canDelete($kasA));
        $this->assertSame('1105', PaymentAccountResource::nextCashCode());
    }
}
