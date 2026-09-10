<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\BookingResource;
use App\Models\Booking;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * "Grafik tren harian" ala Majoo (garis Total Penjualan vs Periode
 * Sebelumnya) — diminta 2026-09-08, prioritas kedua setelah split
 * Sudah Diterima/Piutang. Sumbu-X hari-dalam-bulan (1..akhir bulan),
 * 2 garis: bulan berjalan vs bulan lalu — perbandingan apple-to-apple
 * per tanggal, bukan cuma total kumulatif.
 *
 * Sumber sama dengan BookingRevenueStatsWidget/BookingRevenueByCategoryChart
 * (whereHas('journalEntry'), transaction_amount > 0) supaya SELALU
 * konsisten dengan Jurnal Umum & widget pendapatan lain.
 */
class BookingRevenueTrendChart extends ChartWidget
{
    protected static ?string $heading = 'Tren Pendapatan Harian';

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        $user = auth()->user();

        // Dipakai di Dashboard utama (/admin) DAN Dashboard Penjualan —
        // salah satu akses cukup.
        return ($user?->hasMenuAccess(\App\Filament\Pages\SalesDashboard::class) ?? false)
            || ($user?->hasMenuAccess(BookingResource::class) ?? false);
    }

    protected function getData(): array
    {
        $user = auth()->user();
        $isSuperAdmin = $user?->isFullAccess() ?? false;

        $thisMonthStart = now()->startOfMonth();
        $lastMonthStart = now()->subMonthNoOverflow()->startOfMonth();
        $lastMonthEnd = now()->subMonthNoOverflow()->endOfMonth();
        $daysInThisMonth = now()->daysInMonth;

        $thisMonthByDay = $this->dailyRevenue($thisMonthStart, now()->endOfMonth(), $user, $isSuperAdmin);
        $lastMonthByDay = $this->dailyRevenue($lastMonthStart, $lastMonthEnd, $user, $isSuperAdmin);

        $labels = [];
        $thisMonthData = [];
        $lastMonthData = [];

        for ($day = 1; $day <= $daysInThisMonth; $day++) {
            $labels[] = (string) $day;
            // Hari yang belum terlewati bulan ini SENGAJA null (bukan 0)
            // — supaya garisnya berhenti di hari ini, bukan turun ke 0
            // seolah-olah pendapatan anjlok (garis Chart.js melompati
            // titik null, tidak menggambar sampai ke sana).
            $thisMonthData[] = $day <= now()->day ? ($thisMonthByDay[$day] ?? 0) : null;
            $lastMonthData[] = $lastMonthByDay[$day] ?? 0;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Bulan Ini',
                    'data' => $thisMonthData,
                    'borderColor' => '#ED1651',
                    'backgroundColor' => 'rgba(237, 22, 81, 0.12)',
                    'pointBackgroundColor' => '#ED1651',
                    'pointBorderColor' => '#ffffff',
                    'pointRadius' => 2,
                    'pointHoverRadius' => 5,
                    'borderWidth' => 2,
                    'tension' => 0.3,
                    'fill' => true,
                    'spanGaps' => false,
                ],
                [
                    'label' => 'Bulan Lalu',
                    'data' => $lastMonthData,
                    'borderColor' => '#94a3b8',
                    'backgroundColor' => 'transparent',
                    'pointBackgroundColor' => '#94a3b8',
                    'pointBorderColor' => '#ffffff',
                    'pointRadius' => 0,
                    'pointHoverRadius' => 4,
                    'borderWidth' => 1.5,
                    'borderDash' => [4, 4],
                    'tension' => 0.3,
                    'fill' => false,
                ],
            ],
            'labels' => $labels,
        ];
    }

    /**
     * @return array<int, float> [hari-ke => total pendapatan]
     */
    private function dailyRevenue(Carbon $start, Carbon $end, $user, bool $isSuperAdmin): array
    {
        $query = Booking::query()
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()]))
            ->where('transaction_amount', '>', 0)
            ->with(['journalEntry:id,entry_date']);

        if (! $isSuperAdmin) {
            // store_id di bookings NOT NULL (migrasi create_bookings_table)
            // — orWhereNull() dulu itu dead code + bocor angka toko lain.
            $query->where('store_id', $user->store_id);
        }

        $byDay = [];
        $query->get(['id', 'transaction_amount', 'journal_entry_id'])->each(function (Booking $booking) use (&$byDay) {
            $day = $booking->journalEntry?->entry_date?->day;
            if ($day === null) {
                return;
            }
            $byDay[$day] = ($byDay[$day] ?? 0) + (float) $booking->transaction_amount;
        });

        return $byDay;
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => true, 'position' => 'top', 'align' => 'end'],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                    'grid' => ['color' => 'rgba(148, 163, 184, 0.12)'],
                ],
                'x' => [
                    'grid' => ['display' => false],
                ],
            ],
        ];
    }
}
