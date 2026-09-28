<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Payable;
use App\Models\User;
use App\Services\PayableService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Audit Hutang Usaha 2026-09-29: validasi nominal, overpayment, pemisahan tugas,
 * pembatalan pembayaran/tagihan (jurnal pembalik), dan anti-duplikat per sumber.
 * (Belum pernah dijalankan lokal -- tidak ada PHP; cek hasil CI.)
 */
class PayableServiceTest extends TestCase
{
    use RefreshDatabase;

    private PayableService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChartOfAccountSeeder::class);
        $this->service = app(PayableService::class);
    }

    private function user(string $email): User
    {
        return User::create(['name' => $email, 'email' => $email, 'password' => 'x']);
    }

    private function makePayable(float $amount = 1_000_000, ?int $creatorId = null): Payable
    {
        $expense = ChartOfAccount::where('code', '6210')->firstOrFail();

        return $this->service->createWithJournal([
            'supplier_name' => 'PT Uji',
            'store_id' => null,
            'amount' => $amount,
            'due_date' => now()->addDays(10)->toDateString(),
            'created_by' => $creatorId,
        ], $expense->id);
    }

    public function test_rejects_non_positive_amount(): void
    {
        $this->expectException(RuntimeException::class);

        $this->makePayable(0);
    }

    public function test_payment_cannot_exceed_remaining(): void
    {
        $payable = $this->makePayable(1_000_000);

        $this->expectException(RuntimeException::class);

        $this->service->recordPayment($payable, 1_000_000.01, now(), null);
    }

    public function test_partial_then_full_payment_updates_status(): void
    {
        $payable = $this->makePayable(1_000_000);

        $this->service->recordPayment($payable, 400_000, now(), null);
        $this->assertSame('partial', $payable->fresh()->status);

        $this->service->recordPayment($payable->fresh(), 600_000, now(), null);
        $this->assertSame('paid', $payable->fresh()->status);
    }

    public function test_payment_date_cannot_be_in_the_future(): void
    {
        $payable = $this->makePayable();

        $this->expectException(RuntimeException::class);

        $this->service->recordPayment($payable, 100_000, now()->addDay(), null);
    }

    public function test_creator_cannot_pay_own_payable(): void
    {
        $creator = $this->user('creator@test.local');
        $payable = $this->makePayable(1_000_000, $creator->id);

        $this->expectException(RuntimeException::class);

        $this->service->recordPayment($payable, 100_000, now(), $creator->id);
    }

    public function test_other_user_can_pay(): void
    {
        $creator = $this->user('creator2@test.local');
        $payer = $this->user('payer@test.local');
        $payable = $this->makePayable(1_000_000, $creator->id);

        $payment = $this->service->recordPayment($payable, 100_000, now(), $payer->id);

        $this->assertNotNull($payment->journal_entry_id);
    }

    public function test_void_payment_restores_remaining_and_status(): void
    {
        $payable = $this->makePayable(1_000_000);
        $payment = $this->service->recordPayment($payable, 1_000_000, now(), null);
        $this->assertSame('paid', $payable->fresh()->status);

        $this->service->voidPayment($payment, null, 'Salah rekening');

        $fresh = $payable->fresh();
        $this->assertSame('unpaid', $fresh->status);
        $this->assertEquals(0.0, (float) $fresh->amount_paid);
        $this->assertNotNull($payment->fresh()->void_journal_entry_id);
    }

    public function test_cannot_void_twice(): void
    {
        $payable = $this->makePayable();
        $payment = $this->service->recordPayment($payable, 100_000, now(), null);
        $this->service->voidPayment($payment, null, 'Salah input');

        $this->expectException(RuntimeException::class);

        $this->service->voidPayment($payment->fresh(), null, 'Lagi');
    }

    public function test_cancel_payable_requires_no_active_payment(): void
    {
        $payable = $this->makePayable();
        $this->service->recordPayment($payable, 100_000, now(), null);

        $this->expectException(RuntimeException::class);

        $this->service->cancelPayable($payable->fresh(), null, 'Salah input');
    }

    public function test_cancel_payable_reverses_journal_and_blocks_payment(): void
    {
        $payable = $this->makePayable();

        $cancelled = $this->service->cancelPayable($payable, null, 'Salah input');

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertNotNull($cancelled->cancel_journal_entry_id);

        $this->expectException(RuntimeException::class);
        $this->service->recordPayment($cancelled->fresh(), 100_000, now(), null);
    }

    public function test_duplicate_source_is_rejected(): void
    {
        $data = ['supplier_name' => 'PT Uji', 'store_id' => null, 'amount' => 500_000, 'source_type' => 'purchase_request', 'source_id' => 77];

        $this->service->create($data);

        $this->expectException(RuntimeException::class);

        $this->service->create($data);
    }

    public function test_reconcile_matches_after_manual_bill(): void
    {
        $this->makePayable(750_000);

        $r = $this->service->reconcile();

        $this->assertEquals(750_000.0, $r['gl']);
        $this->assertEquals(0.0, $r['diff']);
    }
}
