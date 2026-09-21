<?php

namespace App\Services;

use App\Models\Store;
use App\Models\Technician;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Metrik Utilisasi Teknisi" — temuan PRIORITAS TINGGI dari audit Majoo vs
 * Ginnva (docs/audit-majoo-vs-ginnva.md, section "Analisa Laporan / Waktu
 * Teramai Produk"): % jam kerja teknisi yang benar-benar terpakai
 * mengerjakan job vs idle/nganggur, menyilangkan data Absensi (jam hadir
 * aktual) dengan Booking (beban kerja yang ditugaskan).
 *
 * PENTING — ini APROKSIMASI, bukan pengukuran presisi jam kerja aktual per
 * job:
 * - "Jam Hadir" = sungguhan, dari selisih clock_in_at/clock_out_at
 *   Attendance (data GPS/waktu asli).
 * - "Jam Job" = ESTIMASI, dari `Booking::duration_days` (hari kerja yang
 *   direncanakan saat booking dibuat) dikali jam operasional toko per hari
 *   (Store::openingTimeOn/closingTimeOn). Booking TIDAK punya timestamp
 *   mulai/selesai kerja yang sesungguhnya (tidak ada "jam mulai pasang jam
 *   9, selesai jam 2"), jadi ini yang paling dekat bisa dihitung dari data
 *   yang ada sekarang. Kalau ke depannya SPK/booking mencatat jam mulai-
 *   selesai riil per job, service ini harus diperbarui untuk pakai data
 *   itu (jauh lebih akurat daripada estimasi duration_days).
 * - Utilisasi > 100% artinya BUKAN bug — itu sinyal legitimate bahwa
 *   estimasi jam job (dari duration_days) melebihi jam hadir tercatat,
 *   kemungkinan karena job dikerjakan di luar jam kerja normal, dikerjakan
 *   tim (installer utama + asisten yang tidak tercatat di sini), atau
 *   duration_days yang dicatat lebih besar dari kenyataan.
 */
class TechnicianUtilizationService
{
    /**
     * @return Collection<int, array{
     *   technician_id: int, name: string, store_name: ?string,
     *   present_hours: ?float, job_hours: float, idle_hours: ?float,
     *   utilization_percent: ?float, has_account: bool
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

        $userIds = $technicians->pluck('user_id')->filter()->unique()->values();

        $presentSeconds = DB::table('attendances')
            ->whereIn('user_id', $userIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('clock_in_at')
            ->whereNotNull('clock_out_at')
            ->selectRaw('user_id, SUM(TIMESTAMPDIFF(SECOND, clock_in_at, clock_out_at)) as total_seconds')
            ->groupBy('user_id')
            ->pluck('total_seconds', 'user_id');

        $bookingRows = DB::table('booking_installers')
            ->join('bookings', 'bookings.id', '=', 'booking_installers.booking_id')
            ->whereIn('booking_installers.user_id', $userIds)
            ->where('bookings.status', 'completed')
            ->whereBetween('bookings.preferred_date', [$from->toDateString(), $to->toDateString()])
            ->select('booking_installers.user_id', 'bookings.store_id', 'bookings.preferred_date', 'bookings.duration_days')
            ->get();

        $storesById = Store::query()
            ->whereIn('id', $bookingRows->pluck('store_id')->unique())
            ->get()
            ->keyBy('id');

        $jobHoursByUser = [];
        foreach ($bookingRows as $row) {
            $store = $storesById->get($row->store_id);
            $dailyHours = $this->dailyStandardHours($store, Carbon::parse($row->preferred_date));
            $jobHoursByUser[$row->user_id] = ($jobHoursByUser[$row->user_id] ?? 0)
                + (int) ($row->duration_days ?? 1) * $dailyHours;
        }

        return $technicians->map(function (Technician $technician) use ($presentSeconds, $jobHoursByUser) {
            $hasAccount = $technician->user_id !== null;
            $presentHours = $hasAccount && isset($presentSeconds[$technician->user_id])
                ? round($presentSeconds[$technician->user_id] / 3600, 1)
                : ($hasAccount ? 0.0 : null);
            $jobHours = round($jobHoursByUser[$technician->user_id] ?? 0, 1);

            $utilizationPercent = ($presentHours !== null && $presentHours > 0)
                ? round(($jobHours / $presentHours) * 100, 1)
                : null;

            $idleHours = $presentHours !== null
                ? max(round($presentHours - $jobHours, 1), 0)
                : null;

            return [
                'technician_id' => $technician->id,
                'name' => $technician->name,
                'store_name' => $technician->store?->name,
                'present_hours' => $presentHours,
                'job_hours' => $jobHours,
                'idle_hours' => $idleHours,
                'utilization_percent' => $utilizationPercent,
                'has_account' => $hasAccount,
            ];
        })->values();
    }

    /**
     * Jam operasional toko pada $date (jam tutup − jam buka), fallback 8
     * jam kalau toko tidak punya jadwal untuk hari itu atau tidak
     * diketahui (mis. booking lama yang toko-nya sudah dihapus).
     */
    protected function dailyStandardHours(?Store $store, Carbon $date): float
    {
        if (! $store) {
            return 8.0;
        }

        $open = $store->openingTimeOn($date);
        $close = $store->closingTimeOn($date);

        if (! $open || ! $close) {
            return 8.0;
        }

        [$openH, $openM] = array_map('intval', explode(':', $open));
        [$closeH, $closeM] = array_map('intval', explode(':', $close));

        $hours = (($closeH * 60 + $closeM) - ($openH * 60 + $openM)) / 60;

        return $hours > 0 ? $hours : 8.0;
    }
}
