<?php

namespace App\Services;

use App\Models\Booking;
use Illuminate\Support\Carbon;

/**
 * Indikator Penjualan (Penjualan Fase 6, 2026-11-13): angka pelengkap Dasbor Penjualan yang menjawab "dari mana penjualan datang dan seberapa
 * baik": penjualan per jenis layanan, booking dengan lebih dari satu jenis layanan, pelanggan kembali, potongan yang diberikan, dan
 * penjualan per toko. Murni agregat dari booking yang SUDAH diproses ke Jurnal Umum (definisi sama dengan semua laporan Penjualan);
 * tidak menyimpan apa pun.
 *
 * Batas yang diakui di angka: nilai per jenis memakai pembagian yang sama dengan Jurnal Umum (rincian harga bila ada, bagi rata bila
 * tidak). Potongan lain selain promo/voucher hanya dapat dihitung untuk booking yang punya rincian harga; sisanya dilaporkan sebagai
 * "tanpa rincian" supaya angka tidak tampak lebih lengkap dari kenyataannya. Tidak ada target: target belum diputuskan pemilik.
 */
class SalesKpiService
{
    /**
     * @return array{
     *   count: int, revenue: float, avg_ticket: float,
     *   by_type: array<string, array{label: string, count: int, revenue: float, pct: float}>,
     *   multi_service: array{count: int, pct: float},
     *   customers: array{total: int, returning: int, pct: float},
     *   discounts: array{promo: float, voucher: float, other: float, total: float, pct_of_gross: float, without_lines: int},
     *   lines_coverage: array{with_lines: int, pct: float},
     *   by_store: list<array{name: string, count: int, revenue: float, avg: float}>
     * }
     */
    public function kpis(Carbon $start, Carbon $end, ?int $storeId): array
    {
        $bookings = Booking::query()
            ->soldBetween($start->toDateString(), $end->toDateString())
            ->where('transaction_amount', '>', 0)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->with(['saleLines', 'store:id,name'])
            ->get(['id', 'customer_id', 'store_id', 'transaction_amount', 'voucher_discount', 'spend_promo_discount', 'product_ppf', 'product_kaca_film', 'product_detailing', 'product_premium_wash']);

        $count = $bookings->count();
        $revenue = round((float) $bookings->sum('transaction_amount'), 2);

        $labels = ['ppf' => 'PPF', 'kaca_film' => 'Kaca Film', 'detailing' => 'Detailing', 'premium_wash' => 'Premium Wash', BookingRevenueSplitter::NO_PRODUCT => 'Tanpa jenis'];
        $byType = [];
        $multi = 0;
        $promo = $voucher = $other = $gross = 0.0;
        $withoutLines = $withLines = 0;

        foreach ($bookings as $booking) {
            $amount = (float) $booking->transaction_amount;

            foreach (BookingRevenueSplitter::shares($booking, $amount) as $key => $portion) {
                $byType[$key] ??= ['label' => $labels[$key] ?? $key, 'count' => 0, 'revenue' => 0.0, 'pct' => 0.0];
                $byType[$key]['count']++;
                $byType[$key]['revenue'] += $portion;
            }

            $types = $booking->saleLines->isNotEmpty()
                ? $booking->saleLines->pluck('service_type')->unique()->count()
                : count(BookingRevenueSplitter::productKeys($booking));
            if ($types > 1) {
                $multi++;
            }

            $bookingPromo = (float) ($booking->spend_promo_discount ?? 0);
            $bookingVoucher = (float) ($booking->voucher_discount ?? 0);
            $promo += $bookingPromo;
            $voucher += $bookingVoucher;

            if ($booking->saleLines->isNotEmpty()) {
                $withLines++;
                $lineGross = (float) $booking->saleLines->sum(fn ($l) => (float) $l->quantity * (float) $l->unit_price);
                $other += max(0.0, $lineGross - $amount - $bookingPromo - $bookingVoucher);
                $gross += $lineGross;
            } else {
                $withoutLines++;
                $gross += $amount + $bookingPromo + $bookingVoucher;
            }
        }

        foreach ($byType as $key => $row) {
            $byType[$key]['revenue'] = round($row['revenue'], 2);
            $byType[$key]['pct'] = $revenue > 0 ? round($row['revenue'] / $revenue * 100, 1) : 0.0;
        }
        uasort($byType, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        $customerIds = $bookings->pluck('customer_id')->filter()->unique()->values();
        $returning = $customerIds->isEmpty() ? 0 : Booking::withoutGlobalScopes()
            ->whereIn('customer_id', $customerIds)
            ->where('transaction_amount', '>', 0)
            ->soldBefore($start->toDateString())
            ->distinct()->pluck('customer_id')->count();

        $totalDiscount = round($promo + $voucher + $other, 2);

        return [
            'count' => $count,
            'revenue' => $revenue,
            'avg_ticket' => $count > 0 ? round($revenue / $count, 2) : 0.0,
            'by_type' => $byType,
            'multi_service' => ['count' => $multi, 'pct' => $count > 0 ? round($multi / $count * 100, 1) : 0.0],
            'customers' => ['total' => $customerIds->count(), 'returning' => $returning, 'pct' => $customerIds->count() > 0 ? round($returning / $customerIds->count() * 100, 1) : 0.0],
            'discounts' => [
                'promo' => round($promo, 2),
                'voucher' => round($voucher, 2),
                'other' => round($other, 2),
                'total' => $totalDiscount,
                'pct_of_gross' => $gross > 0 ? round($totalDiscount / $gross * 100, 1) : 0.0,
                'without_lines' => $withoutLines,
            ],
            'lines_coverage' => ['with_lines' => $withLines, 'pct' => $count > 0 ? round($withLines / $count * 100, 1) : 0.0],
            'by_store' => $bookings->groupBy(fn ($b) => $b->store?->name ?? 'Tanpa toko')
                ->map(fn ($group, $name) => [
                    'name' => $name,
                    'count' => $group->count(),
                    'revenue' => round((float) $group->sum('transaction_amount'), 2),
                    'avg' => round((float) $group->sum('transaction_amount') / max(1, $group->count()), 2),
                ])
                ->sortByDesc('revenue')->values()->all(),
        ];
    }
}
