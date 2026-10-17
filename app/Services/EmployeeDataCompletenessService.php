<?php

namespace App\Services;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Pemeriksaan kelengkapan data karyawan sebelum payroll (Karyawan Fase 0, 2026-10-28). Dua tingkat:
 *  - "wajib": tanpa ini gaji atau absensi bisa salah atau tidak bisa digenerate (gaji pokok, toko, tanggal bergabung, tipe karyawan,
 *    kontrak tanpa tanggal berakhir atau sudah lewat, jam operasional toko belum lengkap);
 *  - "disarankan": data kepegawaian formal yang sebaiknya lengkap (rekening, NIK, NPWP, BPJS, kontak darurat).
 * Karyawan nonaktif dan akun partner tidak diperiksa.
 */
class EmployeeDataCompletenessService
{
    public const REQUIRED = 'wajib';
    public const RECOMMENDED = 'disarankan';

    /** @return Collection<int, array{user: User, required: list<string>, recommended: list<string>}> hanya karyawan yang punya temuan */
    public function findings(?int $storeId = null): Collection
    {
        $incompleteStores = Store::where('is_active', true)->get()
            ->filter(fn (Store $store) => $this->storeHoursIncomplete($store))
            ->pluck('id')
            ->all();

        return User::query()
            ->with(['employeeType', 'store'])
            ->where('is_active', true)
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'partner'))
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->orderBy('name')
            ->get()
            ->map(function (User $user) use ($incompleteStores) {
                return [
                    'user' => $user,
                    'required' => $this->requiredIssues($user, $incompleteStores),
                    'recommended' => $this->recommendedIssues($user),
                ];
            })
            ->filter(fn (array $row) => $row['required'] !== [] || $row['recommended'] !== [])
            ->values();
    }

    /** @param  list<int>  $incompleteStoreIds */
    public function requiredIssues(User $user, array $incompleteStoreIds = []): array
    {
        $issues = [];

        if ($user->base_salary === null) {
            $issues[] = 'Gaji pokok belum diisi (payroll tidak bisa digenerate)';
        }

        if (! $user->store_id) {
            $issues[] = 'Belum terhubung ke toko (payroll dan absensi tidak jalan)';
        } elseif (in_array($user->store_id, $incompleteStoreIds, true)) {
            $issues[] = 'Jam operasional tokonya belum lengkap (jam kerja gaji dan telat tidak bisa dihitung)';
        }

        if (! $user->join_date) {
            $issues[] = 'Tanggal bergabung belum diisi (gaji bulan pertama tidak diproporsikan)';
        }

        if (! $user->employee_type_id) {
            $issues[] = 'Tipe karyawan belum dipilih';
        } elseif ($user->employeeType?->has_end_date) {
            if (! $user->contract_end_date) {
                $issues[] = 'Karyawan kontrak tanpa tanggal berakhir kontrak';
            } elseif ($user->contract_end_date->isPast()) {
                $issues[] = 'Kontrak sudah berakhir pada ' . $user->contract_end_date->format('d M Y') . ' dan belum diperpanjang';
            }
        }

        return $issues;
    }

    public function recommendedIssues(User $user): array
    {
        $issues = [];

        if (blank($user->bank_account_number) || blank($user->bank_name)) {
            $issues[] = 'Rekening bank belum lengkap (untuk transfer gaji)';
        }

        if (blank($user->nik)) {
            $issues[] = 'NIK belum diisi';
        }

        if (blank($user->npwp)) {
            $issues[] = 'NPWP belum diisi';
        }

        if (blank($user->bpjs_kesehatan_number) || blank($user->bpjs_ketenagakerjaan_number)) {
            $issues[] = 'Nomor BPJS belum lengkap';
        }

        if (blank($user->emergency_contact_name) || blank($user->emergency_contact_phone)) {
            $issues[] = 'Kontak darurat belum diisi';
        }

        return $issues;
    }

    /** Toko aktif yang hari bukanya belum punya jam buka dan tutup lengkap: jam kerja terjadwal sebulan tidak bisa dihitung. */
    private function storeHoursIncomplete(Store $store): bool
    {
        $rows = collect($store->opening_hours ?? []);

        return $rows->isEmpty()
            || $rows->contains(fn ($row) => empty($row['closed']) && (empty($row['open']) || empty($row['close'])));
    }
}
