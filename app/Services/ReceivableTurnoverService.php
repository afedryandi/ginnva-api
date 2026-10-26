<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Perputaran piutang dan hari penagihan (audit ACCURATE 5, rasio piutang untuk CFO). Hitungan untuk satu rentang tanggal:
 *  - penjualan kredit = piutang yang terbit dalam rentang;
 *  - dibayar = pembayaran piutang dalam rentang (yang dibatalkan tidak dihitung);
 *  - rata-rata piutang = (sisa piutang di hari sebelum rentang + sisa piutang di akhir rentang) / 2;
 *  - perputaran = penjualan kredit / rata-rata piutang; hari penagihan = rata-rata piutang / penjualan kredit * jumlah hari.
 * Sisa piutang di sebuah tanggal dihitung ulang dari riwayatnya (tanggal terbit, pembayaran, dan pembatalan), bukan dari sisa hari ini.
 * Piutang yang sudah dibatalkan pada tanggal itu tidak dihitung. Tanggal terbit = tanggal piutang dibuat di sistem.
 */
class ReceivableTurnoverService
{
    /**
     * @return array{from: Carbon, to: Carbon, days: int, credit_sales: float, collected: float, opening: float, closing: float, average: float, turnover: ?float, collection_days: ?float}
     */
    public function summarize(Carbon $from, Carbon $to, ?int $storeId = null): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        $days = (int) $from->diffInDays($to) + 1;

        $creditSales = (float) $this->receivables($to, $storeId)
            ->whereDate('receivables.created_at', '>=', $from->toDateString())
            ->sum('receivables.amount');

        $collected = $this->payments($to, $storeId)
            ->whereDate('receivable_payments.payment_date', '>=', $from->toDateString())
            ->sum('receivable_payments.amount');

        $opening = $this->outstanding($from->copy()->subDay(), $storeId);
        $closing = $this->outstanding($to, $storeId);
        $average = round(($opening + $closing) / 2, 2);

        return [
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'credit_sales' => round($creditSales, 2),
            'collected' => round((float) $collected, 2),
            'opening' => $opening,
            'closing' => $closing,
            'average' => $average,
            'turnover' => $average > 0 && $creditSales > 0 ? round($creditSales / $average, 2) : null,
            'collection_days' => $average > 0 && $creditSales > 0 ? round($average / $creditSales * $days, 1) : null,
        ];
    }

    /** Sisa piutang pada akhir hari $asOf. */
    public function outstanding(Carbon $asOf, ?int $storeId = null): float
    {
        $issued = (float) $this->receivables($asOf, $storeId)->sum('receivables.amount');
        $paid = (float) $this->payments($asOf, $storeId)->sum('receivable_payments.amount');

        return round($issued - $paid, 2);
    }

    /** Piutang yang sudah terbit dan belum dibatalkan pada akhir hari $asOf. */
    private function receivables(Carbon $asOf, ?int $storeId): Builder
    {
        $date = $asOf->toDateString();

        return DB::table('receivables')
            ->whereDate('receivables.created_at', '<=', $date)
            ->where(fn ($q) => $q->whereNull('receivables.cancelled_at')->orWhereDate('receivables.cancelled_at', '>', $date))
            ->tap(fn ($q) => $this->applyStore($q, $storeId));
    }

    /** Pembayaran sampai akhir hari $asOf yang belum dibatalkan, atas piutang yang berlaku pada tanggal itu. */
    private function payments(Carbon $asOf, ?int $storeId): Builder
    {
        $date = $asOf->toDateString();

        return DB::table('receivable_payments')
            ->join('receivables', 'receivables.id', '=', 'receivable_payments.receivable_id')
            ->whereDate('receivable_payments.payment_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('receivable_payments.voided_at')->orWhereDate('receivable_payments.voided_at', '>', $date))
            ->whereDate('receivables.created_at', '<=', $date)
            ->where(fn ($q) => $q->whereNull('receivables.cancelled_at')->orWhereDate('receivables.cancelled_at', '>', $date))
            ->tap(fn ($q) => $this->applyStore($q, $storeId));
    }

    private function applyStore(Builder $query, ?int $storeId): void
    {
        if ($storeId === FinancialStatementService::COMPANY_WIDE) {
            $query->whereNull('receivables.store_id');
        } elseif ($storeId !== null) {
            $query->where('receivables.store_id', $storeId);
        }
    }
}
