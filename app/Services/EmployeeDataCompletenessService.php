<?php

namespace App\Services;

use App\Models\CompanyPolicy;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Pemeriksaan kelengkapan data karyawan sebelum payroll (Karyawan Fase 0, 2026-10-28). Dua tingkat:
 *  - "wajib": tanpa ini gaji atau absensi bisa salah atau tidak bisa digenerate (gaji pokok, toko, tanggal bergabung, tipe karyawan,
 *    kontrak tanpa tanggal berakhir atau sudah lewat, jam operasional toko belum lengkap);
 *  - "disarankan": data kepegawaian formal yang sebaiknya lengkap (rekening, NIK, NPWP, BPJS, kontak darurat), ditambah butir yang
 *    perlu ditindaklanjuti tetapi bukan data kosong (masa percobaan, gaji di luar golongan, dokumen yang belum diterima).
 * Karyawan nonaktif dan akun partner tidak diperiksa. Setiap butir punya "jenis" (kunci di KIND_LABELS) untuk ringkasan per jenis masalah.
 */
class EmployeeDataCompletenessService
{
    public const REQUIRED = 'wajib';
    public const RECOMMENDED = 'disarankan';

    /** Jenis butir -> label ringkasan. */
    public const KIND_LABELS = [
        'salary' => 'Gaji pokok belum diisi',
        'salary_zero' => 'Gaji pokok Rp 0',
        'store' => 'Belum terhubung ke toko',
        'store_hours' => 'Jam operasional toko belum lengkap',
        'join_date' => 'Tanggal bergabung belum diisi',
        'type' => 'Tipe karyawan belum dipilih',
        'contract_end' => 'Kontrak tanpa tanggal berakhir',
        'contract_expired' => 'Kontrak sudah berakhir',
        'pkwt_limit' => 'Kontrak melewati batas PKWT',
        'bank' => 'Rekening bank belum lengkap',
        'bank_format' => 'Nomor rekening janggal',
        'nik_format' => 'NIK janggal (bukan 16 digit)',
        'npwp_format' => 'NPWP janggal (bukan 15 atau 16 digit)',
        'nik' => 'NIK belum diisi',
        'npwp' => 'NPWP belum diisi',
        'bpjs' => 'Nomor BPJS belum lengkap',
        'emergency' => 'Kontak darurat belum diisi',
        'probation' => 'Masa percobaan segera berakhir',
        'grade' => 'Gaji di luar rentang golongan',
        'policy' => 'Dokumen wajib belum diterima',
    ];

    /** Jenis yang bukan data kosong, melainkan butir tindak lanjut. */
    public const FOLLOW_UP_KINDS = ['probation', 'grade', 'policy'];

    /**
     * Hanya karyawan yang punya temuan, yang paling banyak butir wajibnya lebih dulu.
     *
     * @return Collection<int, array{user: User, required: list<string>, recommended: list<string>, recommended_data: list<string>, follow_up: list<string>, required_kinds: list<string>, recommended_kinds: list<string>}>
     */
    public function findings(?int $storeId = null): Collection
    {
        // Peraturan Perusahaan yang terbit dan wajib diterima: karyawan yang belum menerimanya ditandai (dasar sah potongan).
        $requiredPolicies = CompanyPolicy::published()->where('requires_acknowledgment', true)->get(['id', 'title']);
        $acknowledged = \App\Models\PolicyAcknowledgment::whereIn('company_policy_id', $requiredPolicies->pluck('id'))
            ->get(['user_id', 'company_policy_id'])->groupBy('user_id');

        $incompleteStores = Store::where('is_active', true)->get()
            ->filter(fn (Store $store) => $this->storeHoursIncomplete($store))
            ->pluck('id')
            ->all();

        return User::query()
            ->with(['employeeType', 'store', 'salaryGrade'])
            ->where('is_active', true)
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'partner'))
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->orderBy('name')
            ->get()
            ->map(function (User $user) use ($incompleteStores, $requiredPolicies, $acknowledged) {
                $done = ($acknowledged[$user->id] ?? collect())->pluck('company_policy_id');
                $pendingPolicies = $requiredPolicies->reject(fn ($p) => $done->contains($p->id));

                $required = $this->requiredIssueMap($user, $incompleteStores);
                $recommended = $this->recommendedIssueMap($user);
                $policyIssues = $pendingPolicies->mapWithKeys(fn ($p) => ["policy:{$p->id}" => "Belum menerima dokumen \"{$p->title}\""])->all();
                $allRecommended = $recommended + $policyIssues;

                $isFollowUp = fn (string $key) => in_array(explode(':', $key)[0], self::FOLLOW_UP_KINDS, true);

                return [
                    'user' => $user,
                    'required' => array_values($required),
                    'recommended' => array_values($allRecommended),
                    'recommended_data' => array_values(array_filter($allRecommended, fn ($text, $key) => ! $isFollowUp($key), ARRAY_FILTER_USE_BOTH)),
                    'follow_up' => array_values(array_filter($allRecommended, fn ($text, $key) => $isFollowUp($key), ARRAY_FILTER_USE_BOTH)),
                    'required_kinds' => array_keys($required),
                    'recommended_kinds' => array_map(fn ($key) => explode(':', $key)[0], array_keys($allRecommended)),
                ];
            })
            ->filter(fn (array $row) => $row['required'] !== [] || $row['recommended'] !== [])
            ->sort(fn (array $a, array $b) => [count($b['required']), count($b['recommended_data']), count($b['follow_up'])] <=> [count($a['required']), count($a['recommended_data']), count($a['follow_up'])]
                ?: strcasecmp($a['user']->name, $b['user']->name))
            ->values();
    }

    /** Hitungan karyawan per jenis masalah, terbanyak dulu: [kind => ['label' => string, 'count' => int, 'required' => bool]]. */
    public function summarize(Collection $findings): array
    {
        $summary = [];

        foreach ($findings as $row) {
            foreach (array_unique($row['required_kinds']) as $kind) {
                $summary[$kind] ??= ['label' => self::KIND_LABELS[$kind] ?? $kind, 'count' => 0, 'required' => true];
                $summary[$kind]['count']++;
            }
            foreach (array_unique($row['recommended_kinds']) as $kind) {
                $summary[$kind] ??= ['label' => self::KIND_LABELS[$kind] ?? $kind, 'count' => 0, 'required' => false];
                $summary[$kind]['count']++;
            }
        }

        uasort($summary, fn (array $a, array $b) => [$b['required'], $b['count']] <=> [$a['required'], $a['count']]);

        return $summary;
    }

    /**
     * Data yang bisa dilengkapi karyawan sendiri (diserahkan ke HR), dalam kata yang dikenal karyawan. Untuk banner di Profil Saya di aplikasi;
     * butir internal HR (gaji pokok, toko, kontrak, golongan) sengaja tidak ikut.
     *
     * @return list<string>
     */
    public function employeeFacingGaps(User $user): array
    {
        $labels = [
            'nik' => 'NIK',
            'nik_format' => 'NIK (tampak keliru)',
            'npwp' => 'NPWP',
            'npwp_format' => 'NPWP (tampak keliru)',
            'bpjs' => 'Nomor BPJS',
            'bank' => 'Rekening bank',
            'bank_format' => 'Nomor rekening (tampak keliru)',
            'emergency' => 'Kontak darurat',
        ];

        return collect($this->recommendedIssueMap($user))
            ->keys()
            ->filter(fn (string $kind) => isset($labels[$kind]))
            ->map(fn (string $kind) => $labels[$kind])
            ->values()
            ->all();
    }

    /** @param  list<int>  $incompleteStoreIds */
    public function requiredIssues(User $user, array $incompleteStoreIds = []): array
    {
        return array_values($this->requiredIssueMap($user, $incompleteStoreIds));
    }

    public function recommendedIssues(User $user): array
    {
        return array_values($this->recommendedIssueMap($user));
    }

    /** @param  list<int>  $incompleteStoreIds @return array<string, string> jenis => kalimat */
    private function requiredIssueMap(User $user, array $incompleteStoreIds): array
    {
        $issues = [];

        if ($user->base_salary === null) {
            $issues['salary'] = 'Gaji pokok belum diisi (payroll tidak bisa digenerate)';
        } elseif ((float) $user->base_salary <= 0) {
            $issues['salary_zero'] = 'Gaji pokok Rp 0 (slip gaji bulanannya akan bernilai Rp 0)';
        }

        if (! $user->store_id) {
            $issues['store'] = 'Belum terhubung ke toko (payroll dan absensi tidak jalan)';
        } elseif (in_array($user->store_id, $incompleteStoreIds, true)) {
            $issues['store_hours'] = 'Jam operasional tokonya belum lengkap (jam kerja gaji dan telat tidak bisa dihitung)';
        }

        if (! $user->join_date) {
            $issues['join_date'] = 'Tanggal bergabung belum diisi (gaji bulan pertama tidak diproporsikan)';
        }

        if (! $user->employee_type_id) {
            $issues['type'] = 'Tipe karyawan belum dipilih';
        } elseif ($user->employeeType?->has_end_date) {
            if (! $user->contract_end_date) {
                $issues['contract_end'] = 'Karyawan kontrak tanpa tanggal berakhir kontrak';
            } elseif ($user->contract_end_date->isPast()) {
                $issues['contract_expired'] = 'Kontrak sudah berakhir pada ' . $user->contract_end_date->format('d M Y') . ' dan belum diperpanjang';
            }
        }

        // PKWT melewati batas lama maksimal (PP 35/2021).
        $maxYears = (int) config('hr.pkwt_max_years', 5);
        if ($user->employeeType?->has_end_date && $user->join_date && $user->contract_end_date && $user->contract_end_date->gt($user->join_date->copy()->addYears($maxYears))) {
            $issues['pkwt_limit'] = "Kontrak melewati batas {$maxYears} tahun PKWT sejak tanggal bergabung";
        }

        return $issues;
    }

    /** @return array<string, string> jenis => kalimat */
    private function recommendedIssueMap(User $user): array
    {
        $issues = [];

        $warnDays = (int) config('hr.probation_warning_days', 14);
        if ($user->probation_end_date && $user->probation_end_date->between(today(), today()->addDays($warnDays))) {
            $issues['probation'] = 'Masa percobaan berakhir ' . $user->probation_end_date->format('d M Y') . ' (putuskan kelanjutannya)';
        }

        if ($user->salaryGrade && $user->base_salary !== null && ! $user->salaryGrade->contains((float) $user->base_salary)) {
            $issues['grade'] = "Gaji pokok di luar rentang golongan \"{$user->salaryGrade->name}\"";
        }

        if (blank($user->bank_account_number) || blank($user->bank_name)) {
            $issues['bank'] = 'Rekening bank belum lengkap (untuk transfer gaji)';
        } elseif (! $this->validBankAccount($user->bank_account_number)) {
            $issues['bank_format'] = "Nomor rekening \"{$user->bank_account_number}\" tampak janggal (harus angka, 6 sampai 20 digit)";
        }

        if (blank($user->nik)) {
            $issues['nik'] = 'NIK belum diisi';
        } elseif (! preg_match('/^\d{16}$/', preg_replace('/[\s.\-]/', '', $user->nik))) {
            $issues['nik_format'] = 'NIK tampak janggal (harus 16 digit angka)';
        }

        if (blank($user->npwp)) {
            $issues['npwp'] = 'NPWP belum diisi';
        } elseif (! preg_match('/^\d{15,16}$/', preg_replace('/[\s.\-]/', '', $user->npwp))) {
            $issues['npwp_format'] = 'NPWP tampak janggal (harus 15 atau 16 digit angka)';
        }

        if (blank($user->bpjs_kesehatan_number) || blank($user->bpjs_ketenagakerjaan_number)) {
            $issues['bpjs'] = 'Nomor BPJS belum lengkap';
        }

        if (blank($user->emergency_contact_name) || blank($user->emergency_contact_phone)) {
            $issues['emergency'] = 'Kontak darurat belum diisi';
        }

        return $issues;
    }

    private function validBankAccount(string $number): bool
    {
        $digits = strlen(preg_replace('/\D/', '', $number));

        return (bool) preg_match('/^[\d\s\-]+$/', $number) && $digits >= 6 && $digits <= 20;
    }

    /** Toko aktif yang hari bukanya belum punya jam buka dan tutup lengkap: jam kerja terjadwal sebulan tidak bisa dihitung. */
    private function storeHoursIncomplete(Store $store): bool
    {
        $rows = collect($store->opening_hours ?? []);

        return $rows->isEmpty()
            || $rows->contains(fn ($row) => empty($row['closed']) && (empty($row['open']) || empty($row['close'])));
    }
}
