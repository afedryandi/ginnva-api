<?php

namespace App\Services;

use App\Models\Spk;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Laporan Proses Order" (audit Majoo, f12: "laporan durasi pengerjaan
 * per job/teknisi/jenis layanan"), dibangun 2026-09-22. Level baris = 1
 * JOB (1 SPK), beda dari App\Services\TechnicianServiceDurationService
 * (level akumulasi per teknisi, untuk persiapan skema komisi bertingkat).
 *
 * Sumber durasi SAMA (Spk::checked_in_at/checked_out_at — waktu
 * kendaraan masuk-keluar bengkel), dipakai ulang di sini untuk sudut
 * pandang operasional per-job: "job mana yang paling lama/tercepat,
 * dikerjakan siapa, jenis layanan apa" — bukan untuk kalkulasi komisi.
 *
 * "Jenis Layanan" diambil dari flag produk Booking terkait
 * (product_ppf/product_kaca_film/product_detailing/product_premium_wash).
 * booking_id di Spk WAJIB & unique (lihat migrasi create_spks_table),
 * jadi `$booking` null hanya bisa terjadi kalau booking induknya
 * terhapus — fallback "Tidak diketahui" murni jaring pengaman, bukan
 * kasus yang diharapkan terjadi.
 */
class JobDurationService
{
    /**
     * @return Collection<int, array{
     *   spk_id: int, spk_number: string, date: Carbon, store_name: ?string,
     *   customer_name: string, services: list<string>, technicians: list<string>,
     *   minutes: int
     * }>
     */
    public function jobs(Carbon $from, Carbon $to, ?int $storeId = null): Collection
    {
        return Spk::query()
            ->whereNotNull('checked_in_at')
            ->whereNotNull('checked_out_at')
            ->whereBetween('checked_in_at', [$from, $to])
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->with(['store:id,name', 'booking:id,product_kaca_film,product_ppf,product_detailing,product_premium_wash', 'booking.installers:id,name'])
            ->orderByDesc('checked_in_at')
            ->get()
            ->map(function (Spk $spk) {
                $booking = $spk->booking;

                $services = [];
                if ($booking?->product_ppf) $services[] = 'PPF';
                if ($booking?->product_kaca_film) $services[] = 'Kaca Film';
                if ($booking?->product_detailing) $services[] = 'Detailing';
                if ($booking?->product_premium_wash) $services[] = 'Premium Wash';
                if (empty($services)) $services[] = $booking ? 'Tidak ditandai' : 'Tidak diketahui (tanpa booking)';

                return [
                    'spk_id' => $spk->id,
                    'spk_number' => $spk->spk_number,
                    'date' => $spk->checked_in_at,
                    'store_name' => $spk->store?->name,
                    'customer_name' => $spk->customer_name,
                    'services' => $services,
                    'technicians' => $booking?->installers?->pluck('name')->all() ?? [],
                    'minutes' => $spk->checked_out_at->diffInMinutes($spk->checked_in_at),
                ];
            });
    }
}
