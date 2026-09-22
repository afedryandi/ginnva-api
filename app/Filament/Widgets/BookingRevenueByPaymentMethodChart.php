<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\BookingResource;
use App\Models\Booking;
use Filament\Widgets\ChartWidget;

/**
 * "Breakdown Metode Pembayaran" (audit Majoo, f3) — pasangan
 * BookingRevenueByCategoryChart, breakdown pendapatan bulan ini per
 * kanal pembayaran (Tunai/Transfer/QRIS/EDC) alih-alih per produk.
 * Berguna untuk rekonsiliasi kas/rekening (berapa yang harusnya masuk
 * rekening bank vs ada di laci kas).
 *
 * `payment_method` BARU ditambahkan (migrasi 2026_09_22_000009) dan
 * OPSIONAL -- booking lama/yang belum diisi staff dikelompokkan
 * "Belum Diisi", BUKAN dihilangkan dari total (pola sama seperti
 * ProductSalesReport untuk film_product_id yang kosong).
 */
class BookingRevenueByPaymentMethodChart extends ChartWidget
{
    protected static ?string $heading = 'Pendapatan per Metode Pembayaran (Bulan Ini)';

    // Setelah BookingRevenueByCategoryChart (sort 3) -- lihat catatan
    // sistem sort di widget itu.
    protected static ?int $sort = 4;

    protected static ?string $pollingInterval = null;

    public ?int $storeId = null;

    public function mount(?int $storeId = null): void
    {
        $this->storeId = $storeId;
    }

    public static function canView(): bool
    {
        $user = auth()->user();

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
            $query->where('store_id', $user->store_id);
        } elseif ($this->storeId) {
            $query->where('store_id', $this->storeId);
        }

        $totals = $query->selectRaw('payment_method, COALESCE(SUM(transaction_amount), 0) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        $labels = Booking::PAYMENT_METHOD_LABELS;
        $labels['belum_diisi'] = 'Belum Diisi';

        $data = [];
        $chartLabels = [];
        foreach ($labels as $key => $label) {
            $value = $key === 'belum_diisi' ? (float) ($totals[null] ?? $totals[''] ?? 0) : (float) ($totals[$key] ?? 0);

            if ($value <= 0) {
                continue;
            }

            $chartLabels[] = $label;
            $data[] = round($value);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Pendapatan',
                    'data' => $data,
                    'backgroundColor' => ['#3b82f6', '#22c55e', '#ED1651', '#a855f7', '#9ca3af'],
                    'borderRadius' => 6,
                ],
            ],
            'labels' => $chartLabels,
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
