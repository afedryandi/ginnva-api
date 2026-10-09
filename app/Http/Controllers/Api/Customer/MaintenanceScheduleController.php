<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\WarrantyMaintenanceSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Bagian C, "Klaim Garansi & Maintenance PPF Terhubung ke Slot Booking"
 * (2026-10-01) -- customer konfirmasi/tolak occurrence jadwal maintenance
 * yang sudah ditentukan staff (interval + tanggal dihitung otomatis, lihat
 * WarrantyMaintenanceSchedule). TIDAK ada langkah pilih tanggal/toko di
 * sini -- semua sudah dari jadwal, customer tinggal konfirmasi/tolak.
 */
class MaintenanceScheduleController extends Controller
{
    /**
     * GET /api/customer/maintenance-schedules/pending
     * Dipakai banner reminder di halaman depan (bukan cuma push) --
     * diminta user 2026-10-01.
     */
    public function pending(Request $request)
    {
        $customerId = $request->user('customer')->id;

        $schedules = WarrantyMaintenanceSchedule::whereIn('status', ['pending', 'confirmation_sent'])
            // Garansi yang sudah dibatalkan tidak boleh menampilkan banner (konfirmasinya ditolak server, jadi kartunya
            // hanya membingungkan).
            ->whereHas('warranty', fn ($q) => $q->where('customer_id', $customerId)->where('status', '!=', 'revoked'))
            ->with('warranty:id,warranty_code')
            ->orderBy('scheduled_date')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $schedules->map(fn (WarrantyMaintenanceSchedule $s) => [
                'id'             => $s->id,
                'warranty_id'    => $s->warranty_id,
                'warranty_code'  => $s->warranty->warranty_code,
                'scheduled_date' => $s->scheduled_date->format('Y-m-d'),
                'valid_until'    => $s->validUntil()->format('Y-m-d'),
                'status'         => $s->status,
            ]),
        ]);
    }

    private function authorizeOwn(Request $request, int $id): WarrantyMaintenanceSchedule
    {
        $schedule = WarrantyMaintenanceSchedule::with('warranty')->findOrFail($id);

        if ($schedule->warranty?->customer_id !== $request->user('customer')->id) {
            abort(404);
        }

        return $schedule;
    }

    /**
     * POST /api/customer/maintenance-schedules/{id}/confirm
     * Langsung membuat Booking (status 'pending') di scheduled_date yang
     * sudah ditentukan -- kapasitas tetap otomatis ikut hitungan existing
     * (Booking::fullDatesInRange()), baru memakan slot pas staff confirm.
     */
    public function confirm(Request $request, int $id)
    {
        $schedule = $this->authorizeOwn($request, $id);

        // Tanggal baru (reschedule) opsional; wajib kalau jadwal sudah lewat masa toleransi (lihat MaintenanceBookingService).
        $request->validate(['preferred_date' => 'nullable|date_format:Y-m-d']);
        $requestedDay = $request->filled('preferred_date')
            ? \Illuminate\Support\Carbon::parse($request->input('preferred_date'))
            : null;

        $result = app(\App\Services\MaintenanceBookingService::class)->book($schedule, $requestedDay, 'app');

        if (! $result['ok']) {
            return response()->json(['success' => false] + array_filter([
                'requires_new_date' => $result['requires_new_date'] ?? null,
            ]) + ['message' => $result['message']], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data'    => ['booking' => $result['booking']],
        ], 201);
    }

    /**
     * POST /api/customer/maintenance-schedules/{id}/decline
     * Hanguskan occurrence ini -- otomatis siapkan occurrence berikutnya
     * selama kuota belum habis (lihat WarrantyMaintenanceSchedule::forfeit()).
     */
    public function decline(Request $request, int $id)
    {
        $schedule = $this->authorizeOwn($request, $id);

        if (! in_array($schedule->status, ['pending', 'confirmation_sent'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Jadwal ini sudah diproses sebelumnya.',
            ], 422);
        }

        $schedule->forfeit(explicit: true);

        return response()->json([
            'success' => true,
            'message' => 'Jadwal maintenance ini ditandai tidak jadi datang.',
        ]);
    }
}
