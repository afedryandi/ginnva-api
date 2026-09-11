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
    protected static ?string $heading = 'Tren Pendapatan Harian (Bulan Ini vs Bulan Lalu)';

    protected static ?int $sort = 1;

    // EKSPLISIT dimatikan (audit 2026-09-11) — default ChartWidget di
    // Filament v3 auto-poll berkala walau halaman idle, artinya query
    // agregat (join journal_entries + GROUP BY) jalan otomatis di
    // background tanpa perlu. Data cuma berubah lewat "Proses Referral"
    // (aksi eksplisit staf), jadi tidak perlu live-refresh — Livewire
    // sudah re-render widget ini tiap kali dashboard di-render ulang
    // (ganti periode/tanggal/cabang).
    protected static ?string $pollingInterval = null;

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
        // Agregasi & bucket per hari di SQL (GROUP BY DAY) — bukan tarik
        // semua booking lalu group di PHP. Join ke journal_entries setara
        // whereHas('journalEntry') karena bookings.journal_entry_id FK.
        $query = Booking::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'bookings.journal_entry_id')
            ->whereBetween('journal_entries.entry_date', [$start->toDateString(), $end->toDateString()])
            ->where('bookings.transaction_amount', '>', 0);

        if (! $isSuperAdmin) {
            $query->where('bookings.store_id', $user->store_id);
        }

        $rows = $query
            ->groupByRaw('DAY(journal_entries.entry_date)')
            ->selectRaw('DAY(journal_entries.entry_date) as d, COALESCE(SUM(bookings.transaction_amount), 0) as total')
            ->toBase()
            ->get();

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(int) $row->d] = (float) $row->total;
        }

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
