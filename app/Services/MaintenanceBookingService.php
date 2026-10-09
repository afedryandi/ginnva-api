<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Store;
use App\Models\WarrantyMaintenanceSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Membuat Booking Maintenance PPF dari satu occurrence jadwal. Dipakai dua jalur yang aturannya HARUS sama:
 * customer konfirmasi sendiri di aplikasi (MaintenanceScheduleController::confirm) dan staf/sales yang menghubungi
 * customer lalu membuatkan booking-nya (aksi "Buatkan Booking" di jadwal maintenance garansi, 2026-10-09).
 */
class MaintenanceBookingService
{
    /**
     * @param  string  $source  'app' (customer sendiri) atau 'whatsapp' (staf menindaklanjuti).
     * @return array{ok: bool, message: string, booking?: Booking, requires_new_date?: bool}
     */
    public function book(WarrantyMaintenanceSchedule $schedule, ?Carbon $requestedDay, string $source = 'app'): array
    {
        $schedule->loadMissing('warranty');
        $warranty = $schedule->warranty;

        if (! in_array($schedule->status, ['pending', 'confirmation_sent'], true)) {
            return $this->fail('Jadwal ini sudah diproses sebelumnya.');
        }

        if ($warranty->status === 'revoked') {
            return $this->fail('Garansi ini sudah dibatalkan, konfirmasi tidak bisa diproses.');
        }

        if (! $warranty->customer_id) {
            return $this->fail('Garansi ini belum terhubung ke akun customer, booking tidak bisa dibuat.');
        }

        if (! $warranty->store_id) {
            return $this->fail('Garansi ini tidak terhubung ke toko mana pun, konfirmasi tidak bisa diproses lewat app. Hubungi tim kami langsung.');
        }

        $requestedDay = $requestedDay?->copy()->startOfDay();

        // Tanggal baru (reschedule) opsional; WAJIB kalau jadwal sudah lewat masa toleransi (2026-10-09).
        if (! $requestedDay && $schedule->validUntil()->lt(today())) {
            return $this->fail('Jadwal maintenance ini sudah terlewat. Pilih tanggal baru untuk maintenance Anda.', requiresNewDate: true);
        }

        if ($requestedDay && ($requestedDay->lt(today()) || $requestedDay->gt(today()->addDays(30)))) {
            return $this->fail('Tanggal baru harus dalam 30 hari ke depan.');
        }

        // Masa toleransi: tanggal jadwal boleh sudah lewat, booking dibuat untuk hari ini (bukan tanggal lampau).
        $bookingDay = $requestedDay ?? Carbon::parse($schedule->scheduled_date)->max(today());

        $store = Store::find($warranty->store_id);

        if ($store && ! $store->is_active) {
            return $this->fail('Toko ini sudah tidak aktif. Hubungi tim kami langsung untuk menyesuaikan jadwal maintenance Anda.');
        }

        if ($store?->isClosedOn($bookingDay)) {
            return $this->fail('Toko tutup/libur pada tanggal jadwal ini. Hubungi toko langsung untuk menyesuaikan jadwal maintenance Anda.');
        }

        if (Booking::confirmedOverlapCount($warranty->store_id, $bookingDay) >= Booking::capacityForDate($warranty->store_id, $bookingDay)) {
            return $this->fail('Kapasitas toko pada tanggal jadwal ini sudah penuh. Hubungi toko langsung untuk menyesuaikan jadwal maintenance Anda.');
        }

        $booking = DB::transaction(function () use ($schedule, $warranty, $bookingDay, $requestedDay, $source) {
            $locked = WarrantyMaintenanceSchedule::whereKey($schedule->id)->lockForUpdate()->first();

            if (! in_array($locked->status, ['pending', 'confirmation_sent'], true)) {
                return null;
            }

            $booking = Booking::create([
                'customer_id'    => $warranty->customer_id,
                'store_id'       => $warranty->store_id,
                'service_type'   => 'Maintenance PPF',
                'preferred_date' => $bookingDay->toDateString(),
                'warranty_id'    => $warranty->id,
                'source'         => $source,
                'status'         => 'pending',
            ]);

            $locked->update([
                'status'         => 'confirmed',
                'booking_id'     => $booking->id,
                'responded_at'   => now(),
                // Reschedule: jadwal ikut pindah ke tanggal yang dipilih.
                'scheduled_date' => $requestedDay ? $bookingDay->toDateString() : $locked->scheduled_date,
            ]);

            return $booking;
        });

        if (! $booking) {
            return $this->fail('Jadwal ini sudah diproses sebelumnya.');
        }

        return ['ok' => true, 'message' => 'Konfirmasi diterima. Toko akan menghubungi Anda untuk finalisasi jadwal.', 'booking' => $booking];
    }

    private function fail(string $message, bool $requiresNewDate = false): array
    {
        return ['ok' => false, 'message' => $message] + ($requiresNewDate ? ['requires_new_date' => true] : []);
    }
}
