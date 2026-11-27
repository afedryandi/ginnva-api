<?php

namespace App\Services;

use App\Models\Booking;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Atur lama pengerjaan booking setelah SPK (keputusan pemilik 2026-11-27): lama PPF/Kaca Film baru diketahui sesudah kondisi kendaraan
 * diperiksa, jadi angka saat booking hanyalah perkiraan awal. Siapa pun yang memegang SPK (installer, staf toko, akses penuh) boleh
 * mengubahnya tanpa membuka kunci data inti booking. Satu tempat untuk aturannya, dipakai panel (SpkResource) dan API staf.
 *
 * Pelanggan diberi tahu lewat BookingObserver (push "Estimasi Pengerjaan Berubah") karena perubahan duration_days pada booking confirmed
 * mana pun, termasuk dari form Ubah, memicu notifikasi yang sama.
 */
class BookingDurationService
{
    public const MAX_DAYS = 14;

    /**
     * @throws RuntimeException pesan siap tampil ke pengguna.
     */
    public function change(Booking $booking, int $days, ?string $reason = null): Booking
    {
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new RuntimeException('Lama pengerjaan harus antara 1 dan ' . self::MAX_DAYS . ' hari kerja.');
        }

        return DB::transaction(function () use ($booking, $days, $reason) {
            $locked = Booking::where('id', $booking->id)->lockForUpdate()->first();

            if (! in_array($locked->status, ['pending', 'confirmed'], true)) {
                throw new RuntimeException("Booking berstatus \"{$locked->status}\" tidak bisa diubah lama pengerjaannya.");
            }

            // Layanan ringan (Detailing, Premium Wash) berharga dan berdurasi tetap: tidak ikut aturan ini.
            if ($locked->isLightOnly()) {
                throw new RuntimeException('Lama pengerjaan hanya bisa diatur untuk PPF dan Kaca Film.');
            }

            if ((int) $locked->duration_days === $days) {
                throw new RuntimeException('Lama pengerjaan sama dengan yang sekarang.');
            }

            // Memperpanjang menggeser hari terakhir ke hari yang mungkin sudah penuh: dicek ulang dengan lock toko (booking ini
            // dikecualikan dari hitungan). Memperpendek selalu aman. Booking pending belum memakai slot, jadi tidak dicek.
            if ($locked->status === 'confirmed' && $days > (int) $locked->duration_days) {
                $full = Booking::fullDatesInRangeLocked(
                    (int) $locked->store_id,
                    $locked->preferred_date->copy(),
                    $days,
                    excludeBookingId: $locked->id,
                );

                if (! empty($full)) {
                    throw new RuntimeException('Kapasitas toko penuh di tanggal: ' . implode(', ', $full) . '. Pilih lama yang lebih pendek, atau atur Kalender Kapasitas dulu.');
                }
            }

            $locked->rescheduleReason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
            $locked->forceFill(['duration_days' => $days])->save();

            return $locked->fresh();
        });
    }
}
