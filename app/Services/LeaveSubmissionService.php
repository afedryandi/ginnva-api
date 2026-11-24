<?php

namespace App\Services;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\SensitiveFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Pengajuan Izin/Cuti/Sakit oleh karyawan untuk dirinya sendiri (2026-11-22). Aturannya DIPINDAH dari StaffAttendanceController::leaveRequestsStore
 * tanpa diubah, supaya aplikasi staf dan halaman Absen Saya di panel memakai jalur yang sama: hanya SAKIT yang boleh diajukan susulan
 * (LeaveRequest::SICK_BACKDATE_DAYS hari), izin dan cuti sebelum hari H; batas hari per jenis; tidak tumpang tindih; jatah cuti tahunan cukup.
 * Penjaga sebenarnya untuk tumpang tindih dan jatah tetap LeaveRequest::createLocked() (cek di dalam kunci baris).
 */
class LeaveSubmissionService
{
    /**
     * @param int|null    $leaveTypeId jenis dari daftar LeaveType (aplikasi baru dan panel)
     * @param string|null $category    kategori lama ('izin','sakit','cuti') untuk aplikasi lama yang belum mengirim leave_type_id
     *
     * @throws ValidationException soal tanggal susulan (agar aplikasi tetap menerima galat per kolom seperti sebelumnya)
     * @throws RuntimeException    aturan lain; pesannya langsung boleh ditampilkan ke karyawan
     */
    public function submit(User $user, ?int $leaveTypeId, ?string $category, string $startDate, string $endDate, string $reason, ?UploadedFile $document = null): LeaveRequest
    {
        if (! $user->store_id) {
            throw new RuntimeException('Akun ini belum terhubung ke toko mana pun, tidak bisa mengajukan izin.');
        }

        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();

        if ($end->lt($start)) {
            throw new RuntimeException('Tanggal selesai tidak boleh sebelum tanggal mulai.');
        }

        $dayCount = $start->diffInDays($end) + 1;

        $leaveType = $leaveTypeId ? LeaveType::find($leaveTypeId) : LeaveType::forCategory((string) $category);
        $maxDays = $leaveType?->max_days ?: LeaveRequest::MAX_DURATION_DAYS;

        if ($start->lt(today())) {
            $kind = $leaveType?->category ?? (string) $category;

            if ($kind !== 'sakit') {
                throw ValidationException::withMessages(['start_date' => 'Izin dan cuti harus diajukan sebelum hari H.']);
            }

            if ($start->lt(today()->subDays(LeaveRequest::SICK_BACKDATE_DAYS))) {
                throw ValidationException::withMessages(['start_date' => 'Sakit hanya bisa diajukan di hari H atau sehari sesudahnya. Untuk yang lebih lama, hubungi admin.']);
            }
        }

        if ($dayCount > $maxDays) {
            throw new RuntimeException(
                $leaveType?->max_days
                    ? "{$leaveType->name} maksimal {$leaveType->max_days} hari."
                    : 'Durasi pengajuan maksimal ' . LeaveRequest::MAX_DURATION_DAYS . ' hari. Hubungi admin untuk kasus khusus di luar itu.'
            );
        }

        if (LeaveRequest::hasOverlap($user, $startDate, $endDate)) {
            throw new RuntimeException('Sudah ada pengajuan izin/cuti lain yang tanggalnya tumpang tindih dengan rentang ini.');
        }

        if ($leaveType ? $leaveType->deducts_annual_quota : $category === 'cuti') {
            $remaining = LeaveRequest::remainingCutiFor($user, $start->year);

            if ($dayCount > $remaining) {
                throw new RuntimeException("Sisa jatah cuti tahun ini tinggal {$remaining} hari, tidak cukup untuk {$dayCount} hari yang diajukan.");
            }
        }

        $documentPath = $document ? SensitiveFiles::store($document, 'leave-requests') : null;

        try {
            $leaveRequest = LeaveRequest::createLocked([
                'user_id' => $user->id,
                'store_id' => $user->store_id,
                'type' => $category,
                'leave_type_id' => $leaveTypeId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'reason' => $reason,
                'document' => $documentPath,
                'status' => 'pending',
            ]);
        } catch (RuntimeException $e) {
            // Lampiran yang sudah terlanjur terunggah dihapus supaya tidak jadi berkas yatim bila ternyata gagal (balapan tumpang tindih atau jatah).
            if ($documentPath) {
                SensitiveFiles::delete($documentPath);
            }

            throw $e;
        }

        $leaveRequest->notifyApprovers();

        return $leaveRequest;
    }

    /** Karyawan menarik kembali pengajuannya sendiri selama masih menunggu keputusan. */
    public function cancel(LeaveRequest $leaveRequest, User $user): void
    {
        if ((int) $leaveRequest->user_id !== (int) $user->id) {
            throw new RuntimeException('Pengajuan tidak ditemukan.');
        }

        if ($leaveRequest->status !== 'pending') {
            throw new RuntimeException('Cuma pengajuan yang masih menunggu persetujuan yang bisa dibatalkan.');
        }

        $leaveRequest->update(['status' => 'cancelled']);
    }
}
