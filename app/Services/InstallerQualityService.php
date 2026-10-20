<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingQcResult;
use App\Models\BookingRework;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Mutu pekerjaan per installer untuk PEMBINAAN (Karyawan Fase 4, 2026-10-31): dari booking selesai pada rentang tanggal, berapa yang
 * lulus Quality Check pada pemeriksaan pertama dan berapa yang pernah gagal QC (dianggap perlu dikerjakan ulang). Bukan dasar potongan
 * gaji atau komisi (keputusan rancangan: mutu hanya untuk pembinaan). Pada booking tim, tiap installer bertugas dihitung sama.
 *
 * Catatan angka: sampel kecil menyesatkan, jadi laporan menampilkan jumlah pekerjaan di samping persentase, dan persentase baru dihitung
 * dari pekerjaan yang sudah diperiksa QC.
 */
class InstallerQualityService
{
    /**
     * @return Collection<int, array{user_id: int, name: string, store: ?string, jobs: int, checked: int, first_pass: int, reworked: int, rework_recorded: int, first_pass_rate: ?float}>
     */
    public function forPeriod(Carbon $from, Carbon $to, ?int $storeId = null): Collection
    {
        $bookings = Booking::query()
            ->where('status', 'completed')
            ->whereDate('preferred_date', '>=', $from->toDateString())
            ->whereDate('preferred_date', '<=', $to->toDateString())
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->whereHas('installers')
            ->with(['installers:id,name,store_id', 'installers.store:id,name'])
            ->get(['id', 'store_id', 'preferred_date', 'status']);

        if ($bookings->isEmpty()) {
            return collect();
        }

        $results = BookingQcResult::query()
            ->whereIn('booking_id', $bookings->pluck('id'))
            ->orderBy('id')
            ->get(['id', 'booking_id', 'passed'])
            ->groupBy('booking_id');

        // Booking dengan catatan Pekerjaan Ulang (di luar QC gagal): keluhan pelanggan dan temuan internal ikut terhitung.
        $reworkBookingIds = BookingRework::withoutGlobalScopes()->whereIn('booking_id', $bookings->pluck('id'))->pluck('booking_id')->flip();

        $rows = [];

        foreach ($bookings as $booking) {
            $checks = $results->get($booking->id, collect());
            $hasQc = $checks->isNotEmpty();
            $firstPassed = $hasQc && $checks->first()->passed;
            $everFailed = $checks->contains(fn ($r) => ! $r->passed);

            foreach ($booking->installers as $installer) {
                $row = $rows[$installer->id] ?? [
                    'user_id' => $installer->id,
                    'name' => $installer->name,
                    'store' => $installer->store?->name,
                    'jobs' => 0, 'checked' => 0, 'first_pass' => 0, 'reworked' => 0, 'rework_recorded' => 0,
                ];

                $row['jobs']++;
                $row['checked'] += $hasQc ? 1 : 0;
                $row['first_pass'] += $firstPassed ? 1 : 0;
                $row['reworked'] += $everFailed ? 1 : 0;
                $row['rework_recorded'] += $reworkBookingIds->has($booking->id) ? 1 : 0;
                $rows[$installer->id] = $row;
            }
        }

        return collect($rows)
            ->map(fn (array $row) => $row + ['first_pass_rate' => $row['checked'] > 0 ? round($row['first_pass'] / $row['checked'] * 100, 1) : null])
            ->sortBy('name')
            ->values();
    }
}
