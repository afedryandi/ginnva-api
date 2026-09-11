<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\ReservationReport;
use App\Models\Booking;
use Filament\Widgets\ChartWidget;

/**
 * "Grafik Performa Reservasi" — diminta 2026-09-09, analog Majoo. 2
 * garis: Dibuat (semua booking diajukan hari itu, created_at) vs
 * Dibatalkan (yang statusnya jadi 'cancelled', diajukan hari itu).
 *
 * Filter rentang hari sendiri (getFilters() bawaan ChartWidget), sama
 * alasan dengan chart Penjualan lain -- widget & Page 2 komponen
 * Livewire terpisah.
 */
class ReservationPerformanceChart extends ChartWidget
{
    protected static ?string $heading = 'Grafik Performa Reservasi';

    public static function canView(): bool
    {
        return ReservationReport::canAccess();
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

        // BUG DIPERBAIKI 2026-09-11 (ditemukan saat audit Laporan
        // Reservasi): grafik ini SEBELUMNYA SAMA SEKALI TIDAK ADA
        // scoping toko — manajer toko manapun lihat "Dibuat vs
        // Dibatalkan" company-wide, walau halaman utamanya sudah benar.
        $user = auth()->user();
        $storeId = ($user?->isFullAccess() ?? false) ? null : $user?->store_id;

        $bookings = Booking::query()
            ->whereBetween('created_at', [$start, $end])
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
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
