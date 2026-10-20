<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingServiceType;
use App\Models\TechnicianServiceRate;
use App\Models\TechnicianSkill;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Peringatan kecocokan keterampilan installer dengan layanan sebuah booking (Karyawan Fase 4, 2026-10-31). HANYA PERINGATAN, tidak
 * memblokir (konsisten dengan keputusan "peringatan saja" untuk ketersediaan installer).
 *
 * Aturan: untuk tiap layanan pada booking, harus ada minimal satu installer bertugas yang tingkatnya Mandiri atau Mahir. Installer
 * yang belum punya satu pun baris matriks dianggap belum dinilai dan diabaikan (matriks mungkin belum diisi), jadi peringatan baru
 * muncul bila ada installer bertugas yang SUDAH dinilai namun tidak ada yang mandiri untuk layanan itu.
 */
class InstallerSkillService
{
    /**
     * Saran installer untuk sebuah booking (Karyawan Fase 4 lanjutan, 2026-11-03). HANYA SARAN, tidak menugaskan otomatis (keputusan
     * "peringatan saja" untuk penugasan tetap berlaku). Kandidat: installer aktif toko booking yang masuk kerja pada tanggal booking.
     * Urutan: yang Mandiri di SEMUA layanan booking dulu, lalu yang mencakup paling banyak layanan, lalu yang jobnya pada tanggal itu
     * paling sedikit; installer yang belum dinilai sama sekali di paling bawah dan ditandai.
     *
     * @return Collection<int, array{user: User, covered: list<string>, missing: list<string>, jobs: int, assessed: bool, full: bool}>
     */
    public function suggestFor(Booking $booking): Collection
    {
        $services = array_values(array_filter($booking->serviceKeys(), fn (string $key) => isset(TechnicianServiceRate::SERVICE_TYPE_LABELS[$key])));
        $date = $booking->preferred_date;

        $unavailable = $date ? app(InstallerAvailabilityService::class)->unavailableReasons((int) $booking->store_id, $date->copy()) : [];

        $candidates = User::query()
            ->where('store_id', $booking->store_id)
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['installer', 'installer_leader']))
            ->whereDoesntHave('technician', fn ($q) => $q->whereIn('status', ['pending_review', 'inactive']))
            ->with('technician.skills')
            ->get()
            ->reject(fn (User $user) => isset($unavailable[$user->id]));

        $jobs = $date
            ? \Illuminate\Support\Facades\DB::table('booking_installers')
                ->join('bookings', 'bookings.id', '=', 'booking_installers.booking_id')
                ->where('bookings.status', 'confirmed')
                ->whereDate('bookings.preferred_date', $date->toDateString())
                ->where('bookings.id', '!=', $booking->id)
                ->selectRaw('booking_installers.user_id as user_id, count(*) as total')
                ->groupBy('booking_installers.user_id')
                ->pluck('total', 'user_id')
            : collect();

        return $candidates->map(function (User $user) use ($services, $jobs) {
            $skills = $user->technician?->skills ?? collect();
            $covered = array_values(array_filter($services, fn (string $key) => ($skills->firstWhere('service_type', $key)?->level ?? 0) >= TechnicianSkill::INDEPENDENT));

            return [
                'user' => $user,
                'covered' => $covered,
                'missing' => array_values(array_diff($services, $covered)),
                'jobs' => (int) ($jobs[$user->id] ?? 0),
                'assessed' => $skills->isNotEmpty(),
                'full' => $services !== [] && count($covered) === count($services),
            ];
        })
            ->sort(function (array $a, array $b) {
                return [! $a['full'], ! $a['assessed'], -count($a['covered']), $a['jobs'], $a['user']->name]
                    <=> [! $b['full'], ! $b['assessed'], -count($b['covered']), $b['jobs'], $b['user']->name];
            })
            ->values();
    }

    /** @return list<string> satu kalimat per layanan yang belum tercakup */
    public function warningsFor(Booking $booking): array
    {
        $installers = $booking->installers()->with('technician.skills')->get();

        $assessed = $installers->filter(fn ($user) => $user->technician && $user->technician->skills->isNotEmpty());

        if ($assessed->isEmpty()) {
            return [];
        }

        $warnings = [];

        foreach ($booking->serviceKeys() as $key) {
            if (! isset(TechnicianServiceRate::SERVICE_TYPE_LABELS[$key])) {
                continue;
            }

            $covered = $assessed->contains(function ($user) use ($key) {
                $skill = $user->technician->skills->firstWhere('service_type', $key);

                return $skill && $skill->level >= TechnicianSkill::INDEPENDENT;
            });

            if (! $covered) {
                $label = BookingServiceType::labelFor($key) ?? TechnicianServiceRate::SERVICE_TYPE_LABELS[$key];
                $warnings[] = "Belum ada installer bertugas yang Mandiri untuk {$label}.";
            }
        }

        return $warnings;
    }
}
