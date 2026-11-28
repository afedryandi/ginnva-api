<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceCorrectionRequest;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * "Alur approval utk absensi anomali" (audit Majoo, f24) — satu-satunya
 * jalur resmi ajukan/approve/reject koreksi absensi dari staff non-
 * manager, pola sama dengan TransactionApprovalService (segregation of
 * duties, audit framework 2026-09-14): store_manager/full-access TETAP
 * bisa edit langsung lewat AttendanceResource, TIDAK lewat service ini
 * (self-approval tidak menambah kontrol apa pun).
 */
class AttendanceCorrectionService
{
    /**
     * @param array{attendance_id: ?int, user_id: int, store_id: int, date: string, entry_type: string, clock_in_at: ?string, clock_out_at: ?string, reason: string} $data
     *
     * @throws RuntimeException bila pengajuan tidak valid (aturan yang sama untuk aplikasi dan panel).
     */
    public function submit(array $data, int $requestedBy): AttendanceCorrectionRequest
    {
        $this->assertValid($data);

        $request = AttendanceCorrectionRequest::create($data + [
            'status' => AttendanceCorrectionRequest::STATUS_PENDING,
            'requested_by' => $requestedBy,
        ]);

        $this->notifyApprovers($request);

        return $request;
    }

    /**
     * Pengajuan koreksi oleh karyawan untuk dirinya sendiri (lupa absen, isi jam manual): dipakai aplikasi staf dan halaman Absen Saya di panel
     * (2026-11-22), supaya aturannya satu. Hanya jenis 'manual'; Dinas Luar, Alpha, dan Izin tetap lewat jalurnya sendiri.
     *
     * @param string|null $clockIn  jam "HH:MM" di tanggal koreksi, atau null
     * @param string|null $clockOut jam "HH:MM" di tanggal koreksi, atau null
     *
     * @throws RuntimeException
     */
    public function submitForStaff(User $user, string $date, ?string $clockIn, ?string $clockOut, string $reason): AttendanceCorrectionRequest
    {
        if (! $user->store_id) {
            throw new RuntimeException('Akun ini belum terhubung ke toko mana pun, tidak bisa mengajukan koreksi.');
        }

        $day = Carbon::parse($date)->startOfDay();

        if ($day->isFuture()) {
            throw new RuntimeException('Tanggal koreksi tidak boleh di masa depan.');
        }

        $existing = \App\Models\Attendance::where('user_id', $user->id)->where('date', $day->toDateString())->first();

        return $this->submit([
            'attendance_id' => $existing?->id,
            'user_id' => $user->id,
            'store_id' => $user->store_id,
            'date' => $day->toDateString(),
            'entry_type' => 'manual',
            'clock_in_at' => filled($clockIn) ? $day->copy()->setTimeFromTimeString($clockIn) : null,
            'clock_out_at' => filled($clockOut) ? $day->copy()->setTimeFromTimeString($clockOut) : null,
            'reason' => $reason,
        ], $user->id);
    }

    /**
     * Aturan pengajuan: minimal satu jam, jam keluar setelah jam masuk, jam jatuh di tanggal absensi, dan satu pengajuan menunggu per
     * karyawan per tanggal (supaya penyetuju tidak menghadapi dua pengajuan yang saling menimpa).
     */
    private function assertValid(array $data): void
    {
        $in = ! empty($data['clock_in_at']) ? Carbon::parse($data['clock_in_at']) : null;
        $out = ! empty($data['clock_out_at']) ? Carbon::parse($data['clock_out_at']) : null;

        if (! $in && ! $out) {
            throw new RuntimeException('Isi minimal salah satu: jam masuk atau jam keluar.');
        }

        $date = Carbon::parse($data['date'])->toDateString();

        foreach (['Jam masuk' => $in, 'Jam keluar' => $out] as $label => $time) {
            if ($time && $time->toDateString() !== $date) {
                throw new RuntimeException("{$label} harus di tanggal yang sama dengan tanggal absensi.");
            }
        }

        if ($in && $out && $out->lessThanOrEqualTo($in)) {
            throw new RuntimeException('Jam keluar harus setelah jam masuk.');
        }

        $hasPending = AttendanceCorrectionRequest::where('user_id', $data['user_id'])
            ->whereDate('date', $date)
            ->where('status', AttendanceCorrectionRequest::STATUS_PENDING)
            ->exists();

        if ($hasPending) {
            throw new RuntimeException('Sudah ada pengajuan koreksi untuk tanggal ini yang masih menunggu keputusan.');
        }
    }

    /**
     * Approver = full-access (mana pun tokonya) + store_manager TOKO
     * yang sama dengan permintaan ini -- store_manager toko lain tidak
     * relevan/tidak perlu tahu.
     */
    private function notifyApprovers(AttendanceCorrectionRequest $request): void
    {
        $recipients = User::where('is_active', true)
            ->get()
            ->filter(fn (User $u) => $u->isFullAccess() || ($u->canDecideAttendanceCorrections() && $u->store_id === $request->store_id));

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title('Permintaan koreksi absensi baru')
                ->body("{$request->user?->name} mengajukan koreksi absensi tanggal {$request->date->format('d M Y')}.")
                ->warning()
                ->actions(\App\Support\PanelNotificationLink::actions(\App\Filament\Pages\ApprovalCenter::class))
                ->sendToDatabase($recipient);
        }

        $request->loadMissing('user:id,name', 'store:id,name');

        app(ApprovalMailer::class)->requested(
            'Koreksi Absen',
            $request->user?->name ?? '—',
            $request->date->translatedFormat('d M Y') . ': masuk ' . ($request->clock_in_at?->format('H:i') ?? '—') . ', keluar ' . ($request->clock_out_at?->format('H:i') ?? '—') . ' · ' . $request->reason,
            $request->store?->name,
            $recipients->pluck('id'),
        );
    }

    /**
     * Bug diperbaiki 2026-09-27 (audit Koreksi Absensi) -- SEBELUMNYA
     * isPending() dicek DI LUAR lock/transaction, celah TOCTOU: 2 admin
     * approve/reject permintaan yang sama nyaris bersamaan bisa
     * dua-duanya lolos cek "masih pending" sebelum salah satu commit,
     * hasil akhirnya jadi klik TERAKHIR yang menang tanpa jejak jelas
     * siapa yang benar-benar memutuskan lebih dulu. Pola sama dengan
     * lockForUpdate() DI DALAM DB::transaction() yang sudah wajib di
     * modul finansial lain sesi ini (BookingPostingService/RefundService/
     * dst) -- isPending() sekarang dicek ULANG pada baris yang SUDAH
     * dikunci, bukan objek $request lama di memori.
     */
    /**
     * Dinas luar sehari penuh yang sudah disetujui di tanggal itu. Kehadirannya tercatat sebagai baris 'field_duty'; koreksi yang disetujui akan menimpa baris itu
     * menjadi absen biasa, jadi pengaju dan penyetuju perlu diberi tahu (lihat approve()).
     */
    public function fullDayFieldDutyOn(int $userId, string $date): ?\App\Models\FieldDutyRequest
    {
        return \App\Models\FieldDutyRequest::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->whereDate('date', $date)
            ->where('status', \App\Models\FieldDutyRequest::STATUS_APPROVED)
            ->whereNull('start_time')
            ->whereNull('end_time')
            ->first();
    }

    /**
     * @param  bool  $replaceFieldDuty  harus true bila hari itu tercatat Dinas Luar sehari penuh: koreksi menggantinya menjadi absen biasa, dan itu hanya boleh
     *                                  atas konfirmasi penyetuju (kotak centang di halaman Koreksi Absensi), tidak diam-diam.
     */
    public function approve(AttendanceCorrectionRequest $request, int $approvedBy, ?string $notes = null, bool $replaceFieldDuty = false): Attendance
    {
        return DB::transaction(function () use ($request, $approvedBy, $notes, $replaceFieldDuty) {
            $locked = AttendanceCorrectionRequest::whereKey($request->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isPending()) {
                throw new RuntimeException('Permintaan ini sudah diputuskan sebelumnya.');
            }

            // Bug diperbaiki 2026-09-29 (audit Absensi) -- SEBELUMNYA tidak ada pengecekan sama
            // sekali: store_manager bisa ajukan koreksi absensinya SENDIRI lewat mobile app, lalu
            // approve sendiri lewat Filament (requested_by === approvedBy). Guard ini ditegakkan DI
            // DALAM lock (bukan cuma disembunyikan di tombol UI) supaya tidak bisa dilewati lewat
            // pemanggilan langsung, pola sama segregation-of-duties di TransactionApprovalService.
            if ($locked->requested_by === $approvedBy) {
                throw new RuntimeException('Tidak boleh menyetujui pengajuan koreksi absensi milik sendiri.');
            }

            // Izin eksplisit mengalahkan peran (2026-11-28): penegakan di layanan, bukan hanya tombol.
            $decider = User::find($approvedBy);
            if (! $decider || ! $decider->canDecideAttendanceCorrections()) {
                throw new RuntimeException('Akun ini tidak punya izin memutuskan koreksi absensi.');
            }

            // Jam yang tidak diisi pengaju tetap memakai jam yang sudah tercatat (mis. hanya melengkapi jam keluar), bukan dikosongkan.
            $existing = Attendance::where('user_id', $locked->user_id)->where('date', $locked->date->toDateString())->first();
            if (! $replaceFieldDuty && $existing?->entry_type === 'field_duty') {
                throw new RuntimeException('Tanggal ini tercatat Dinas Luar sehari penuh yang sudah disetujui. Menyetujui koreksi akan mengganti Dinas Luar menjadi absen biasa. Setujui dari halaman Koreksi Absensi dengan mencentang konfirmasinya, atau tolak koreksi ini.');
            }

            $clockIn = $locked->clock_in_at ?? $existing?->clock_in_at;
            $clockOut = $locked->clock_out_at ?? $existing?->clock_out_at;

            if ($clockIn && $clockOut && $clockOut->lessThanOrEqualTo($clockIn)) {
                throw new RuntimeException('Jam keluar usulan tidak boleh sebelum jam masuk yang sudah tercatat. Tolak pengajuan ini dan minta diajukan ulang dengan kedua jam.');
            }

            $attendance = Attendance::updateOrCreate(
                ['user_id' => $locked->user_id, 'date' => $locked->date->toDateString()],
                [
                    'store_id' => $locked->store_id,
                    'entry_type' => $locked->entry_type,
                    'clock_in_at' => $clockIn,
                    'clock_out_at' => $clockOut,
                    'note' => $locked->reason,
                    'recorded_by' => $approvedBy,
                ]
            );

            // Jam masuk/keluar berubah -> menit telat/pulang cepat lama tidak boleh menempel (dipakai Payroll).
            $attendance->recalculateTiming();

            $locked->update([
                'attendance_id' => $attendance->id,
                'status' => AttendanceCorrectionRequest::STATUS_APPROVED,
                'reviewed_by' => $approvedBy,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ]);

            // Bug diperbaiki 2026-09-27 (audit Koreksi Absensi) --
            // SEBELUMNYA staff pengaju TIDAK PERNAH diberi tahu hasilnya,
            // harus buka app manual & cek status sendiri.
            $this->notifyRequester($locked, approved: true);

            return $attendance;
        });
    }

    public function reject(AttendanceCorrectionRequest $request, int $rejectedBy, ?string $notes = null): void
    {
        DB::transaction(function () use ($request, $rejectedBy, $notes) {
            $locked = AttendanceCorrectionRequest::whereKey($request->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isPending()) {
                throw new RuntimeException('Permintaan ini sudah diputuskan sebelumnya.');
            }

            // Bug diperbaiki 2026-09-29 (audit Absensi) -- sama alasan dengan approve(), demi
            // konsistensi (menolak permintaan sendiri juga tidak boleh, walau dampaknya kecil).
            if ($locked->requested_by === $rejectedBy) {
                throw new RuntimeException('Tidak boleh memutuskan pengajuan koreksi absensi milik sendiri.');
            }

            // Izin eksplisit mengalahkan peran (2026-11-28): penegakan di layanan, bukan hanya tombol.
            $decider = User::find($rejectedBy);
            if (! $decider || ! $decider->canDecideAttendanceCorrections()) {
                throw new RuntimeException('Akun ini tidak punya izin memutuskan koreksi absensi.');
            }

            $locked->update([
                'status' => AttendanceCorrectionRequest::STATUS_REJECTED,
                'reviewed_by' => $rejectedBy,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ]);

            $this->notifyRequester($locked, approved: false);
        });
    }

    /**
     * Pengaju menarik kembali pengajuannya selama masih menunggu (salah isi jam, atau tidak jadi). Hanya pengaju sendiri; yang sudah diputuskan
     * tidak bisa ditarik. Tanggalnya langsung bebas untuk pengajuan baru.
     */
    public function cancel(AttendanceCorrectionRequest $request, int $cancelledBy): void
    {
        DB::transaction(function () use ($request, $cancelledBy) {
            $locked = AttendanceCorrectionRequest::whereKey($request->id)->lockForUpdate()->first();

            if (! $locked || $locked->requested_by !== $cancelledBy) {
                throw new RuntimeException('Pengajuan ini bukan milik akun ini.');
            }

            if (! $locked->isPending()) {
                throw new RuntimeException('Cuma pengajuan yang masih menunggu keputusan yang bisa dibatalkan.');
            }

            $locked->update(['status' => AttendanceCorrectionRequest::STATUS_CANCELLED]);
        });
    }

    private function notifyRequester(AttendanceCorrectionRequest $request, bool $approved): void
    {
        if (! $request->requested_by) {
            return;
        }

        $title = $approved ? 'Koreksi Absensi Disetujui' : 'Koreksi Absensi Ditolak';
        $body = $approved
            ? "Pengajuan koreksi absensi tanggal {$request->date->format('d M Y')} sudah disetujui."
            : "Pengajuan koreksi absensi tanggal {$request->date->format('d M Y')} ditolak."
                . ($request->review_notes ? " Alasan: {$request->review_notes}" : '');

        // Lewat StaffNotifier supaya staf yang hanya memakai panel (tanpa aplikasi) tetap menerima kabar di lonceng.
        app(\App\Services\StaffNotifier::class)->toUsers([$request->requested_by], $title, $body, color: $approved ? 'success' : 'danger');
    }
}
