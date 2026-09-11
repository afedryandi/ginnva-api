<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\ReservationReport;
use App\Models\Booking;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * "Grafik Performa Reservasi" — diminta 2026-09-09, analog Majoo. 2
 * garis: Dibuat (semua booking diajukan hari itu, created_at) vs
 * Dibatalkan (yang statusnya jadi 'cancelled', diajukan hari itu).
 *
 * SINKRON dengan ReservationReport (audit 2026-09-11, temuan A) —
 * SEBELUMNYA widget ini punya filter sendiri (14/30/90 hari terakhir),
 * terputus dari form Dari/Sampai di halamannya. Sekarang menerima
 * $from/$to/$storeId lewat mount() (dipanggil @livewire(..., ['from'=>...])
 * dari blade halaman, BUKAN <x-filament-widgets::widgets> — pola sama
 * yang sudah terbukti jalan di chart Penjualan lain).
 */
class ReservationPerformanceChart extends ChartWidget
{
    protected static ?string $heading = 'Grafik Performa Reservasi';

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
        return ReservationReport::canAccess();
    }

    protected function getData(): array
    {
        $start = $this->from ? Carbon::parse($this->from)->startOfDay() : now()->subDays(29)->startOfDay();
        $end = $this->to ? Carbon::parse($this->to)->endOfDay() : now()->endOfDay();

        $bookings = Booking::query()
            ->whereBetween('created_at', [$start, $end])
            ->when($this->storeId, fn ($q) => $q->where('store_id', $this->storeId))
            ->get(['created_at', 'status']);

        $createdByDate = [];
        $cancelledByDate = [];
        foreach ($bookings as $booking) {
            $date = $booking->created_at->toDateString();
            $createdByDate[$date] = ($createdByDate[$date] ?? 0) + 1;
            if ($booking->status === 'cancelled') {
                $cancelledByDate[$date] = ($cancelledByDate[$date] ?? 0) + 1;
            }
        }

        $labels = [];
        $createdData = [];
        $cancelledData = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $labels[] = $cursor->format('d M');
            $createdData[] = $createdByDate[$key] ?? 0;
            $cancelledData[] = $cancelledByDate[$key] ?? 0;
            $cursor->addDay();
        }

        return [
            'datasets' => [
                [
                    'label' => 'Dibuat',
                    'data' => $createdData,
                    'borderColor' => '#2563eb',
                    'backgroundColor' => 'rgba(37, 99, 235, 0.1)',
                    'pointRadius' => 2,
                    'tension' => 0.3,
                    'fill' => true,
                ],
                [
                    'label' => 'Dibatalkan',
                    'data' => $cancelledData,
                    'borderColor' => '#dc2626',
                    'backgroundColor' => 'transparent',
                    'pointRadius' => 2,
                    'tension' => 0.3,
                    'fill' => false,
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
