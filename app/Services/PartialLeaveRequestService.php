<?php

namespace App\Services;

use App\Filament\Resources\PartialLeaveRequestResource;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\PartialLeaveRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pengajuan Izin Sebagian Hari (2026-11-18, keputusan pemilik): izin singkat untuk keperluan pribadi yang TIDAK memotong jatah cuti maupun gaji,
 * dalam tiga kategori:
 *
 * - Terlambat Masuk Kerja: karyawan mengisi jam paling lambat ia tiba. Menit telat dari jam mulai kerja sampai jam itu tidak dihitung.
 * - Pulang Cepat: karyawan mengisi jam mulai ia pulang. Menit pulang cepat dari jam itu sampai jam selesai tidak dihitung.
 * - Keluar Lingkungan Kantor: jam keluar dan jam kembali. Hanya tercatat sebagai izin resmi (absensi hanya mencatat masuk dan keluar sekali sehari,
 *   jadi tidak ada menit yang bisa dihapus); tidak mengubah hitungan apa pun.
 *
 * Tanpa batas frekuensi dan tanpa menit minimal. Boleh diajukan susulan beberapa hari ke belakang (karyawan sering baru sempat mengajukan setelah
 * tiba). Pemutus: akses penuh atau store manager toko itu (atau pengguna toko itu yang diberi aksi Ubah di menu Pengajuan Izin Sebagian Hari);
 * pemohon tidak boleh memutuskan pengajuannya sendiri (kunci baris di dalam transaksi, pola sama dengan FieldDutyRequestService).
 */
class PartialLeaveRequestService
{
    /** Pengajuan susulan paling lama sekian hari ke belakang. */
    public const MAX_DAYS_BACK = 3;

    /** Pengajuan paling jauh sekian hari ke depan. */
    public const MAX_DAYS_AHEAD = 14;

    /**
     * @param array{date: Carbon|string, category: string, time?: ?string, start_time?: ?string, end_time?: ?string, reason?: ?string} $data
     *                                                                                                                                         time dipakai Terlambat Masuk Kerja dan Pulang Cepat; start_time dan end_time dipakai Keluar Lingkungan Kantor
     *
     * @throws RuntimeException bila pengajuan tidak memenuhi aturan.
     */
    public function submit(User $user, array $data): PartialLeaveRequest
    {
        $date = Carbon::parse($data['date'])->startOfDay();
        $category = (string) ($data['category'] ?? '');
        $reason = trim((string) ($data['reason'] ?? ''));
        [$start, $end] = $this->window($category, $data);

        $this->assertValid($user, $date, $category, $reason, $start, $end);

        $request = PartialLeaveRequest::create([
            'user_id' => $user->id,
            'store_id' => $user->store_id,
            'date' => $date->toDateString(),
            'category' => $category,
            'start_time' => $start,
            'end_time' => $end,
            'reason' => $reason,
            'status' => PartialLeaveRequest::STATUS_PENDING,
        ]);

        $this->notifyApprovers($request);

        return $request;
    }

    /**
     * Rentang jam menurut kategori, "HH:MM:00".
     *
     * @return array{0: string, 1: string}
     */
    private function window(string $category, array $data): array
    {
        if (! array_key_exists($category, PartialLeaveRequest::CATEGORY_LABELS)) {
            throw new RuntimeException('Pilih kategori izin: Terlambat Masuk Kerja, Keluar Lingkungan Kantor, atau Pulang Cepat.');
        }

        if ($category === PartialLeaveRequest::CATEGORY_LEAVE_OFFICE) {
            $start = $this->clean($data['start_time'] ?? null, 'Isi jam keluar.');
            $end = $this->clean($data['end_time'] ?? null, 'Isi jam kembali.');

            if ($end <= $start) {
                throw new RuntimeException('Jam kembali harus setelah jam keluar (di hari yang sama).');
            }

            return [$start, $end];
        }

        $time = $this->clean($data['time'] ?? null, $category === PartialLeaveRequest::CATEGORY_LATE_ARRIVAL ? 'Isi jam paling lambat Anda tiba.' : 'Isi jam Anda mulai pulang.');

        return $category === PartialLeaveRequest::CATEGORY_LATE_ARRIVAL ? ['00:00:00', $time] : [$time, '23:59:00'];
    }

    private function clean(?string $time, string $missing): string
    {
        $time = $time !== null ? trim($time) : '';

        if ($time === '') {
            throw new RuntimeException($missing);
        }

        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time)) {
            throw new RuntimeException('Format jam harus HH:MM (24 jam).');
        }

        return substr($time, 0, 5) . ':00';
    }

    private function assertValid(User $user, Carbon $date, string $category, string $reason, string $start, string $end): void
    {
        if ($user->isAttendanceExempt()) {
            throw new RuntimeException('Akun ini tidak wajib absen sehingga tidak mengajukan izin sebagian hari.');
        }

        if (! $user->store_id) {
            throw new RuntimeException('Akun ini belum terhubung ke toko mana pun, tidak bisa mengajukan izin sebagian hari.');
        }

        if ($date->lessThan(today()->subDays(self::MAX_DAYS_BACK))) {
            throw new RuntimeException('Izin sebagian hari susulan paling lama ' . self::MAX_DAYS_BACK . ' hari ke belakang. Untuk tanggal yang lebih lama, minta HR mencatatnya.');
        }

        if ($date->greaterThan(today()->addDays(self::MAX_DAYS_AHEAD))) {
            throw new RuntimeException('Izin sebagian hari paling jauh diajukan ' . self::MAX_DAYS_AHEAD . ' hari ke depan.');
        }

        if ($reason === '') {
            throw new RuntimeException('Tuliskan alasan izin.');
        }

        $day = $date->toDateString();

        $onLeave = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $day)
            ->whereDate('end_date', '>=', $day)
            ->exists();

        if ($onLeave) {
            throw new RuntimeException('Pada tanggal itu sudah ada izin/cuti sehari penuh, tidak perlu izin sebagian hari.');
        }

        $existing = Attendance::withoutGlobalScopes()->where('user_id', $user->id)->whereDate('date', $day)->first();

        if ($existing && in_array($existing->entry_type, ['leave', 'field_duty'], true)) {
            throw new RuntimeException('Pada tanggal itu absensi tercatat ' . ($existing->entry_type === 'leave' ? 'Izin/Cuti' : 'Dinas Luar sehari penuh') . ', tidak perlu izin sebagian hari.');
        }

        $this->assertNoConflict($user->id, $day, $category, $start, $end);
    }

    /** Satu kategori sekali per hari; Keluar Lingkungan Kantor boleh beberapa kali selama jamnya tidak tumpang tindih. Yang dihitung: menunggu dan disetujui. */
    private function assertNoConflict(int $userId, string $day, string $category, string $start, string $end): void
    {
        $others = PartialLeaveRequest::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->whereDate('date', $day)
            ->where('category', $category)
            ->whereIn('status', [PartialLeaveRequest::STATUS_PENDING, PartialLeaveRequest::STATUS_APPROVED])
            ->get();

        if ($others->isEmpty()) {
            return;
        }

        if ($category !== PartialLeaveRequest::CATEGORY_LEAVE_OFFICE) {
            throw new RuntimeException('Sudah ada pengajuan ' . PartialLeaveRequest::CATEGORY_LABELS[$category] . ' untuk tanggal itu.');
        }

        if ($others->contains(fn (PartialLeaveRequest $o) => $o->start_time < $end && $o->end_time > $start)) {
            throw new RuntimeException('Jam ini bertabrakan dengan pengajuan Keluar Lingkungan Kantor lain di tanggal itu.');
        }
    }

    public function approve(PartialLeaveRequest $request, User $by, ?string $notes = null): void
    {
        DB::transaction(function () use ($request, $by, $notes) {
            $locked = $this->lockPending($request);
            $this->assertCanDecide($locked, $by);

            $locked->update([
                'status' => PartialLeaveRequest::STATUS_APPROVED,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ]);

            $this->recalculateExistingAttendance($locked);
            $this->notifyRequester($locked, approved: true);
        });
    }

    public function reject(PartialLeaveRequest $request, User $by, string $notes): void
    {
        if (trim($notes) === '') {
            throw new RuntimeException('Alasan penolakan wajib diisi.');
        }

        DB::transaction(function () use ($request, $by, $notes) {
            $locked = $this->lockPending($request);
            $this->assertCanDecide($locked, $by);

            $locked->update([
                'status' => PartialLeaveRequest::STATUS_REJECTED,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                'review_notes' => trim($notes),
            ]);

            $this->notifyRequester($locked, approved: false);
        });
    }

    /**
     * Setelah disetujui, absensi yang SUDAH tercatat pada tanggal itu dihitung ulang telat dan pulang cepatnya (karyawan sering mengajukan setelah tiba).
     * Keluar Lingkungan Kantor tidak mengubah hitungan, jadi tidak perlu.
     */
    private function recalculateExistingAttendance(PartialLeaveRequest $request): void
    {
        if (! $request->affectsTiming()) {
            return;
        }

        $attendance = Attendance::withoutGlobalScopes()
            ->with(['user', 'store'])
            ->where('user_id', $request->user_id)
            ->whereDate('date', $request->date->toDateString())
            ->first();

        if (! $attendance || $attendance->clock_in_at === null || $attendance->entry_type === 'field_duty') {
            return;
        }

        $attendance->recalculateTiming();
    }

    /** Pemohon menarik pengajuannya: selama masih menunggu, atau sudah disetujui untuk tanggal yang belum tiba. */
    public function cancel(PartialLeaveRequest $request, int $userId): void
    {
        DB::transaction(function () use ($request, $userId) {
            $locked = PartialLeaveRequest::withoutGlobalScopes()->whereKey($request->id)->lockForUpdate()->first();

            if (! $locked || $locked->user_id !== $userId) {
                throw new RuntimeException('Pengajuan ini bukan milik akun ini.');
            }

            if (! $this->isCancellable($locked)) {
                throw new RuntimeException('Pengajuan ini tidak bisa dibatalkan: sudah diputuskan, ditolak, atau tanggalnya sudah tiba.');
            }

            $locked->update(['status' => PartialLeaveRequest::STATUS_CANCELLED]);
        });
    }

    public function isCancellable(PartialLeaveRequest $request): bool
    {
        return $request->isPending() || ($request->isApproved() && $request->date->greaterThan(today()));
    }

    private function lockPending(PartialLeaveRequest $request): PartialLeaveRequest
    {
        $locked = PartialLeaveRequest::withoutGlobalScopes()->whereKey($request->id)->lockForUpdate()->first();

        if (! $locked || ! $locked->isPending()) {
            throw new RuntimeException('Pengajuan ini sudah diputuskan sebelumnya.');
        }

        return $locked;
    }

    /**
     * Pemutus: akses penuh (semua toko); selain itu hanya untuk toko sendiri, yaitu store manager atau pengguna yang diberi aksi Ubah pada menu
     * Pengajuan Izin Sebagian Hari. Tidak pernah untuk pengajuan sendiri.
     */
    public function canDecide(User $by, PartialLeaveRequest $request): bool
    {
        if ($request->user_id === $by->id) {
            return false;
        }

        if ($by->isFullAccess()) {
            return true;
        }

        return $by->store_id !== null
            && (int) $by->store_id === (int) $request->store_id
            && ($by->isStoreManager() || $by->hasModuleAction(PartialLeaveRequestResource::class, 'update', false));
    }

    private function assertCanDecide(PartialLeaveRequest $request, User $by): void
    {
        if ($request->user_id === $by->id) {
            throw new RuntimeException('Tidak boleh memutuskan pengajuan izin sebagian hari milik sendiri.');
        }

        if (! $this->canDecide($by, $request)) {
            throw new RuntimeException('Hanya akses penuh, store manager toko ini, atau pengguna yang diberi hak memutuskan yang boleh memutuskan pengajuan izin sebagian hari.');
        }
    }

    // ------------------------------------------------------------------ pemberitahuan

    private function notifyApprovers(PartialLeaveRequest $request): void
    {
        $request->loadMissing('user:id,name', 'store:id,name');

        $recipients = User::where('is_active', true)
            ->get()
            ->filter(fn (User $u) => $this->canDecide($u, $request))
            ->pluck('id');

        $summary = "{$request->categoryLabel()}, {$request->date->translatedFormat('d M Y')} ({$request->windowLabel()}): {$request->reason}";

        app(StaffNotifier::class)->toUsers(
            $recipients,
            'Pengajuan Izin Sebagian Hari Baru',
            "{$request->user?->name} mengajukan izin sebagian hari. {$summary}",
            color: 'warning',
        );

        app(ApprovalMailer::class)->requested(
            'Izin Sebagian Hari',
            $request->user?->name ?? '—',
            $summary,
            $request->store?->name,
            $recipients,
        );
    }

    private function notifyRequester(PartialLeaveRequest $request, bool $approved): void
    {
        $when = "{$request->categoryLabel()} {$request->date->format('d M Y')} ({$request->windowLabel()})";

        app(StaffNotifier::class)->toUsers(
            [$request->user_id],
            $approved ? 'Pengajuan Izin Sebagian Hari Disetujui' : 'Pengajuan Izin Sebagian Hari Ditolak',
            $approved
                ? "Izin sebagian hari {$when} disetujui." . ($request->affectsTiming() ? ' Telat atau pulang cepat pada jam itu tidak dihitung.' : '')
                : "Izin sebagian hari {$when} ditolak." . ($request->review_notes ? " Alasan: {$request->review_notes}" : ''),
            color: $approved ? 'success' : 'danger',
        );
    }
}
