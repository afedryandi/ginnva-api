<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\PromoLoyaltyReport;
use App\Models\VoucherClaim;
use Filament\Widgets\ChartWidget;

/**
 * "Grafik Promo" — diminta 2026-09-09, analog Majoo. 1 garis: total
 * nilai voucher dipakai per hari (used_at, status=used, terhubung
 * booking).
 *
 * Filter rentang hari sendiri (getFilters() bawaan ChartWidget), sama
 * alasan dengan chart Penjualan lain -- widget & Page 2 komponen
 * Livewire terpisah.
 */
class PromoValueChart extends ChartWidget
{
    protected static ?string $heading = 'Grafik Promo';

    public static function canView(): bool
    {
        return PromoLoyaltyReport::canAccess();
    }

    protected function getFilters(): ?array
    {
        return [
            '14' => '14 Hari Terakhir',
            '30' => '30 Hari Terakhir',
            '90' => '90 Hari Terakhir',
        ];
    }

    protected function getData(): array
    {
        $days = (int) ($this->filter ?? 30);
        $start = now()->subDays($days - 1)->startOfDay();
        $end = now()->endOfDay();

        $claims = VoucherClaim::query()
            ->where('status', 'used')
            ->whereNotNull('booking_id')
            ->whereBetween('used_at', [$start, $end])
            ->with('voucher:id,discount_amount')
            ->get(['id', 'used_at', 'voucher_id']);

        $byDate = [];
        foreach ($claims as $claim) {
            $date = $claim->used_at?->toDateString();
            if (! $date) continue;
            $byDate[$date] = ($byDate[$date] ?? 0) + (float) ($claim->voucher->discount_amount ?? 0);
        }

        $labels = [];
        $data = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $labels[] = $cursor->format('d M');
            $data[] = $byDate[$key] ?? 0;
            $cursor->addDay();
        }

        return [
            'datasets' => [
                [
                    'label' => 'Nilai Promo',
                    'data' => $data,
                    'borderColor' => '#16a34a',
                    'backgroundColor' => 'rgba(22, 163, 74, 0.1)',
                    'pointRadius' => 2,
                    'tension' => 0.3,
                    'fill' => true,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'grid' => ['color' => 'rgba(148, 163, 184, 0.12)'],
                ],
                'x' => [
                    'grid' => ['display' => false],
                ],
            ],
        ];
    }
}
