<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\PromoLoyaltyReport;
use App\Models\VoucherClaim;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * "Grafik Promo" — diminta 2026-09-09, analog Majoo. 1 garis: total
 * nilai voucher dipakai per hari (used_at, status=used, terhubung
 * booking).
 *
 * SINKRON dengan PromoLoyaltyReport (audit 2026-09-11, temuan A) —
 * SEBELUMNYA widget ini punya filter sendiri (14/30/90 hari terakhir),
 * terputus dari form Dari/Sampai di halamannya. Sekarang menerima
 * $from/$to/$storeId lewat mount() (dipanggil @livewire(..., ['from'=>...])
 * dari blade halaman, BUKAN <x-filament-widgets::widgets> — pola sama
 * yang sudah terbukti jalan di chart Penjualan lain).
 */
class PromoValueChart extends ChartWidget
{
    protected static ?string $heading = 'Grafik Promo';

    protected static ?string $pollingInterval = null;

    public ?string $from = null;

    public ?string $to = null;

    public ?int $storeId = null;

    public function mount(?string $from = null, ?string $to = null, ?int $storeId = null): void
    {
        $this->from = $from;
        $this->to = $to;
        $this->storeId = $storeId;
    }

    public static function canView(): bool
    {
        return PromoLoyaltyReport::canAccess();
    }

    protected function getData(): array
    {
        $start = $this->from ? Carbon::parse($this->from)->startOfDay() : now()->subDays(29)->startOfDay();
        $end = $this->to ? Carbon::parse($this->to)->endOfDay() : now()->endOfDay();

        $claims = VoucherClaim::query()
            ->where('status', 'used')
            ->whereNotNull('booking_id')
            ->whereBetween('used_at', [$start, $end])
            ->when($this->storeId, fn ($q) => $q->whereHas('booking', fn ($q2) => $q2->where('store_id', $this->storeId)))
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
