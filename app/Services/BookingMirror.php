<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\MaterialMemo;
use App\Models\Spk;

/**
 * Fase 0 rombakan alur Booking, SPK, Memo (2026-11-27, keputusan pemilik 3): pelanggan dan kendaraan diisi SEKALI di Booking, dan SPK serta Memo
 * hanya membacanya. Implementasinya cermin otomatis: kolom pelanggan dan kendaraan di SPK dan Memo tetap ada (supaya pencarian, PDF, ekspor, dan
 * data lama tidak berubah), tetapi isinya tidak lagi bisa diketik bila booking-nya punya nilai. Setiap kali SPK atau Memo disimpan nilai dari
 * booking menimpa isian apa pun yang dikirim, dan setiap kali booking berubah semua SPK dan Memo anaknya ikut diperbarui.
 *
 * Hanya nilai yang terisi di booking yang dicerminkan. Booking tanpa data kendaraan (data lama, booking lama) tidak mengosongkan isian SPK atau
 * Memo; isian itu tetap bisa diketik sampai booking-nya dilengkapi.
 */
class BookingMirror
{
    /** Kolom booking yang bila berubah memicu pembaruan SPK dan Memo anaknya. */
    public const WATCHED = ['customer_name', 'phone_number', 'customer_id', 'vehicle_plate', 'vehicle_brand', 'vehicle_model', 'vehicle_variant', 'vehicle_year'];

    /** @return array<string, string> kolom SPK => nilai dari booking (hanya yang terisi) */
    public static function spkValues(Booking $booking): array
    {
        $booking->loadMissing('customer');

        return array_filter([
            'customer_name' => $booking->customer_name ?: $booking->customer?->name,
            'phone_number' => $booking->phone_number ?: $booking->customer?->phone_number,
            'vehicle_plate' => $booking->vehicle_plate,
            'vehicle_brand' => $booking->vehicle_brand,
            'vehicle_model' => $booking->vehicle_model,
            'vehicle_variant' => $booking->vehicle_variant,
            'vehicle_year' => $booking->vehicle_year ? (string) $booking->vehicle_year : null,
        ], fn ($value) => filled($value));
    }

    /** @return array<string, string|int> kolom Memo => nilai dari booking (hanya yang terisi) */
    public static function memoValues(Booking $booking): array
    {
        return array_filter([
            'vehicle_plate' => $booking->vehicle_plate,
            'vehicle_brand' => $booking->vehicle_brand,
            'vehicle_model' => $booking->vehicle_model,
            'vehicle_variant' => $booking->vehicle_variant,
            'vehicle_year' => $booking->vehicle_year ? (int) $booking->vehicle_year : null,
        ], fn ($value) => filled($value));
    }

    public static function bookingFor(?int $bookingId): ?Booking
    {
        return $bookingId ? Booking::withoutGlobalScopes()->find($bookingId) : null;
    }

    /** Dipanggil saat SPK disimpan: nilai dari booking menimpa isian. */
    public static function applyToSpk(Spk $spk): void
    {
        $booking = self::bookingFor($spk->booking_id);

        if (! $booking) {
            return;
        }

        foreach (self::spkValues($booking) as $column => $value) {
            $spk->{$column} = $value;
        }
    }

    /** Booking efektif sebuah memo: booking yang ditautkan langsung, atau booking milik SPK-nya (rantai Booking, SPK, Memo). */
    public static function bookingForMemo(MaterialMemo $memo): ?Booking
    {
        if ($memo->booking_id) {
            return self::bookingFor((int) $memo->booking_id);
        }

        $spkBookingId = $memo->spk_id ? Spk::withoutGlobalScopes()->whereKey($memo->spk_id)->value('booking_id') : null;

        return self::bookingFor($spkBookingId ? (int) $spkBookingId : null);
    }

    /** Dipanggil saat Memo disimpan: nilai kendaraan dari booking menimpa isian. */
    public static function applyToMemo(MaterialMemo $memo): void
    {
        $booking = self::bookingForMemo($memo);

        if (! $booking) {
            return;
        }

        foreach (self::memoValues($booking) as $column => $value) {
            $memo->{$column} = $value;
        }
    }

    /** Kolom SPK yang terkunci (dibaca dari booking), untuk layar yang menampilkannya baca-saja. */
    public static function lockedSpkFields(?Booking $booking): array
    {
        return $booking ? array_keys(self::spkValues($booking)) : [];
    }

    /** Dipanggil saat booking berubah: SPK dan Memo anaknya disimpan ulang, kait penyimpanan masing-masing yang menyalin nilai barunya. */
    public static function syncChildren(Booking $booking): void
    {
        $spkIds = Spk::withoutGlobalScopes()->where('booking_id', $booking->id)->pluck('id');

        Spk::withoutGlobalScopes()->whereIn('id', $spkIds)->get()->each->save();

        MaterialMemo::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('booking_id', $booking->id)->orWhereIn('spk_id', $spkIds))
            ->get()
            ->each->save();
    }
}
