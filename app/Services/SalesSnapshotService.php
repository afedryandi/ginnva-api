<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Refund;
use Illuminate\Support\Carbon;

/**
 * Sumber kebenaran untuk angka "Ringkasan Penjualan" (omzet ringkas per
 * periode) di halaman Filament App\Filament\Pages\SalesDashboard
 * (tab Penjualan > Dashboard).
 *
 * SEBELUMNYA seluruh logika ini nyangkut di SalesDashboard sebagai
 * private method — ditarik ke service saat audit Dashboard Penjualan
 * 2026-09-10 supaya halaman-nya tipis, mudah diuji, dan kalau nanti ada
 * pemakai lain (mis. endpoint mobile) angkanya dijamin identik.
 *
 * Definisi "pendapatan" identik dengan seluruh laporan Penjualan lain:
 * booking yang benar-benar sudah diproses ke Jurnal Umum
 * (whereHas('journalEntry') + transaction_amount > 0), dikurangi refund
 * yang diproses pada periode itu (by Refund.created_at) → "Penjualan
 * Bersih" di Laba-Rugi. Tidak ada angka yang ditebak / dipaksa jadi 0.
 */
class SalesSnapshotService
{
    /** @var list<string> */
    public const PERIODS = ['harian', 'mingguan', 'bulanan'];

    /**
     * Rentang periode berjalan berdasarkan tanggal acuan.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function range(string $period, Carbon $ref): array
    {
        return match ($period) {
            'harian' => [$ref->copy()->startOfDay(), $ref->copy()->endOfDay()],
            'mingguan' => [$ref->copy()->startOfWeek(Carbon::MONDAY), $ref->copy()->endOfWeek(Carbon::SUNDAY)],
            'bulanan' => [$ref->copy()->startOfMonth(), $ref->copy()->endOfMonth()],
            default => [$ref->copy()->startOfDay(), $ref->copy()->endOfDay()],
        };
    }

    /**
     * Periode SEBELUMNYA yang sama panjang, langsung berbatasan dengan
     * awal periode berjalan — pembanding apple-to-apple.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function previousRange(string $period, Carbon $ref): array
    {
        [$start, $end] = $this->range($period, $ref);

        return match ($period) {
            'harian' => [$start->copy()->subDay(), $end->copy()->subDay()],
            'mingguan' => [$start->copy()->subWeek(), $end->copy()->subWeek()],
            'bulanan' => [$start->copy()->subMonthNoOverflow()->startOfMonth(), $start->copy()->subMonthNoOverflow()->endOfMonth()],
            default => [$start->copy()->subDay(), $end->copy()->subDay()],
        };
    }

    /**
     * Geser tanggal acuan maju/mundur 1 periode.
     */
    public function shift(Carbon $ref, string $period, int $direction): Carbon
    {
        return match ($period) {
            'harian' => $ref->copy()->addDays($direction),
            'mingguan' => $ref->copy()->addWeeks($direction),
            'bulanan' => $ref->copy()->addMonthsNoOverflow($direction),
            default => $ref->copy(),
        };
    }

    /**
     * Agregat 1 periode.
     *
     * $storeId null = seluruh cabang (hanya untuk full-access — staff toko
     * WAJIB di-resolve ke store_id-nya sendiri oleh pemanggil). store_id di
     * bookings NOT NULL (migrasi create_bookings_table), jadi tidak perlu
     * orWhereNull().
     *
     * @return array{revenue: float, received: float, outstanding: float, count: int, productsSold: int, refund: float, net: float}
     */
    public function summarize(Carbon $start, Carbon $end, ?int $storeId): array
    {
        $agg = Booking::query()
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()]))
            ->where('transaction_amount', '>', 0)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->selectRaw(
                'COUNT(*) as cnt,'
                . ' COALESCE(SUM(transaction_amount), 0) as revenue,'
                . ' COALESCE(SUM(COALESCE(amount_received, transaction_amount)), 0) as received,'
                . ' COALESCE(SUM(COALESCE(product_kaca_film, 0) + COALESCE(product_ppf, 0) + COALESCE(product_detailing, 0)), 0) as products_sold'
            )
            ->toBase()
            ->first();

        $revenue = (float) $agg->revenue;
        $received = (float) $agg->received;

        $refund = (float) Refund::query()
            ->whereBetween('created_at', [$start, $end])
            ->when($storeId, fn ($q) => $q->whereHas('booking', fn ($q2) => $q2->where('store_id', $storeId)))
            ->sum('amount');

        return [
            'revenue' => $revenue,
            'received' => $received,
            'outstanding' => max(0, $revenue - $received),
            'count' => (int) $agg->cnt,
            'productsSold' => (int) $agg->products_sold,
            'refund' => $refund,
            'net' => $revenue - $refund,
        ];
    }

    /**
     * Booking SELESAI yang belum "Proses Referral" (belum ada jurnal
     * pendapatan) — pendapatan yang SUDAH terjadi tapi belum tercatat.
     * Booking cancelled tidak mungkin punya jurnal (cancel diblokir setelah
     * status 'completed'), jadi tidak perlu dikecualikan.
     */
    public function pendingCount(?int $storeId): int
    {
        return Booking::query()
            ->where('status', 'completed')
            ->whereDoesntHave('journalEntry')
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->count();
    }

    /**
     * Snapshot lengkap 1 layar Dashboard/Ringkasan untuk 1 periode + 1
     * cabang. Baris "Akumulasi Awal Bulan", "Proyeksi", dan banner growth
     * SELALU dihitung dari bulan KALENDER berjalan — tidak mengikuti
     * $period/$referenceDate (sama seperti Majoo).
     *
     * @return array<string, mixed>
     */
    public function snapshot(string $period, Carbon $ref, ?int $storeId): array
    {
        [$start, $end] = $this->range($period, $ref);
        [$prevStart, $prevEnd] = $this->previousRange($period, $ref);

        $current = $this->summarize($start, $end, $storeId);
        $previous = $this->summarize($prevStart, $prevEnd, $storeId);

        $monthStart = now()->startOfMonth();
        $monthToDate = $this->summarize($monthStart, now()->endOfDay(), $storeId);
        $monthToDateNet = $monthToDate['net'];

        $daysElapsed = now()->day;
        $daysInMonth = now()->daysInMonth;
        $projection = $daysElapsed > 0 ? ($monthToDateNet / $daysElapsed) * $daysInMonth : 0.0;

        // Growth apple-to-apple: bulan lalu TAPI cuma sejumlah hari yang
        // sama sudah berjalan bulan ini (bukan bulan lalu penuh vs bulan
        // ini yang masih separuh jalan).
        $lastMonthStart = now()->subMonthNoOverflow()->startOfMonth();
        $comparableDayCount = min($daysElapsed, $lastMonthStart->daysInMonth);
        $lastMonthToDate = $this->summarize(
            $lastMonthStart,
            $lastMonthStart->copy()->addDays($comparableDayCount - 1)->endOfDay(),
            $storeId
        );
        $lastMonthToDateNet = $lastMonthToDate['net'];

        return [
            'period' => $period,
            'range' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'previousRange' => ['from' => $prevStart->toDateString(), 'to' => $prevEnd->toDateString()],
            'current' => $current,
            'previous' => $previous,
            'monthToDateNet' => $monthToDateNet,
            'projection' => $projection,
            'growthDelta' => $monthToDateNet - $lastMonthToDateNet,
            'growthHasComparison' => $lastMonthToDateNet > 0,
            'pendingCount' => $this->pendingCount($storeId),
        ];
    }
}
