<?php

namespace App\Services;

use App\Models\Spk;
use App\Models\Technician;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Komisi Bertingkat berbasis Durasi Layanan Selesai" — temuan
 * PRIORITAS TINGGI dari audit Majoo vs Ginnva, dibangun 2026-09-22.
 *
 * Majoo mendefinisikan durasi STANDAR per jenis layanan (field "Durasi
 * menit" di master Produk Layanan) lalu mengakumulasi target dari situ
 * — tapi Ginnva BELUM membangun tipe produk "Layanan" itu (masih
 * pertanyaan bisnis terbuka, lihat project_penjualan_majoo_blocked_items).
 * Atas keputusan user 2026-09-22, service ini SENGAJA pakai basis data
 * yang BERBEDA dan sudah ada: durasi AKTUAL per job dari
 * `Spk::checked_in_at`/`checked_out_at` (waktu kendaraan masuk-keluar
 * bengkel) — lebih akurat daripada estimasi standar per produk, dan
 * tidak perlu menunggu keputusan bisnis yang masih pending itu.
 *
 * INI CUMA LAPORAN AKUMULASI — tidak ada perhitungan tier/nominal
 * komisi progresif di sini. Skema tier (mis. "0-40 jam = flat, >40 jam
 * = +Rp X/jam") BELUM diputuskan user, sengaja tidak ditebak. Laporan
 * ini menyiapkan angka akumulasi durasi per teknisi per periode yang
 * nanti jadi dasar hitung tier begitu skemanya sudah diputuskan.
 *
 * KETERBATASAN: SPK tidak punya kolom teknisi sendiri — atribusi
 * durasi diambil dari `Booking::installers()` (bisa >1 orang per job,
 * tim instalasi). Kalau 1 job dikerjakan tim, durasi PENUH dikreditkan
 * ke SETIAP installer (tidak dibagi rata) — sama konvensi dengan
 * TechnicianUtilizationService untuk estimasi jam job.
 */
class TechnicianServiceDurationService
{
    /**
     * @return Collection<int, array{
     *   technician_id: int, name: string, store_name: ?string,
     *   total_minutes: int, total_hours: float, job_count: int,
     *   avg_minutes_per_job: int, has_account: bool
     * }>
     */
    public function summarize(Carbon $from, Carbon $to, ?int $storeId = null): Collection
    {
        $technicians = Technician::query()
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->where('status', 'active')
            ->with('store')
            ->orderBy('name')
            ->get();

        $userIds = $technicians->pluck('user_id')->filter()->unique();

        $spks = Spk::query()
            ->whereNotNull('checked_in_at')
            ->whereNotNull('checked_out_at')
            ->whereBetween('checked_in_at', [$from, $to])
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->with('booking.installers')
            ->get();

        $minutesByUser = [];
        $jobsByUser = [];

        foreach ($spks as $spk) {
            $minutes = $spk->checked_in_at->diffInMinutes($spk->checked_out_at);

            if ($minutes <= 0) {
                continue;
            }

            $installers = $spk->booking?->installers ?? collect();

            foreach ($installers as $installer) {
                if (! $userIds->contains($installer->id)) {
                    continue;
                }

                $minutesByUser[$installer->id] = ($minutesByUser[$installer->id] ?? 0) + $minutes;
                $jobsByUser[$installer->id] = ($jobsByUser[$installer->id] ?? 0) + 1;
            }
        }

        return $technicians->map(function (Technician $technician) use ($minutesByUser, $jobsByUser) {
            $minutes = $minutesByUser[$technician->user_id] ?? 0;
            $jobs = $jobsByUser[$technician->user_id] ?? 0;

            return [
                'technician_id' => $technician->id,
                'name' => $technician->name,
                'store_name' => $technician->store?->name,
                'total_minutes' => $minutes,
                'total_hours' => round($minutes / 60, 1),
                'job_count' => $jobs,
                'avg_minutes_per_job' => $jobs > 0 ? (int) round($minutes / $jobs) : 0,
                'has_account' => $technician->user_id !== null,
            ];
        })->sortByDesc('total_minutes')->values();
    }
}
