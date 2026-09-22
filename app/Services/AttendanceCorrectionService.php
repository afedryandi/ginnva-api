<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceCorrectionRequest;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
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
     */
    public function submit(array $data, int $requestedBy): AttendanceCorrectionRequest
    {
        $request = AttendanceCorrectionRequest::create($data + [
            'status' => AttendanceCorrectionRequest::STATUS_PENDING,
            'requested_by' => $requestedBy,
        ]);

        $this->notifyApprovers($request);

        return $request;
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
            ->filter(fn (User $u) => $u->isFullAccess() || ($u->isStoreManager() && $u->store_id === $request->store_id));

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title('Permintaan koreksi absensi baru')
                ->body("{$request->user?->name} mengajukan koreksi absensi tanggal {$request->date->format('d M Y')}.")
                ->warning()
                ->sendToDatabase($recipient);
        }
    }

    public function approve(AttendanceCorrectionRequest $request, int $approvedBy, ?string $notes = null): Attendance
    {
        if (! $request->isPending()) {
            throw new RuntimeException('Permintaan ini sudah diputuskan sebelumnya.');
        }

        return DB::transaction(function () use ($request, $approvedBy, $notes) {
            $attendance = Attendance::updateOrCreate(
                ['user_id' => $request->user_id, 'date' => $request->date->toDateString()],
                [
                    'store_id' => $request->store_id,
                    'entry_type' => $request->entry_type,
                    'clock_in_at' => $request->clock_in_at,
                    'clock_out_at' => $request->clock_out_at,
                    'note' => $request->reason,
                    'recorded_by' => $approvedBy,
                ]
            );

            $request->update([
                'attendance_id' => $attendance->id,
                'status' => AttendanceCorrectionRequest::STATUS_APPROVED,
                'reviewed_by' => $approvedBy,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ]);

            return $attendance;
        });
    }

    public function reject(AttendanceCorrectionRequest $request, int $rejectedBy, ?string $notes = null): void
    {
        if (! $request->isPending()) {
            throw new RuntimeException('Permintaan ini sudah diputuskan sebelumnya.');
        }

        $request->update([
            'status' => AttendanceCorrectionRequest::STATUS_REJECTED,
            'reviewed_by' => $rejectedBy,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);
    }
}
