<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\BookingResource;
use App\Models\Booking;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Kartu pendapatan booking (Rupiah) di Dashboard — referensi "Dashboard
 * Penjualan" Majoo (dashboard.majoo.id/sales-dashboard), diminta
 * 2026-09-08. BookingStatsWidget yang sudah ada murni OPERASIONAL
 * (jumlah booking/garansi/quotation), tidak ada satu pun angka Rupiah
 * — widget ini melengkapi sisi FINANSIAL-nya, sort di atas
 * BookingStatsWidget (sort=0) supaya jadi headline pertama, sama
 * seperti Majoo naruh "Total Penjualan" paling atas.
 *
 * Sumber kebenaran nominal: Booking::transaction_amount, TAPI difilter
 * lewat journalEntry.entry_date (bukan created_at/updated_at booking
 * itu sendiri) — journalEntry cuma ada begitu "Proses Referral" benar-
 * benar disimpan kasir (lihat BookingPostingService), jadi angka di
 * sini otomatis konsisten dengan Jurnal Umum di Keuangan (bukan
 * booking yang baru "Selesai" statusnya tapi nominalnya belum diisi).
 */
class BookingRevenueStatsWidget extends BaseWidget
{
    protected ?string $heading = 'Pendapatan Booking';

    protected static ?int $sort = 0;

    public static function canView(): bool
    {
        return auth()->user()?->hasMenuAccess(BookingResource::class) ?? false;
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        $isSuperAdmin = $user?->isFullAccess() ?? false;

        $today = now();
        $yesterday = now()->subDay();
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();
        $lastMonthStart = now()->subMonthNoOverflow()->startOfMonth();
        $lastMonthEnd = now()->subMonthNoOverflow()->endOfMonth();

        $todayRevenue = $this->revenueBetween($today->copy()->startOfDay(), $today->copy()->endOfDay(), $user, $isSuperAdmin);
        $yesterdayRevenue = $this->revenueBetween($yesterday->copy()->startOfDay(), $yesterday->copy()->endOfDay(), $user, $isSuperAdmin);

        $monthRevenue = $this->revenueBetween($monthStart, $monthEnd, $user, $isSuperAdmin);
        $lastMonthRevenue = $this->revenueBetween($lastMonthStart, $lastMonthEnd, $user, $isSuperAdmin);

        $monthCount = $this->countBetween($monthStart, $monthEnd, $user, $isSuperAdmin);
        $avgThisMonth = $monthCount > 0 ? $monthRevenue / $monthCount : 0;

        [$receivedThisMonth, $outstandingThisMonth] = $this->receivedAndOutstandingBetween($monthStart, $monthEnd, $user, $isSuperAdmin);

        return [
            Stat::make('Pendapatan Hari Ini', $this->formatRupiah($todayRevenue))
                ->description($this->changeDescription($todayRevenue, $yesterdayRevenue, 'dari kemarin'))
                ->descriptionIcon($this->changeIcon($todayRevenue, $yesterdayRevenue))
                ->color($this->changeColor($todayRevenue, $yesterdayRevenue))
                ->url(BookingResource::getUrl('index')),

            Stat::make('Pendapatan Bulan Ini', $this->formatRupiah($monthRevenue))
                ->description($this->changeDescription($monthRevenue, $lastMonthRevenue, 'dari bulan lalu'))
                ->descriptionIcon($this->changeIcon($monthRevenue, $lastMonthRevenue))
                ->color($this->changeColor($monthRevenue, $lastMonthRevenue))
                ->url(BookingResource::getUrl('index')),

            Stat::make('Rata-rata Nilai Booking', $this->formatRupiah($avgThisMonth))
                ->description("{$monthCount} booking tercatat bulan ini")
                ->descriptionIcon('heroicon-m-calculator')
                ->color('gray'),

            // Split "Sudah Diterima" vs "Piutang" — referensi "Penjualan
            // Terbayar"/"Penjualan Belum Dibayar" Majoo, diminta
            // 2026-09-08. Datanya SUDAH ADA (Booking::amount_received),
            // cuma belum pernah disorot di dashboard — penting supaya
            // piutang yang menumpuk kelihatan tanpa harus buka Penjualan
            // satu-satu. amount_received NULL dianggap lunas penuh (lihat
            // BookingPostingService, DEFAULT-nya SAMA), jadi piutang di
            // sini cuma dari booking yang eksplisit belum lunas semua.
            Stat::make('Sudah Diterima (Bulan Ini)', $this->formatRupiah($receivedThisMonth))
                ->description('Dari Pendapatan Bulan Ini di atas')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success')
                ->url(BookingResource::getUrl('index')),

            Stat::make('Piutang (Bulan Ini)', $this->formatRupiah($outstandingThisMonth))
                ->description($outstandingThisMonth > 0 ? 'Belum lunas penuh — perlu ditagih' : 'Semua booking bulan ini lunas')
                ->descriptionIcon($outstandingThisMonth > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($outstandingThisMonth > 0 ? 'danger' : 'gray')
                ->url(BookingResource::getUrl('index')),
        ];
    }

    /**
     * @param  \Illuminate\Support\Carbon  $start
     * @param  \Illuminate\Support\Carbon  $end
     */
    private function revenueBetween(Carbon $start, Carbon $end, $user, bool $isSuperAdmin): float
    {
        $query = Booking::query()
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()]))
            ->where('transaction_amount', '>', 0);

        if (! $isSuperAdmin) {
            $query->where(function ($q) use ($user) {
                $q->where('store_id', $user->store_id)
                    ->orWhereNull('store_id');
            });
        }

        return (float) $query->sum('transaction_amount');
    }

    private function countBetween(Carbon $start, Carbon $end, $user, bool $isSuperAdmin): int
    {
        $query = Booking::query()
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()]))
            ->where('transaction_amount', '>', 0);

        if (! $isSuperAdmin) {
            $query->where(function ($q) use ($user) {
                $q->where('store_id', $user->store_id)
                    ->orWhereNull('store_id');
            });
        }

        return $query->count();
    }

    /**
     * @return array{0: float, 1: float} [diterima, piutang]
     *
     * Dihitung di PHP (bukan SQL SUM/COALESCE) — jumlah baris per bulan
     * kecil, dan ini menghindari duplikasi asumsi "NULL = lunas penuh"
     * antara sini & BookingPostingService/SalesResource kalau logikanya
     * berubah nanti (satu tempat PHP, bukan expression SQL terpisah yang
     * gampang lupa disinkronkan).
     */
    private function receivedAndOutstandingBetween(Carbon $start, Carbon $end, $user, bool $isSuperAdmin): array
    {
        $query = Booking::query()
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()]))
            ->where('transaction_amount', '>', 0);

        if (! $isSuperAdmin) {
            $query->where(function ($q) use ($user) {
                $q->where('store_id', $user->store_id)
                    ->orWhereNull('store_id');
            });
        }

        $received = 0.0;
        $outstanding = 0.0;

        $query->get(['transaction_amount', 'amount_received'])->each(function (Booking $booking) use (&$received, &$outstanding) {
            $amount = (float) $booking->transaction_amount;
            $receivedAmount = $booking->amount_received !== null ? (float) $booking->amount_received : $amount;

            $received += $receivedAmount;
            $outstanding += max(0, $amount - $receivedAmount);
        });

        return [$received, $outstanding];
    }

    private function formatRupiah(float $amount): string
    {
        return 'Rp' . number_format($amount, 0, ',', '.');
    }

    /**
     * Persentase perubahan gaya Majoo ("↓92.88% dari bulan lalu") —
     * periode sebelumnya 0 dianggap tidak ada pembanding (bukan
     * "naik ~tak terhingga%") supaya tidak menyesatkan.
     */
    private function changeDescription(float $current, float $previous, string $label): string
    {
        if ($previous <= 0) {
            return $current > 0 ? "Belum ada pembanding {$label}" : "Belum ada data {$label}";
        }

        $percent = (($current - $previous) / $previous) * 100;
        $arrow = $percent >= 0 ? '↑' : '↓';

        return sprintf('%s%s%% %s', $arrow, number_format(abs($percent), 2, ',', '.'), $label);
    }

    private function changeIcon(float $current, float $previous): string
    {
        if ($previous <= 0) {
            return 'heroicon-m-minus';
        }

        return $current >= $previous ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down';
    }

    private function changeColor(float $current, float $previous): string
    {
        if ($previous <= 0) {
            return 'gray';
        }

        return $current >= $previous ? 'success' : 'danger';
    }
}
