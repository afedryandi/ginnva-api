<?php

namespace App\Services;

use App\Filament\Resources\FieldDutyRequestResource;
use App\Models\Attendance;
use App\Models\FieldDutyRequest;
use App\Models\LeaveRequest;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pengajuan Dinas Luar (2026-11-12, keputusan pemilik): karyawan yang bekerja di luar toko (mis. kanvasing) tidak bisa absen karena absen dibatasi
 * radius toko. Mereka mengajukan dengan tujuan yang jelas, boleh diajukan susulan, dalam dua bentuk:
 *
 * - Sehari penuh (jam kosong): setelah disetujui, absensi hari itu tercatat sebagai Dinas Luar (entry_type field_duty), yang tidak pernah
 *   dihitung telat atau pulang cepat.
 * - Sebagian hari (jam mulai dan selesai): absen masuk dan keluar di toko tetap wajib, tetapi menit telat dan pulang cepat yang tercakup rentang
 *   yang disetujui tidak dihitung (lihat Attendance::fieldDutyCoveredMinutes). Bila rentang yang disetujui berlangsung sampai jam tutup toko atau
 *   lebih, karyawan tidak perlu kembali: absen keluar diisi otomatis dengan jam tutup. Jam dinas melewati jam tutup BUKAN lembur; lembur tetap
 *   lewat Pengajuan Lembur.
 *
 * Pemutus: akses penuh, store manager toko itu, atau pengguna toko itu yang diberi aksi Ubah pada menu Pengajuan Dinas Luar di Hak Akses Detail;
 * boleh mengubah jam saat menyetujui. Pemohon tidak boleh memutuskan pengajuannya sendiri (kunci baris di dalam transaksi, pola sama dengan
 * OvertimeRequestService).
 */
class FieldDutyRequestService
{
    /** Pengajuan susulan paling lama sekian hari ke belakang. */
    public const MAX_DAYS_BACK = 7;

    /** Pengajuan paling jauh sekian hari ke depan. */
    public const MAX_DAYS_AHEAD = 14;

    /** Dinas sebagian hari minimal sekian menit. */
    public const MIN_PARTIAL_MINUTES = 30;

    /**
     * @param array{date: Carbon|string, description?: ?string, start_time?: ?string, end_time?: ?string} $data jam kosong = sehari penuh
     *
     * @throws RuntimeException bila pengajuan tidak memenuhi aturan.
     */
    public function submit(User $user, array $data): FieldDutyRequest
    {
        $date = Carbon::parse($data['date'])->startOfDay();
        $description = trim((string) ($data['description'] ?? ''));
        [$start, $end] = $this->normalizeWindow($data['start_time'] ?? null, $data['end_time'] ?? null);

        $this->assertValid($user, $date, $description, $start, $end);

        $request = FieldDutyRequest::create([
            'user_id' => $user->id,
            'store_id' => $user->store_id,
            'date' => $date->toDateString(),
            'start_time' => $start,
            'end_time' => $end,
            'description' => $description,
            'status' => FieldDutyRequest::STATUS_PENDING,
        ]);

        $this->notifyApprovers($request);

        return $request;
    }

    /**
     * Jam mulai dan selesai harus keduanya terisi (sebagian hari) atau keduanya kosong (sehari penuh).
     *
     * @return array{0: ?string, 1: ?string} "HH:MM:00" atau null
     */
    private function normalizeWindow(?string $start, ?string $end): array
    {
        $start = $start !== null && trim($start) !== '' ? trim($start) : null;
        $end = $end !== null && trim($end) !== '' ? trim($end) : null;

        if ($start === null && $end === null) {
            return [null, null];
        }

        if ($start === null || $end === null) {
            throw new RuntimeException('Isi jam mulai dan jam selesai, atau kosongkan keduanya untuk dinas sehari penuh.');
        }

        foreach ([$start, $end] as $time) {
            if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time)) {
                throw new RuntimeException('Format jam harus HH:MM (24 jam).');
            }
        }

        $start = substr($start, 0, 5) . ':00';
        $end = substr($end, 0, 5) . ':00';

        if ($end <= $start) {
            throw new RuntimeException('Jam selesai harus setelah jam mulai (di hari yang sama).');
        }

        $minutes = (int) Carbon::parse("2000-01-01 {$start}")->diffInMinutes(Carbon::parse("2000-01-01 {$end}"));

        if ($minutes < self::MIN_PARTIAL_MINUTES) {
            throw new RuntimeException('Dinas sebagian hari minimal ' . self::MIN_PARTIAL_MINUTES . ' menit.');
        }

        return [$start, $end];
    }

    private function assertValid(User $user, Carbon $date, string $description, ?string $start, ?string $end): void
    {
        if ($user->isAttendanceExempt()) {
            throw new RuntimeException('Akun ini tidak wajib absen sehingga tidak mengajukan dinas luar.');
        }

        if (! $user->store_id) {
            throw new RuntimeException('Akun ini belum terhubung ke toko mana pun, tidak bisa mengajukan dinas luar.');
        }

        if ($date->lessThan(today()->subDays(self::MAX_DAYS_BACK))) {
            throw new RuntimeException('Dinas luar susulan paling lama ' . self::MAX_DAYS_BACK . ' hari ke belakang. Untuk tanggal yang lebih lama, minta HR mencatatnya di Absensi Karyawan.');
        }

        if ($date->greaterThan(today()->addDays(self::MAX_DAYS_AHEAD))) {
            throw new RuntimeException('Dinas luar paling jauh diajukan ' . self::MAX_DAYS_AHEAD . ' hari ke depan.');
        }

        if ($description === '') {
            throw new RuntimeException('Jelaskan tujuan atau pekerjaan di luar toko, mis. kanvasing area Kelapa Gading.');
        }

        $day = $date->toDateString();

        $onLeave = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $day)
            ->whereDate('end_date', '>=', $day)
            ->exists();

        if ($onLeave) {
            throw new RuntimeException('Pada tanggal itu Anda sedang izin/cuti, tidak bisa mengajukan dinas luar.');
        }

        $existing = Attendance::withoutGlobalScopes()->where('user_id', $user->id)->whereDate('date', $day)->first();

        if ($existing && $existing->entry_type === 'leave') {
            throw new RuntimeException('Pada tanggal itu absensi Anda tercatat Izin/Cuti, tidak bisa mengajukan dinas luar.');
        }

        if ($start === null && $this->hasRealAttendance($user->id, $day)) {
            throw new RuntimeException('Pada tanggal itu sudah ada catatan absensi. Dinas sehari penuh hanya untuk hari tanpa absen; kalau Anda sempat absen dan keluar sebagian hari, ajukan dinas dengan jam mulai dan selesai.');
        }

        if ($start !== null && $existing && $existing->entry_type === 'field_duty') {
            throw new RuntimeException('Hari itu sudah tercatat Dinas Luar sehari penuh.');
        }

        $this->assertNoConflict($user->id, $day, $start, $end);
    }

    /**
     * Sehari penuh tidak boleh bersama pengajuan lain pada hari yang sama; dinas sebagian hari boleh lebih dari satu selama jamnya tidak tumpang
     * tindih dan tidak ada pengajuan sehari penuh. Yang dihitung: pengajuan menunggu dan disetujui.
     */
    private function assertNoConflict(int $userId, string $day, ?string $start, ?string $end, ?int $ignoreId = null): void
    {
        $others = FieldDutyRequest::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->whereDate('date', $day)
            ->whereIn('status', [FieldDutyRequest::STATUS_PENDING, FieldDutyRequest::STATUS_APPROVED])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->get();

        if ($others->isEmpty()) {
            return;
        }

        if ($start === null || $others->contains(fn (FieldDutyRequest $o) => ! $o->isPartialDay())) {
            throw new RuntimeException('Anda sudah punya pengajuan dinas luar untuk tanggal itu.');
        }

        $overlaps = $others->contains(fn (FieldDutyRequest $o) => $o->start_time < $end && $o->end_time > $start);

        if ($overlaps) {
            throw new RuntimeException('Jam ini bertabrakan dengan pengajuan dinas luar Anda yang lain di tanggal itu.');
        }
    }

    /** Hari itu sudah punya absensi nyata (absen masuk di toko, atau Izin/Cuti): tidak boleh ditimpa menjadi Dinas Luar. Hari Alpha atau kosong boleh. */
    private function hasRealAttendance(int $userId, string $day, bool $lock = false): bool
    {
        $query = Attendance::withoutGlobalScopes()->where('user_id', $userId)->whereDate('date', $day);
        $existing = ($lock ? $query->lockForUpdate() : $query)->first();

        if (! $existing) {
            return false;
        }

        return $existing->entry_type === 'leave'
            || (in_array($existing->entry_type, ['clock', 'manual'], true) && $existing->clock_in_at !== null);
    }

    /**
     * @param array{start_time?: ?string, end_time?: ?string}|null $adjust jam baru dari pemutus (hanya untuk dinas sebagian hari); null = pakai
     *                                                                       jam yang diajukan
     */
    public function approve(FieldDutyRequest $request, User $by, ?string $notes = null, ?array $adjust = null): void
    {
        DB::transaction(function () use ($request, $by, $notes, $adjust) {
            $locked = $this->lockPending($request);
            $this->assertCanDecide($locked, $by);

            $day = $locked->date->toDateString();

            if ($locked->isPartialDay()) {
                $this->approvePartial($locked, $adjust);
            } else {
                $this->approveFullDay($locked, $by, $day);
            }

            $locked->update([
                'status' => FieldDutyRequest::STATUS_APPROVED,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ]);

            if ($locked->isPartialDay()) {
                $this->applyToExistingAttendance($locked);
            }

            $this->notifyRequester($locked, approved: true);
        });
    }

    private function approveFullDay(FieldDutyRequest $locked, User $by, string $day): void
    {
        if ($this->hasRealAttendance($locked->user_id, $day, lock: true)) {
            throw new RuntimeException('Karyawan ini sudah punya catatan absensi di tanggal itu, jadi tidak bisa dicatat sebagai dinas luar sehari penuh. Tolak pengajuan ini atau koreksi absensinya lebih dulu.');
        }

        // Hari Alpha (otomatis dini hari) dan baris kosong diganti menjadi Dinas Luar; absensi di toko tidak pernah ditimpa.
        $attributes = [
            'store_id' => $locked->store_id,
            'entry_type' => 'field_duty',
            'clock_in_at' => null,
            'clock_out_at' => null,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'note' => $locked->description,
            'recorded_by' => $by->id,
        ];

        $existing = Attendance::withoutGlobalScopes()->where('user_id', $locked->user_id)->whereDate('date', $day)->first();

        if ($existing) {
            $existing->forceFill($attributes)->save();
        } else {
            Attendance::create($attributes + ['user_id' => $locked->user_id, 'date' => $day]);
        }
    }

    /** Dinas sebagian hari: pemutus boleh mengubah jam (mis. karena dinas ternyata lebih lama atau dinilai berlebihan). */
    private function approvePartial(FieldDutyRequest $locked, ?array $adjust): void
    {
        if ($adjust === null || (($adjust['start_time'] ?? null) === null && ($adjust['end_time'] ?? null) === null)) {
            return;
        }

        [$start, $end] = $this->normalizeWindow($adjust['start_time'] ?? null, $adjust['end_time'] ?? null);

        if ($start === null) {
            throw new RuntimeException('Jam mulai dan jam selesai tidak boleh dikosongkan untuk dinas sebagian hari.');
        }

        $this->assertNoConflict($locked->user_id, $locked->date->toDateString(), $start, $end, $locked->id);

        $locked->update(['start_time' => $start, 'end_time' => $end]);
    }

    /**
     * Setelah dinas sebagian hari disetujui, absensi yang SUDAH tercatat pada tanggal itu dihitung ulang telat dan pulang cepatnya, dan bila rentang
     * yang disetujui berlangsung sampai jam tutup, absen keluar yang masih kosong ditutup otomatis.
     */
    private function applyToExistingAttendance(FieldDutyRequest $request): void
    {
        $attendance = Attendance::withoutGlobalScopes()
            ->with(['user', 'store'])
            ->where('user_id', $request->user_id)
            ->whereDate('date', $request->date->toDateString())
            ->first();

        if (! $attendance || $attendance->clock_in_at === null || $attendance->entry_type === 'field_duty') {
            return;
        }

        $this->autoCloseIfCovered($attendance, $request);
        $attendance->refresh()->recalculateTiming();
    }

    /**
     * Rentang yang disetujui berlangsung sampai jam tutup (atau jam selesai shift) atau lebih, dan absen keluar masih kosong: karyawan tidak mungkin
     * kembali ke toko, jadi jam keluar diisi jam tutup. Hanya dilakukan bila jam tutup hari itu sudah lewat.
     */
    private function autoCloseIfCovered(Attendance $attendance, FieldDutyRequest $request): bool
    {
        if ($attendance->clock_out_at !== null || ! $request->isPartialDay() || ! $request->isApproved()) {
            return false;
        }

        $user = $attendance->user ?? User::find($attendance->user_id);
        $store = $attendance->store ?? Store::find($attendance->store_id);

        if (! $user || ! $store) {
            return false;
        }

        $expectedEnd = Attendance::expectedEndOn($user, $store, $attendance->date->copy());

        if ($expectedEnd === null || now()->lessThan($expectedEnd) || $request->endsAt()->lessThan($expectedEnd) || $attendance->clock_in_at->greaterThanOrEqualTo($expectedEnd)) {
            return false;
        }

        $attendance->forceFill([
            'clock_out_at' => $expectedEnd,
            'note' => trim(($attendance->note ? $attendance->note . ' | ' : '') . 'Absen keluar otomatis: dinas luar sampai jam tutup (' . $request->windowLabel() . ').'),
        ])->save();

        return true;
    }

    /**
     * Dipanggil harian (attendance:mark-absences, dini hari) untuk hari kemarin: absensi yang masih belum absen keluar tetapi punya dinas sebagian
     * hari disetujui yang berlangsung sampai jam tutup, ditutup otomatis. Mengembalikan jumlah baris yang ditutup.
     */
    public function closeCoveredRows(Carbon $day): int
    {
        $closed = 0;

        $requests = FieldDutyRequest::withoutGlobalScopes()
            ->where('status', FieldDutyRequest::STATUS_APPROVED)
            ->whereDate('date', $day->toDateString())
            ->whereNotNull('start_time')
            ->get();

        foreach ($requests as $request) {
            $attendance = Attendance::withoutGlobalScopes()
                ->with(['user', 'store'])
                ->where('user_id', $request->user_id)
                ->whereDate('date', $day->toDateString())
                ->whereNotNull('clock_in_at')
                ->whereNull('clock_out_at')
                ->where('entry_type', '!=', 'field_duty')
                ->first();

            if ($attendance && $this->autoCloseIfCovered($attendance, $request)) {
                $attendance->refresh()->recalculateTiming();
                $closed++;
            }
        }

        return $closed;
    }

    public function reject(FieldDutyRequest $request, User $by, string $notes): void
    {
        if (trim($notes) === '') {
            throw new RuntimeException('Alasan penolakan wajib diisi.');
        }

        DB::transaction(function () use ($request, $by, $notes) {
            $locked = $this->lockPending($request);
            $this->assertCanDecide($locked, $by);

            $locked->update([
                'status' => FieldDutyRequest::STATUS_REJECTED,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                'review_notes' => trim($notes),
            ]);

            $this->notifyRequester($locked, approved: false);
        });
    }

    /** Pemohon menarik pengajuannya: selama masih menunggu, atau sudah disetujui untuk tanggal yang belum tiba (catatan Dinas Luar sehari penuh dihapus). */
    public function cancel(FieldDutyRequest $request, int $userId): void
    {
        DB::transaction(function () use ($request, $userId) {
            $locked = FieldDutyRequest::withoutGlobalScopes()->whereKey($request->id)->lockForUpdate()->first();

            if (! $locked || $locked->user_id !== $userId) {
                throw new RuntimeException('Pengajuan ini bukan milik Anda.');
            }

            if (! $this->isCancellable($locked)) {
                throw new RuntimeException('Pengajuan ini tidak bisa dibatalkan: sudah diputuskan, ditolak, atau tanggalnya sudah tiba.');
            }

            if ($locked->isApproved() && ! $locked->isPartialDay()) {
                Attendance::withoutGlobalScopes()
                    ->where('user_id', $locked->user_id)
                    ->whereDate('date', $locked->date->toDateString())
                    ->where('entry_type', 'field_duty')
                    ->whereNull('clock_in_at')
                    ->delete();
            }

            $locked->update(['status' => FieldDutyRequest::STATUS_CANCELLED]);
        });
    }

    public function isCancellable(FieldDutyRequest $request): bool
    {
        return $request->isPending() || ($request->isApproved() && $request->date->greaterThan(today()));
    }

    private function lockPending(FieldDutyRequest $request): FieldDutyRequest
    {
        $locked = FieldDutyRequest::withoutGlobalScopes()->whereKey($request->id)->lockForUpdate()->first();

        if (! $locked || ! $locked->isPending()) {
            throw new RuntimeException('Pengajuan ini sudah diputuskan sebelumnya.');
        }

        return $locked;
    }

    /**
     * Pemutus: akses penuh (semua toko); selain itu hanya untuk toko sendiri, yaitu store manager atau pengguna yang diberi aksi Ubah pada menu
     * Pengajuan Dinas Luar. Tidak pernah untuk pengajuan sendiri.
     */
    public function canDecide(User $by, FieldDutyRequest $request): bool
    {
        if ($request->user_id === $by->id) {
            return false;
        }

        if ($by->isFullAccess()) {
            return true;
        }

        return $by->store_id !== null
            && (int) $by->store_id === (int) $request->store_id
            && ($by->isStoreManager() || $by->hasModuleAction(FieldDutyRequestResource::class, 'update', false));
    }

    private function assertCanDecide(FieldDutyRequest $request, User $by): void
    {
        if ($request->user_id === $by->id) {
            throw new RuntimeException('Tidak boleh memutuskan pengajuan dinas luar milik sendiri.');
        }

        if (! $this->canDecide($by, $request)) {
            throw new RuntimeException('Hanya akses penuh, store manager toko ini, atau pengguna yang diberi hak memutuskan yang boleh memutuskan pengajuan dinas luar.');
        }
    }

    // ------------------------------------------------------------------ pemberitahuan

    private function notifyApprovers(FieldDutyRequest $request): void
    {
        $request->loadMissing('user:id,name');

        $recipients = User::where('is_active', true)
            ->get()
            ->filter(fn (User $u) => $this->canDecide($u, $request))
            ->pluck('id');

        app(\App\Services\StaffNotifier::class)->toUsers(
            $recipients,
            'Pengajuan Dinas Luar Baru',
            "{$request->user?->name} mengajukan dinas luar {$request->date->format('d M Y')} ({$request->windowLabel()}): {$request->description}",
            color: 'warning',
        );
    }

    private function notifyRequester(FieldDutyRequest $request, bool $approved): void
    {
        $when = "{$request->date->format('d M Y')} ({$request->windowLabel()})";

        app(\App\Services\StaffNotifier::class)->toUsers(
            [$request->user_id],
            $approved ? 'Pengajuan Dinas Luar Disetujui' : 'Pengajuan Dinas Luar Ditolak',
            $approved
                ? ($request->isPartialDay()
                    ? "Dinas luar {$when} disetujui. Telat dan pulang cepat pada rentang itu tidak dihitung; absen masuk dan keluar di toko tetap berlaku."
                    : "Dinas luar {$when} disetujui. Absensi hari itu tercatat sebagai Dinas Luar.")
                : "Dinas luar {$when} ditolak." . ($request->review_notes ? " Alasan: {$request->review_notes}" : ''),
            color: $approved ? 'success' : 'danger',
        );
    }
}
