<?php

namespace App\Support;

use App\Models\AccountingPeriod;
use Illuminate\Support\Carbon;

/**
 * Status buku bulan berjalan dan bulan lalu (Terbuka/Ditutup) untuk ditampilkan di atas halaman Keuangan,
 * supaya staf tahu bulan mana yang sudah dikunci sebelum menginput transaksi. Ide dari bilah status ACCURATE 5.
 * Bulan tanpa baris di accounting_periods dianggap terbuka (lihat AccountingPeriod::isClosedFor).
 */
class PeriodStatus
{
    /**
     * @return list<array{label: string, closed: bool, closed_by: ?string, closed_at: ?Carbon}>
     */
    public static function summary(?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy();
        $months = [$now->copy()->startOfMonth(), $now->copy()->subMonthNoOverflow()->startOfMonth()];

        $closed = AccountingPeriod::with('closer')
            ->whereIn('period_month', array_map(fn (Carbon $m) => $m->toDateString(), $months))
            ->get()
            ->keyBy(fn (AccountingPeriod $p) => $p->period_month->toDateString());

        return array_map(function (Carbon $month) use ($closed) {
            $period = $closed->get($month->toDateString());

            return [
                'label' => $month->translatedFormat('F Y'),
                'closed' => $period !== null,
                'closed_by' => $period?->closer?->name,
                'closed_at' => $period?->closed_at,
            ];
        }, $months);
    }

    /** Alamat halaman Keuangan: dipakai hook panel untuk memutuskan kapan bilah ini tampil. */
    public static function appliesToPath(string $panelPath, string $clusterSlug, string $requestPath): bool
    {
        $base = trim($panelPath, '/') . '/' . trim($clusterSlug, '/');
        $requestPath = trim($requestPath, '/');

        return $requestPath === $base || str_starts_with($requestPath, $base . '/');
    }
}
