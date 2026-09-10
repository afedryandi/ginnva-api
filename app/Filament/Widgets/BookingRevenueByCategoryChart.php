<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\BookingResource;
use App\Models\Booking;
use Filament\Widgets\ChartWidget;

/**
 * "Penjualan per Kategori" ala Majoo (dashboard.majoo.id/sales-dashboard)
 * — breakdown pendapatan bulan ini per jenis produk (Kaca Film vs PPF).
 * Diminta 2026-09-08, pasangan BookingRevenueStatsWidget.
 *
 * Booking BISA punya product_kaca_film DAN product_ppf sekaligus tapi
 * cuma 1 transaction_amount (tidak ada rincian per produk di kolom) —
 * split 50/50 untuk baris begitu SENGAJA disamakan PERSIS dengan logika
 * BookingPostingService (jurnal Pendapatan sungguhan di Keuangan: akun
 * 4100 PPF & 4200 Kaca Film, 50/50 kalau dua-duanya true), supaya
 * breakdown di chart ini TIDAK PERNAH menyimpang dari angka yang
 * sebenarnya tercatat di Jurnal Umum.
 */
class BookingRevenueByCategoryChart extends ChartWidget
{
    protected static ?string $heading = 'Pendapatan per Kategori (Bulan Ini)';

    protected static ?int $sort = 2;

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

        $start = now()->startOfMonth()->toDateString();
        $end = now()->endOfMonth()->toDateString();

        $query = Booking::query()
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$start, $end]))
            ->where('transaction_amount', '>', 0);

        if (! $isSuperAdmin) {
            // store_id di bookings NOT NULL (migrasi create_bookings_table)
            // — orWhereNull() dulu itu dead code + bocor angka toko lain.
            $query->where('store_id', $user->store_id);
        }

        // Split 50/50 (booking PPF + Kaca Film sekaligus) dihitung di SQL.
        // Booking tanpa product_ppf/product_kaca_film sengaja tidak masuk
        // kategori mana pun (ELSE 0) — bukan dipaksa ke salah satu.
        $agg = $query->selectRaw(
            'COALESCE(SUM(CASE'
            . ' WHEN product_ppf = 1 AND product_kaca_film = 1 THEN transaction_amount / 2'
            . ' WHEN product_kaca_film = 1 THEN transaction_amount ELSE 0 END), 0) as kaca_film,'
            . ' COALESCE(SUM(CASE'
            . ' WHEN product_ppf = 1 AND product_kaca_film = 1 THEN transaction_amount / 2'
            . ' WHEN product_ppf = 1 THEN transaction_amount ELSE 0 END), 0) as ppf'
        )->toBase()->first();

        $kacaFilmTotal = (float) $agg->kaca_film;
        $ppfTotal = (float) $agg->ppf;

        return [
            'datasets' => [
                [
                    'label' => 'Pendapatan',
                    'data' => [round($kacaFilmTotal), round($ppfTotal)],
                    'backgroundColor' => ['#3b82f6', '#ED1651'],
                    'borderRadius' => 6,
                ],
            ],
            'labels' => ['Kaca Film', 'PPF'],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'x' => [
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                    'grid' => ['color' => 'rgba(148, 163, 184, 0.12)'],
                ],
                'y' => [
                    'grid' => ['display' => false],
                ],
            ],
        ];
    }
}
