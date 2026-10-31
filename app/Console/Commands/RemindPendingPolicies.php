<?php

namespace App\Console\Commands;

use App\Models\CompanyPolicy;
use App\Services\StaffNotifier;
use Illuminate\Console\Command;

/**
 * Pengingat mingguan ke karyawan aktif yang belum menerima dokumen peraturan/kebijakan wajib-terima yang sudah terbit (audit Peraturan &
 * Kebijakan, 2026-10-31). Satu pesan per karyawan berapa pun dokumennya; pesan lama yang belum dibuka diperbarui, bukan ditumpuk. Ketukan
 * pada pesan membuka layar Peraturan & Kebijakan di aplikasi.
 */
class RemindPendingPolicies extends Command
{
    protected $signature = 'policies:remind-pending';

    protected $description = 'Ingatkan karyawan yang belum menerima dokumen peraturan/kebijakan wajib-terima';

    public function handle(): int
    {
        $pendingByUser = [];

        foreach (CompanyPolicy::published()->where('requires_acknowledgment', true)->get() as $policy) {
            foreach ($policy->pendingUsers()->pluck('id') as $userId) {
                $pendingByUser[$userId] = ($pendingByUser[$userId] ?? 0) + 1;
            }
        }

        if ($pendingByUser === []) {
            $this->info('Semua karyawan sudah menerima dokumen wajib-terima.');

            return self::SUCCESS;
        }

        foreach ($pendingByUser as $userId => $count) {
            app(StaffNotifier::class)->toUsers(
                [$userId],
                'Dokumen Peraturan Belum Diterima',
                $count === 1
                    ? 'Ada 1 dokumen peraturan/kebijakan yang belum Anda terima. Buka Peraturan & Kebijakan untuk membaca dan menerimanya.'
                    : "Ada {$count} dokumen peraturan/kebijakan yang belum Anda terima. Buka Peraturan & Kebijakan untuk membaca dan menerimanya.",
                ['type' => 'company_policy', 'route' => '/staff/policies'],
                'policy-pending',
                'warning'
            );
        }

        $this->info(count($pendingByUser) . ' karyawan diingatkan.');

        return self::SUCCESS;
    }
}
