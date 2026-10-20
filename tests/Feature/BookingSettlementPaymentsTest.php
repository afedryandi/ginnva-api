<?php

namespace Tests\Feature;

use App\Filament\Pages\PaymentBookReport;
use App\Filament\Resources\BookingResource\Pages\ListBookings;
use App\Models\Booking;
use App\Models\BookingSettlementPayment;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\PaymentAccount;
use App\Models\Store;
use App\Models\User;
use App\Services\BookingPostingService;
use App\Services\BookingSettlementPaymentService;
use App\Services\DownPaymentService;
use App\Services\TransactionApprovalService;
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
 * Penjualan Fase 2 (2026-11-11): pembayaran yang diterima saat penjualan diproses. Pembayaran campur terpisah per rekening di jurnal,
 * kelebihan bayar ditolak, DP dan piutang tetap benar, dan Buku Pembayaran menyatukan ketiga sumber.
 */
class BookingSettlementPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private PaymentAccount $kas;

    private PaymentAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-11-11 10:00:00');
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);

        $this->kas = $this->paymentAccount('Kas Toko A', 'kas', '1103', ['tunai']);
        $this->bank = $this->paymentAccount('BCA Operasional', 'bank', '1104', ['transfer', 'edc']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function paymentAccount(string $name, string $kind, string $code, array $methods): PaymentAccount
    {
        $coa = ChartOfAccount::create([
            'code' => $code, 'name' => $name, 'type' => 'aset', 'normal_balance' => 'debit',
            'parent_id' => ChartOfAccount::where('code', '1100')->value('id'),
            'is_postable' => true, 'is_active' => true, 'is_cash' => true, 'is_contra' => false,
        ]);

        return PaymentAccount::create(['name' => $name, 'kind' => $kind, 'chart_of_account_id' => $coa->id, 'store_id' => null, 'methods' => $methods, 'is_active' => true]);
    }

    private function booking(float $amount = 1000000, array $overrides = []): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);

        return Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $this->store->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2026-11-03', 'status' => 'completed',
            'transaction_amount' => $amount, 'amount_received' => $amount,
        ], $overrides));
    }

    /** @return array<string, float> kode akun => debit jurnal pendapatan booking */
    private function debits(Booking $booking): array
    {
        $entry = JournalEntry::findOrFail($booking->fresh()->journal_entry_id);

        return $entry->lines()->with('account')->get()->groupBy(fn ($l) => $l->account->code)
            ->map(fn ($g) => round((float) $g->sum('debit'), 2))->filter(fn ($v) => $v > 0)->all();
    }

    private function settle(Booking $booking, array $rows, ?float $nominal = null): void
    {
        $service = app(BookingSettlementPaymentService::class);
        $nominal ??= (float) $booking->transaction_amount;
        $clean = $service->normalize($rows, (int) $booking->store_id);
        $service->assertWithinNominal($booking, $clean, $nominal);
        $service->replace($booking, $clean, null);
        $booking->update(['amount_received' => $service->amountReceived($booking, $clean, $nominal), 'payment_method' => BookingSettlementPaymentService::primaryMethod($clean)]);
    }

    /** Judul dan isi notifikasi Filament yang terkirim pada sesi tes, untuk pesan kegagalan yang menjelaskan. */
    private function notifications(): string
    {
        return collect(session('filament.notifications', []))
            ->map(fn ($n) => trim(($n['title'] ?? '') . ' ' . ($n['body'] ?? '')))
            ->implode(' | ') ?: '(tidak ada notifikasi)';
    }

    /**
     * Menjalankan aksi Proses Referral seperti di panel: baris pembayaran bawaan form DIGANTI (bukan ditambah), karena baris bawaan
     * yang nominalnya kosong akan gagal validasi. Daftar booking bawaan menyembunyikan booking selesai bertanggal lampau, jadi
     * filter "Aktif & akan datang" dilepas.
     */
    private function processReferral(Booking $booking, array $data, array $payments)
    {
        $component = Livewire::test(ListBookings::class)->removeTableFilter('upcoming')->mountTableAction('process_referral', $booking);
        $defaultKeys = array_keys((array) $component->get('mountedTableActionsData.0.settlement_payments'));

        $keyed = [];
        foreach (array_values($payments) as $i => $row) {
            $keyed[$defaultKeys[$i] ?? 'baru-' . $i] = $row;
        }

        return $component->setTableActionData($data + ['settlement_payments' => $keyed])->callMountedTableAction();
    }

    private function admin(): User
    {
        return tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x']), fn (User $u) => $u->assignRole('super_admin'));
    }

    public function test_mixed_payments_post_one_cash_line_per_account(): void
    {
        $booking = $this->booking(1000000);
        $this->settle($booking, [['method' => 'tunai', 'amount' => 400000], ['method' => 'transfer', 'amount' => 600000]]);

        app(BookingPostingService::class)->sync($booking->fresh());

        $this->assertEqualsWithDelta(['1103' => 400000.0, '1104' => 600000.0], $this->debits($booking), 0.01);
        $this->assertSame('transfer', $booking->fresh()->payment_method, 'Metode utama = pembayaran terbesar.');
    }

    public function test_an_explicit_account_overrides_the_method_rule(): void
    {
        $booking = $this->booking(500000);
        $this->settle($booking, [['method' => 'tunai', 'payment_account_id' => $this->bank->id, 'amount' => 500000]]);

        app(BookingPostingService::class)->sync($booking->fresh());

        $this->assertEqualsWithDelta(['1104' => 500000.0], $this->debits($booking), 0.01);
    }

    public function test_the_unpaid_part_becomes_a_receivable_and_the_journal_stays_balanced(): void
    {
        $booking = $this->booking(1000000);
        $this->settle($booking, [['method' => 'transfer', 'amount' => 300000]]);

        $entry = app(BookingPostingService::class)->sync($booking->fresh());

        $this->assertEqualsWithDelta(['1104' => 300000.0, '1110' => 700000.0], $this->debits($booking), 0.01);
        $this->assertEqualsWithDelta((float) $entry->lines->sum('debit'), (float) $entry->lines->sum('credit'), 0.001);
        $this->assertDatabaseHas('receivables', ['source_type' => 'booking', 'source_id' => $booking->id, 'amount' => 700000]);
    }

    public function test_overpayment_is_rejected_with_a_clear_message(): void
    {
        $booking = $this->booking(1000000);

        try {
            $this->settle($booking, [['method' => 'tunai', 'amount' => 600000], ['method' => 'transfer', 'amount' => 500000]]);
            $this->fail('Kelebihan bayar seharusnya ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('melebihi nominal transaksi', $e->getMessage());
        }

        $this->assertSame(0, BookingSettlementPayment::count());
    }

    public function test_a_down_payment_counts_toward_the_limit_and_is_not_posted_twice(): void
    {
        $booking = $this->booking(1000000, ['status' => 'confirmed', 'transaction_amount' => null, 'amount_received' => null]);
        app(DownPaymentService::class)->receive($booking, 300000, null, null, $this->kas->id);
        $booking->update(['status' => 'completed', 'transaction_amount' => 1000000]);
        $booking = $booking->fresh();

        // 300.000 DP + 800.000 pembayaran = 1.100.000 > 1.000.000
        try {
            $this->settle($booking, [['method' => 'transfer', 'amount' => 800000]]);
            $this->fail('DP harus ikut dihitung.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('DP', $e->getMessage());
        }

        $this->settle($booking, [['method' => 'transfer', 'amount' => 700000]]);
        app(BookingPostingService::class)->sync($booking->fresh());

        $this->assertEqualsWithDelta(['2140' => 300000.0, '1104' => 700000.0], $this->debits($booking), 0.01);
    }

    public function test_a_booking_without_settlement_payments_keeps_the_old_single_cash_line(): void
    {
        $booking = $this->booking(800000, ['payment_method' => 'transfer']);

        app(BookingPostingService::class)->sync($booking->fresh());

        $this->assertEqualsWithDelta(['1104' => 800000.0], $this->debits($booking), 0.01);
    }

    public function test_normalize_rejects_bad_rows(): void
    {
        $service = app(BookingSettlementPaymentService::class);

        foreach ([
            [['method' => 'bitcoin', 'amount' => 1000], 'metode'],
            [['method' => 'tunai', 'amount' => 0], 'nominal'],
            [['method' => 'tunai', 'amount' => 'abc'], 'nominal'],
            [['method' => '', 'amount' => 5000], 'metode'],
        ] as [$row, $needle]) {
            try {
                $service->normalize([$row]);
                $this->fail('Seharusnya ditolak: ' . json_encode($row));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($needle, $e->getMessage());
            }
        }

        $this->assertSame([], $service->normalize([['method' => '', 'amount' => '']]));
    }

    public function test_an_inactive_or_other_store_account_is_rejected(): void
    {
        $other = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $this->kas->update(['store_id' => $other->id]);
        $service = app(BookingSettlementPaymentService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('milik toko lain');
        $service->normalize([['method' => 'tunai', 'payment_account_id' => $this->kas->id, 'amount' => 1000]], $this->store->id);
    }

    public function test_the_approval_path_saves_the_payments_and_recomputes_the_received_total(): void
    {
        $staff = tap(User::create(['name' => 'Staff', 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id]), fn (User $u) => $u->assignRole('super_admin'));
        $booking = $this->booking(0, ['transaction_amount' => null, 'amount_received' => null]);
        $service = app(TransactionApprovalService::class);

        $request = $service->submitBookingReferral($booking, 1000000, 1000000, [
            'settlement_payments' => [['method' => 'tunai', 'amount' => 250000], ['method' => 'transfer', 'amount' => 750000]],
        ], $staff->id);

        $service->approve($request, $this->admin()->id);

        $this->assertSame(2, $booking->settlementPayments()->count());
        $this->assertEqualsWithDelta(['1103' => 250000.0, '1104' => 750000.0], $this->debits($booking), 0.01);
        $this->assertEquals(1000000.0, (float) $booking->fresh()->amount_received);
    }

    public function test_the_process_referral_action_saves_lines_payments_and_posts(): void
    {
        $this->actingAs($this->admin(), 'web');
        $booking = $this->booking(0, ['transaction_amount' => null, 'amount_received' => null, 'product_premium_wash' => true]);

        $this->processReferral($booking, [
            'sale_lines' => [
                ['service_type' => 'ppf', 'quantity' => 1, 'unit_price' => 900000, 'discount_amount' => 0],
                ['service_type' => 'premium_wash', 'quantity' => 1, 'unit_price' => 100000, 'discount_amount' => 0],
            ],
            'transaction_amount' => 1000000,
            'referral_code' => null,
        ], [['method' => 'tunai', 'amount' => 400000], ['method' => 'transfer', 'amount' => 600000]])
            ->assertHasNoTableActionErrors();

        $booking = $booking->fresh();
        $this->assertEquals(1000000.0, (float) $booking->transaction_amount, 'Notifikasi: ' . $this->notifications());
        $this->assertEquals(1000000.0, (float) $booking->amount_received);
        $this->assertSame(2, $booking->saleLines()->count());
        $this->assertSame(2, $booking->settlementPayments()->count());
        $this->assertEqualsWithDelta(['1103' => 400000.0, '1104' => 600000.0], $this->debits($booking), 0.01);
    }

    public function test_the_process_referral_action_refuses_overpayment_and_saves_nothing(): void
    {
        $this->actingAs($this->admin(), 'web');
        $booking = $this->booking(0, ['transaction_amount' => null, 'amount_received' => null]);

        $this->processReferral($booking, [
            'sale_lines' => [],
            'transaction_amount' => 500000,
            'referral_code' => null,
        ], [['method' => 'tunai', 'amount' => 700000]])
            ->assertNotified('Pembayaran belum benar');

        $this->assertNull($booking->fresh()->journal_entry_id);
        $this->assertSame(0, BookingSettlementPayment::count());
        // Penolakan ditampilkan sebagai notifikasi 'Pembayaran belum benar' (dicek lewat asersi bawaan Filament).
    }

    public function test_the_payment_book_unites_down_payments_settlements_and_receivable_payments(): void
    {
        $booking = $this->booking(1000000, ['status' => 'confirmed', 'transaction_amount' => null, 'amount_received' => null]);
        app(DownPaymentService::class)->receive($booking, 200000, null, null, $this->kas->id);
        $booking->update(['status' => 'completed', 'transaction_amount' => 1000000]);
        $booking = $booking->fresh();
        $this->settle($booking, [['method' => 'transfer', 'amount' => 500000]]);
        app(BookingPostingService::class)->sync($booking->fresh());

        $receivable = \App\Models\Receivable::where('source_type', 'booking')->where('source_id', $booking->id)->firstOrFail();
        app(\App\Services\ReceivableService::class)->recordPayment($receivable, 100000, Carbon::today(), null);

        $this->actingAs($this->admin(), 'web');
        $result = Livewire::test(PaymentBookReport::class)->assertSuccessful()->assertSee('Buku Pembayaran')->instance()->getResult();

        $this->assertSame(['Pelunasan piutang', 'Pembayaran saat selesai', 'Uang Muka (DP)'], collect($result['byKind'])->keys()->sort()->values()->all());
        $this->assertEquals(800000.0, $result['total']);
        $this->assertEquals(500000.0, $result['byMethod']['Transfer Bank']);
    }
}
