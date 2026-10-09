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

        if (! in_array($schedule->status, ['pending', 'confirmation_sent'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Jadwal ini sudah diproses sebelumnya.',
            ], 422);
        }

        $warranty = $schedule->warranty;

        // Bug ditutup 2026-10-01 (audit Maintenance PPF) -- SEBELUMNYA tidak
        // dicek sama sekali, customer masih bisa konfirmasi (bikin Booking
        // baru) untuk garansi yang sudah di-revoke staff.
        if ($warranty->status === 'revoked') {
            return response()->json([
                'success' => false,
                'message' => 'Garansi ini sudah dibatalkan, konfirmasi tidak bisa diproses.',
            ], 422);
        }

        if (! $warranty->store_id) {
            return response()->json([
                'success' => false,
                'message' => 'Garansi ini tidak terhubung ke toko mana pun, konfirmasi tidak bisa diproses lewat app. Hubungi tim kami langsung.',
            ], 422);
        }

        // Gap ditutup 2026-10-01 (audit Bagian C) -- SEBELUMNYA tidak dicek
        // sama sekali, beda dari Bagian B (storeClaim()). scheduled_date di
        // sini dihitung OTOMATIS dari interval (bukan dipilih customer),
        // jadi bisa saja jatuh di hari toko tutup/libur -- tidak ada langkah
        // pilih tanggal lain di alur ini, jadi staff yang perlu dihubungi.
        // Tanggal jadwal sudah lewat: tidak bisa dikonfirmasi (nanti dihanguskan
        // otomatis oleh ProcessMaintenanceSchedules).
        if ($schedule->validUntil()->lt(today())) {
            return response()->json([
                'success' => false,
                'message' => 'Masa berlaku jadwal maintenance ini sudah lewat. Hubungi toko langsung untuk mengatur jadwal baru.',
            ], 422);
        }

        // Masa toleransi: tanggal jadwal boleh sudah lewat, booking dibuat untuk hari ini (bukan tanggal lampau).
        $bookingDay = \Illuminate\Support\Carbon::parse($schedule->scheduled_date)->max(today());

        $store = \App\Models\Store::find($warranty->store_id);

        // Bug ditutup 2026-10-01 (audit Maintenance PPF) -- SEBELUMNYA tidak
        // dicek sama sekali, customer masih bisa konfirmasi ke toko yang
        // sudah dinonaktifkan (is_active=false).
        if ($store && ! $store->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Toko ini sudah tidak aktif. Hubungi tim kami langsung untuk menyesuaikan jadwal maintenance Anda.',
            ], 422);
        }

        if ($store?->isClosedOn($bookingDay)) {
            return response()->json([
                'success' => false,
                'message' => 'Toko tutup/libur pada tanggal jadwal ini. Hubungi toko langsung untuk menyesuaikan jadwal maintenance Anda.',
            ], 422);
        }

        // Kapasitas tanggal jadwal juga dicek (2026-10-02), sama dengan
        // booking biasa -- sebelumnya jalur ini melewatinya.
        if (\App\Models\Booking::confirmedOverlapCount($warranty->store_id, $bookingDay) >= \App\Models\Booking::capacityForDate($warranty->store_id, $bookingDay)) {
            return response()->json([
                'success' => false,
                'message' => 'Kapasitas toko pada tanggal jadwal ini sudah penuh. Hubungi toko langsung untuk menyesuaikan jadwal maintenance Anda.',
            ], 422);
        }

        $booking = DB::transaction(function () use ($schedule, $warranty, $request, $bookingDay) {
            $locked = WarrantyMaintenanceSchedule::whereKey($schedule->id)->lockForUpdate()->first();

            if (! in_array($locked->status, ['pending', 'confirmation_sent'], true)) {
                return null;
            }

            $booking = Booking::create([
                'customer_id'    => $request->user('customer')->id,
                'store_id'       => $warranty->store_id,
                'service_type'   => 'Maintenance PPF',
                'preferred_date' => $bookingDay->toDateString(),
                'warranty_id'    => $warranty->id,
                'source'         => 'app',
                'status'         => 'pending',
            ]);

            $locked->update([
                'status'       => 'confirmed',
                'booking_id'   => $booking->id,
                'responded_at' => now(),
            ]);

            return $booking;
        });

        if (! $booking) {
            return response()->json([
                'success' => false,
                'message' => 'Jadwal ini sudah diproses sebelumnya.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Konfirmasi diterima. Toko akan menghubungi Anda untuk finalisasi jadwal.',
            'data'    => ['booking' => $booking],
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
