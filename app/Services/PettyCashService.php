<?php

namespace App\Services;

use App\Models\CashTransfer;
use App\Models\PaymentAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Kas kecil sistem dana tetap/imprest (Keuangan Fase 4, 2026-11-07). Kas kecil adalah rekening kas berjenis "kas_kecil" dengan plafon dana,
 * batas nominal per bukti, dan pemegang kas. Pengeluaran kecil dari rekening itu dicatat lewat Transaksi Keuangan biasa (kategori, bukti
 * foto, jurnal), tetapi pengeluaran sampai batas per bukti oleh karyawan toko yang sama tidak perlu menunggu persetujuan direksi.
 * Saldo kas kecil tidak boleh dilampaui. Isi ulang = pemindahan kas dari rekening sumber sebesar plafon dikurangi saldo.
 */
class PettyCashService
{
    public function __construct(private readonly FinancialStatementService $statements, private readonly CashTransferService $transfers)
    {
    }

    public function balance(PaymentAccount $account): float
    {
        return $account->chartAccount ? round($this->statements->balanceAsOf($account->chartAccount, now()), 2) : 0.0;
    }

    /** Jumlah yang diperlukan untuk mengembalikan saldo ke plafon (0 bila sudah penuh atau plafon belum diisi). */
    public function replenishAmount(PaymentAccount $account): float
    {
        if ($account->float_amount === null) {
            return 0.0;
        }

        return max(0.0, round((float) $account->float_amount - $this->balance($account), 2));
    }

    public function find(?int $paymentAccountId): ?PaymentAccount
    {
        if (! $paymentAccountId) {
            return null;
        }

        $account = PaymentAccount::with('chartAccount')->find($paymentAccountId);

        return $account && $account->kind === 'kas_kecil' ? $account : null;
    }

    /**
     * Pengeluaran ini boleh langsung dicatat tanpa pengajuan persetujuan? Syarat: rekening kas kecil aktif, nominal tidak melebihi batas per
     * bukti (batas harus diisi), dan pencatat adalah pemegang kas atau karyawan toko rekening itu.
     *
     * @param  array<string, mixed>  $data data transaksi (payment_account_id, amount, type, store_id)
     */
    public function allowsDirectSpending(array $data, User $user): bool
    {
        $account = $this->find($data['payment_account_id'] ?? null);

        if (! $account || ! $account->is_active || ($data['type'] ?? null) !== 'out') {
            return false;
        }

        $amount = (float) ($data['amount'] ?? 0);

        if ($account->voucher_limit === null || $amount <= 0 || $amount > (float) $account->voucher_limit) {
            return false;
        }

        $isCustodian = $account->custodian_id && (int) $account->custodian_id === (int) $user->id;
        $sameStore = $account->store_id && (int) $account->store_id === (int) $user->store_id;

        return $isCustodian || $sameStore;
    }

    /** @throws RuntimeException bila saldo kas kecil tidak cukup */
    public function assertSufficient(PaymentAccount $account, float $amount): void
    {
        $balance = $this->balance($account);

        if ($amount > $balance + 0.005) {
            throw new RuntimeException('Saldo kas kecil "' . $account->name . '" tinggal Rp' . number_format($balance, 0, ',', '.') . ', tidak cukup untuk Rp' . number_format($amount, 0, ',', '.') . '. Minta isi ulang dulu.');
        }
    }

    /**
     * Isi ulang kas kecil dari akun kas/bank sumber. Nominal bawaan = plafon dikurangi saldo.
     *
     * @throws RuntimeException
     */
    public function replenish(PaymentAccount $petty, int $sourceChartAccountId, ?float $amount, ?int $userId): CashTransfer
    {
        if ($petty->kind !== 'kas_kecil') {
            throw new RuntimeException('Hanya rekening kas kecil yang bisa diisi ulang.');
        }

        $amount ??= $this->replenishAmount($petty);

        if ($amount <= 0) {
            throw new RuntimeException('Tidak ada yang perlu diisi ulang: saldo sudah mencapai plafon.');
        }

        return $this->transfers->create(
            $sourceChartAccountId,
            $petty->chart_of_account_id,
            $amount,
            Carbon::now(),
            $petty->store_id ? (int) $petty->store_id : null,
            "Isi ulang kas kecil {$petty->name}",
            $userId,
        );
    }
}
