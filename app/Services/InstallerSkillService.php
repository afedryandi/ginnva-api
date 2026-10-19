<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingServiceType;
use App\Models\TechnicianServiceRate;
use App\Models\TechnicianSkill;

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
