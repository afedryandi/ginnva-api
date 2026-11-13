<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\Payroll;
use App\Models\User;

/**
 * Keputusan Izin & Cuti satu pintu (2026-11-13): dipakai tombol Setujui/Tolak di menu Izin & Cuti dan di Pusat Persetujuan, supaya akibatnya
 * sama persis di kedua tempat. Penguncian dan pengecekan hak memutuskan ada di LeaveRequest::approveLocked() / rejectLocked().
 */
class LeaveDecisionService
{
    /**
     * @return array{record: LeaveRequest, payroll_locked: bool}
     *
     * @throws \RuntimeException bila sudah diputuskan, atau pemutus tidak berhak, atau jatah cuti tidak cukup.
     */
    public function approve(int $id, User $by): array
    {
        $record = LeaveRequest::approveLocked($id, $by->id);

        // Bila izin ini baru disetujui SETELAH sebagian tanggalnya ditandai 'alpha' oleh job harian (attendance:mark-absences), baris itu
        // dikoreksi menjadi 'leave' supaya tidak tertinggal sebagai mangkir.
        Attendance::where('user_id', $record->user_id)
            ->whereBetween('date', [$record->start_date, $record->end_date])
            ->where('entry_type', 'alpha')
            ->update(['entry_type' => 'leave']);

        app(StaffNotifier::class)->toUsers(
            [$record->user_id],
            'Pengajuan Izin Disetujui',
            "Pengajuan {$record->request_number} ({$record->start_date->format('d M')}–{$record->end_date->format('d M Y')}) disetujui."
        );

        // Absensi tanggal lampau yang diubah dari Alpha mengubah potongan gaji; ingatkan bila slip bulan itu sudah dikunci.
        $locked = Payroll::where('user_id', $record->user_id)
            ->whereIn('status', ['pending_approval', 'paid'])
            ->whereDate('period_month', '>=', $record->start_date->copy()->startOfMonth()->toDateString())
            ->whereDate('period_month', '<=', $record->end_date->copy()->startOfMonth()->toDateString())
            ->exists();

        return ['record' => $record, 'payroll_locked' => $locked];
    }

    public function reject(int $id, User $by, string $note): LeaveRequest
    {
        $record = LeaveRequest::rejectLocked($id, $by->id, $note);

        app(StaffNotifier::class)->toUsers(
            [$record->user_id],
            'Pengajuan Izin Ditolak',
            "Pengajuan {$record->request_number} ditolak: {$note}"
        );

        return $record;
    }
}
