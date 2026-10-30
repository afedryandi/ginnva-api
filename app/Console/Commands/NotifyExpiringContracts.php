<?php

namespace App\Console\Commands;

use App\Filament\Resources\ContractExtensionResource;
use App\Models\ContractExtension;
use App\Models\User;
use App\Services\StaffNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Pengingat kontrak karyawan yang akan atau baru berakhir -- jalan harian (lihat routes/console.php). Ditata ulang pada audit Riwayat Kontrak
 * (2026-10-31) supaya tidak berisik dan tepat sasaran:
 *  - Pemegang menu Riwayat Kontrak (akses penuh dan yang dicentang eksplisit; non-penuh hanya karyawan tokonya) menerima SATU ringkasan
 *    per hari: yang berakhir dalam 7 hari dipisah dari yang sampai 30 hari. Selama ringkasan sebelumnya belum dibaca, barisnya diperbarui,
 *    bukan ditumpuk 30 baris.
 *  - Karyawan sendiri diingatkan hanya pada H-30, H-14, H-7, H-3, dan H-1 (kotak masuk dan push), bukan tiap hari.
 *  - Kontrak yang baru lewat dan akunnya sudah dinonaktifkan otomatis diingatkan 7 hari ke depan kepada pemegang menu, supaya perpanjangan
 *    yang terlambat dicatat (mengaktifkan akun kembali) atau memang dibiarkan berakhir.
 * Hanya karyawan aktif bertipe kontrak yang dihitung. Setelah diperpanjang, baris keluar sendiri dari jendela 30 hari ini.
 */
class NotifyExpiringContracts extends Command
{
    /** Hari sebelum kontrak berakhir saat karyawan sendiri diingatkan. */
    public const EMPLOYEE_REMINDER_DAYS = [30, 14, 7, 3, 1];

    /** Lama pengingat untuk kontrak yang sudah lewat dan akunnya dinonaktifkan otomatis. */
    public const LAPSED_REMINDER_DAYS = 7;

    protected $signature = 'contracts:notify-expiring';

    protected $description = 'Kirim ringkasan kontrak karyawan yang akan/baru berakhir ke pemegang menu Riwayat Kontrak, dan pengingat bertahap ke karyawan yang bersangkutan';

    public function handle(): int
    {
        $expiring = User::query()
            ->where('is_active', true)
            ->whereHas('employeeType', fn ($q) => $q->where('has_end_date', true))
            ->whereNotNull('contract_end_date')
            ->whereDate('contract_end_date', '>=', today())
            ->whereDate('contract_end_date', '<=', today()->addDays(30))
            ->orderBy('contract_end_date')
            ->get();

        $lapsed = User::query()
            ->where('is_active', false)
            ->whereHas('employeeType', fn ($q) => $q->where('has_end_date', true))
            ->whereNotNull('contract_end_date')
            ->whereDate('contract_end_date', '<', today())
            ->whereDate('contract_end_date', '>=', today()->subDays(self::LAPSED_REMINDER_DAYS))
            ->orderBy('contract_end_date')
            ->get()
            ->filter(fn (User $user) => ContractExtension::wasDeactivatedByLapse($user));

        if ($expiring->isEmpty() && $lapsed->isEmpty()) {
            $this->info('Tidak ada kontrak karyawan yang mendekati atau baru berakhir.');

            return self::SUCCESS;
        }

        // Hanya akses penuh dan pemegang menu Riwayat Kontrak yang dicentang eksplisit (akun bawaan tidak ikut); pemegang menu non-penuh
        // hanya menerima karyawan tokonya sendiri, sama seperti isi daftar riwayat.
        $recipients = User::where('is_active', true)->get()->filter(fn (User $user) => $user->hasExplicitMenuAccess(ContractExtensionResource::class));
        $digests = 0;

        foreach ($recipients as $recipient) {
            $mine = fn (Collection $people) => $recipient->isFullAccess() ? $people : $people->where('store_id', $recipient->store_id);

            if ($digest = $this->expiringDigest($mine($expiring))) {
                app(StaffNotifier::class)->toUsers([$recipient->id], 'Kontrak Karyawan Akan Berakhir', $digest['body'], [], 'contracts-expiring', $digest['color']);
                $digests++;
            }

            if ($body = $this->lapsedBody($mine($lapsed))) {
                app(StaffNotifier::class)->toUsers([$recipient->id], 'Kontrak Karyawan Sudah Berakhir', $body, [], 'contracts-lapsed', 'danger');
                $digests++;
            }
        }

        $reminded = 0;
        foreach ($expiring as $user) {
            $daysLeft = (int) today()->diffInDays($user->contract_end_date, false);

            if (! in_array($daysLeft, self::EMPLOYEE_REMINDER_DAYS, true)) {
                continue;
            }

            app(StaffNotifier::class)->toUsers(
                [$user->id],
                'Kontrak Kerja Anda Akan Berakhir',
                "Kontrak Anda berakhir {$daysLeft} hari lagi (" . $user->contract_end_date->translatedFormat('d M Y') . '). Hubungi HR/atasan Anda untuk informasi perpanjangan.',
                [],
                'contract-expiring-self',
                $daysLeft <= 7 ? 'warning' : 'info'
            );
            $reminded++;
        }

        $this->info("{$digests} ringkasan ke pemegang menu, {$reminded} pengingat ke karyawan ({$expiring->count()} kontrak mendekati berakhir, {$lapsed->count()} baru lewat).");

        return self::SUCCESS;
    }

    /** @return array{body: string, color: string}|null */
    private function expiringDigest(Collection $people): ?array
    {
        if ($people->isEmpty()) {
            return null;
        }

        $urgent = $people->filter(fn (User $u) => today()->diffInDays($u->contract_end_date, false) <= 7);
        $normal = $people->reject(fn (User $u) => $urgent->contains('id', $u->id));

        $parts = [];
        if ($urgent->isNotEmpty()) {
            $parts[] = "SEGERA (7 hari atau kurang), {$urgent->count()} karyawan: " . $this->names($urgent) . '.';
        }
        if ($normal->isNotEmpty()) {
            $parts[] = "Dalam 30 hari, {$normal->count()} karyawan: " . $this->names($normal) . '.';
        }

        return ['body' => implode(' ', $parts) . ' Buka Karyawan > Riwayat Kontrak.', 'color' => $urgent->isNotEmpty() ? 'danger' : 'warning'];
    }

    private function lapsedBody(Collection $people): ?string
    {
        if ($people->isEmpty()) {
            return null;
        }

        return "{$people->count()} karyawan kontraknya baru berakhir dan akunnya dinonaktifkan otomatis: " . $this->names($people)
            . '. Bila ternyata diperpanjang, catat di Karyawan > Riwayat Kontrak (akunnya diaktifkan kembali); bila tidak, biarkan.';
    }

    private function names(Collection $people): string
    {
        $listed = $people->take(5)->map(fn (User $u) => "{$u->name} ({$u->contract_end_date->format('d M Y')})")->implode(', ');
        $more = $people->count() > 5 ? ' dan ' . ($people->count() - 5) . ' lainnya' : '';

        return $listed . $more;
    }
}
