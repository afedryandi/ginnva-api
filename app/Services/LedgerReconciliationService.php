<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\BookingDownPayment;
use App\Models\ChartOfAccount;
use App\Models\ConsumableItem;
use App\Models\Payable;
use App\Models\RawMaterial;
use App\Models\Receivable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rekonsiliasi sub-buku ke akun kontrol Buku Besar (Keuangan Fase 0, 2026-11-04). Membandingkan saldo akun kontrol (jurnal posted) dengan
 * total sub-buku yang dikelola modul lain: Piutang Usaha, Hutang Usaha, Pendapatan Diterima Dimuka (DP), Aset Tetap per akun, dan
 * Persediaan (bahan baku dan barang habis pakai). Hanya MELAPORKAN selisih, tidak memperbaiki apa pun.
 *
 * Sub-buku dibaca dalam keadaan SAAT INI, sedangkan saldo akun dihitung sampai tanggal $asOf (bawaan hari ini), jadi perbandingan hanya
 * bermakna untuk tanggal hari ini. Selisih tidak selalu berarti galat: saldo awal yang diinput tanpa jurnal, atau pemakaian bahan yang
 * belum dijurnal (HPP belum otomatis), akan muncul sebagai selisih Persediaan.
 */
class LedgerReconciliationService
{
    /** Selisih di bawah ini dianggap pembulatan. */
    public const TOLERANCE = 1.0;

    public function __construct(private readonly FinancialStatementService $statements)
    {
    }

    /**
     * @return Collection<int, array{key: string, label: string, account: string, ledger: float, subledger: float, difference: float, ok: bool, note: ?string}>
     */
    public function run(?Carbon $asOf = null, ?int $storeId = null): Collection
    {
        $asOf ??= now();
        $rows = collect();

        $rows->push($this->check('receivable', 'Piutang Usaha', '1110', $asOf, $storeId, $this->receivables($storeId)));
        $rows->push($this->check('payable', 'Hutang Usaha', '2110', $asOf, $storeId, $this->payables($storeId)));
        $rows->push($this->check('deferred_revenue', 'Pendapatan Diterima Dimuka (DP booking)', '2140', $asOf, $storeId, $this->downPayments($storeId)));

        foreach ($this->assetsByAccount($storeId) as $accountId => $cost) {
            $account = ChartOfAccount::find($accountId);

            if ($account) {
                $rows->push($this->check("asset_{$account->code}", "Aset Tetap: {$account->name}", $account->code, $asOf, $storeId, $cost,
                    'Total harga perolehan aset berstatus aktif/diperbaiki/rusak pada akun ini.'));
            }
        }

        // Persediaan tidak dipisah per toko di sub-buku, jadi hanya dibandingkan untuk seluruh perusahaan.
        if (! $storeId) {
            $rows->push($this->check('raw_material', 'Persediaan Bahan Baku', '1130', $asOf, null, $this->rawMaterialValue(),
                'Pemakaian bahan untuk booking belum dijurnal sebagai HPP, jadi selisih di sini akan membesar seiring pemakaian (rencana Fase 2).'));
            $rows->push($this->check('consumable', 'Persediaan Barang Habis Pakai', '1131', $asOf, null, $this->consumableValue(),
                'Pemakaian barang habis pakai belum dijurnal sebagai HPP (rencana Fase 2).'));
        }

        return $rows->filter()->values();
    }

    /** @return array{key: string, label: string, account: string, ledger: float, subledger: float, difference: float, ok: bool, note: ?string}|null */
    private function check(string $key, string $label, string $accountCode, Carbon $asOf, ?int $storeId, float $subledger, ?string $note = null): ?array
    {
        $account = ChartOfAccount::where('code', $accountCode)->first();

        if (! $account) {
            return null;
        }

        $ledger = round($this->statements->balanceAsOf($account, $asOf, $storeId), 2);
        $subledger = round($subledger, 2);
        $difference = round($ledger - $subledger, 2);

        return [
            'key' => $key,
            'label' => $label,
            'account' => "{$account->code} - {$account->name}",
            'ledger' => $ledger,
            'subledger' => $subledger,
            'difference' => $difference,
            'ok' => abs($difference) < self::TOLERANCE,
            'note' => $note,
        ];
    }

    private function receivables(?int $storeId): float
    {
        return (float) Receivable::withoutGlobalScopes()
            ->whereIn('status', ['unpaid', 'partial'])
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->selectRaw('COALESCE(SUM(amount - amount_paid), 0) as total')
            ->value('total');
    }

    private function payables(?int $storeId): float
    {
        return (float) Payable::withoutGlobalScopes()
            ->whereIn('status', ['unpaid', 'partial'])
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->selectRaw('COALESCE(SUM(amount - amount_paid), 0) as total')
            ->value('total');
    }

    /** DP yang diterima dan belum dipakai atau dikembalikan. */
    private function downPayments(?int $storeId): float
    {
        return (float) BookingDownPayment::query()
            ->when($storeId, fn ($q) => $q->whereHas('booking', fn ($b) => $b->withoutGlobalScopes()->where('store_id', $storeId)))
            ->selectRaw('COALESCE(SUM(amount - applied_amount - refunded_amount), 0) as total')
            ->value('total');
    }

    /** @return array<int, float> chart_of_account_id => total harga perolehan */
    private function assetsByAccount(?int $storeId): array
    {
        return Asset::withoutGlobalScopes()
            ->whereIn('status', ['aktif', 'diperbaiki', 'rusak'])
            ->whereNotNull('chart_of_account_id')
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->selectRaw('chart_of_account_id, COALESCE(SUM(purchase_cost), 0) as total')
            ->groupBy('chart_of_account_id')
            ->pluck('total', 'chart_of_account_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    private function rawMaterialValue(): float
    {
        return (float) RawMaterial::query()->with('batches')->get()->sum(fn (RawMaterial $m) => $m->stockValue());
    }

    private function consumableValue(): float
    {
        return (float) ConsumableItem::query()->get()->sum(fn (ConsumableItem $c) => (float) $c->current_stock * (float) ($c->unit_cost ?? 0));
    }
}
