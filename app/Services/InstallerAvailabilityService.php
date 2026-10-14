<?php

namespace App\Services;

use App\Models\EmployeeScheduleAssignment;
use App\Models\LeaveRequest;
use App\Models\ScheduleDayOverride;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Support\Carbon;

/**
 * Ketersediaan installer per toko per tanggal (Fase 2 pengerjaan ulang booking, 2026-10-16). HANYA INFORMASI/PERINGATAN:
 * tidak memblokir konfirmasi dan tidak mengubah kapasitas toko (keputusan pemilik: "peringatan saja").
 *
 * Installer = user aktif berperan installer / installer_leader di toko itu. Tidak tersedia pada tanggal itu bila:
 *  - punya Izin & Cuti berstatus 'approved' yang mencakup tanggal itu, ATAU
 *  - override jadwal 1 hari tanpa shift (Libur), ATAU
 *  - tanpa override, jadwal kerja yang berlaku menandai hari itu libur.
 * Installer yang belum punya penugasan jadwal kerja dianggap tersedia (jadwalnya tidak diketahui, bukan libur).
 */
class InstallerAvailabilityService
{
    /** @return array{total: int, available: int, unavailable: array<int, array{name: string, reason: string}>} */
    public function forDate(int $storeId, Carbon $date): array
    {
        $installers = User::query()
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->role(['installer', 'installer_leader'])
            ->get(['id', 'name']);

        if ($installers->isEmpty()) {
            return ['total' => 0, 'available' => 0, 'unavailable' => []];
        }

        $ids = $installers->pluck('id');
        $day = $date->toDateString();

        $onLeave = LeaveRequest::query()
            ->whereIn('user_id', $ids)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $day)
            ->whereDate('end_date', '>=', $day)
            ->pluck('user_id')
            ->flip();

        $overrides = ScheduleDayOverride::query()
            ->whereIn('user_id', $ids)
            ->whereDate('date', $day)
            ->get()
            ->keyBy('user_id');

        $dayCode = WorkSchedule::DAYS[$date->dayOfWeekIso - 1];
        $unavailable = [];

        foreach ($installers as $installer) {
            $reason = null;

            if ($onLeave->has($installer->id)) {
                $reason = 'Cuti/izin';
            } elseif ($overrides->has($installer->id)) {
                $reason = $overrides[$installer->id]->shift_id ? null : 'Libur (jadwal)';
            } else {
                $assignment = EmployeeScheduleAssignment::activeFor($installer->id, $date);

                if ($assignment?->workSchedule && ! $assignment->workSchedule->shiftIdFor($dayCode)) {
                    $reason = 'Libur (jadwal)';
                }
            }

            if ($reason) {
                $unavailable[] = ['name' => $installer->name, 'reason' => $reason];
            }
        }

        return [
            'total' => $installers->count(),
            'available' => $installers->count() - count($unavailable),
            'unavailable' => $unavailable,
        ];
    }
}
