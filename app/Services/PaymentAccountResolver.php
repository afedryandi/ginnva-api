<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\PaymentAccount;
use Illuminate\Support\Collection;

/**
 * Menentukan akun kas/bank untuk sebuah penerimaan atau pembayaran (Keuangan Fase 1, 2026-11-05). Urutan:
 *  1. rekening aktif milik toko itu, lalu rekening pusat, yang menerima metode bayar tersebut;
 *  2. rekening bawaan toko itu, lalu rekening bawaan pusat;
 *  3. akun 1101 Kas di Tangan (perilaku sebelum rekening dipakai).
 * Tanpa rekening terdaftar, hasilnya selalu 1101, sehingga tidak ada perubahan sampai rekening pertama dibuat.
 */
class PaymentAccountResolver
{
    public const LEGACY_CASH_CODE = '1101';

    public function resolve(?int $storeId, ?string $method = null): ?ChartOfAccount
    {
        $account = $this->resolvePaymentAccount($storeId, $method);

        return $account?->chartAccount ?? $this->legacyCash();
    }

    public function resolvePaymentAccount(?int $storeId, ?string $method = null): ?PaymentAccount
    {
        $candidates = $this->candidates($storeId);

        if ($candidates->isEmpty()) {
            return null;
        }

        // Rekening toko didahulukan atas rekening pusat.
        $ordered = $candidates->sortBy(fn (PaymentAccount $a) => $a->store_id === null ? 1 : 0)->values();

        if ($method !== null) {
            $byMethod = $ordered->first(fn (PaymentAccount $a) => $a->acceptsMethod($method));

            if ($byMethod) {
                return $byMethod;
            }
        }

        return $ordered->first(fn (PaymentAccount $a) => $a->is_default);
    }

    public function legacyCash(): ?ChartOfAccount
    {
        return ChartOfAccount::where('code', self::LEGACY_CASH_CODE)->first();
    }

    /** @return Collection<int, PaymentAccount> */
    private function candidates(?int $storeId): Collection
    {
        return PaymentAccount::query()
            ->with('chartAccount')
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('store_id')->when($storeId, fn ($w) => $w->orWhere('store_id', $storeId)))
            ->orderBy('id')
            ->get()
            ->filter(fn (PaymentAccount $a) => $a->isUsable())
            ->values();
    }

    /** Akun COA dari rekening yang tercatat pada transaksi lama/baru; null (baris lama) berarti 1101. */
    public function chartAccountFor(?int $paymentAccountId): ?ChartOfAccount
    {
        if ($paymentAccountId) {
            $account = PaymentAccount::with('chartAccount')->find($paymentAccountId);

            if ($account?->chartAccount) {
                return $account->chartAccount;
            }
        }

        return $this->legacyCash();
    }
}
