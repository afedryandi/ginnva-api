<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\AppSetting;
use App\Models\BookingServiceType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pengingat booking confirmed. Pelanggan menerima H-2, H-1, dan pagi hari-H (Fase 4 pengerjaan ulang booking, 2026-10-20);
 * installer yang ditugaskan menerima H-1. Pesan menyebut layanan, toko, tanggal, dan jam. Tiap pengingat membuka layar
 * Booking Saya di aplikasi, tempat ubah jadwal dan batal tersedia (tautan aksi satu ketukan).
 *
 * Dipakai command harian (08:00 & 16:00) DAN langsung dari BookingObserver kalau booking baru dikonfirmasi/dipindah ke
 * "besok" setelah jam 16:00 (tidak ada run terjadwal lagi sebelum besok).
 */
class BookingReminderService
{
    /** Rute aplikasi pelanggan yang memuat tombol ubah jadwal / batal. */
    private const CUSTOMER_ROUTE = '/account/my-bookings';

    public function __construct(private PushNotificationService $push)
    {
    }

    /** Kirim H-2 ke pelanggan; true bila terkirim dan ditandai. */
    public function sendH2(Booking $b): bool
    {
        return $this->sendToCustomer(
            $b,
            'h2_reminder_sent_at',
            'booking_reminder_h2',
            'Pengingat Jadwal 2 Hari Lagi',
            fn (string $detail) => "Booking {$detail} tinggal 2 hari lagi. Perlu ubah jadwal? Ketuk untuk mengajukannya. " . static::policyLine(),
            withReschedule: true,
        );
    }

    /** Kirim pengingat pagi hari-H ke pelanggan; true bila terkirim dan ditandai. */
    public function sendDayOf(Booking $b): bool
    {
        return $this->sendToCustomer(
            $b,
            'dayof_reminder_sent_at',
            'booking_reminder_dayof',
            'Jadwal Anda Hari Ini',
            fn (string $detail) => "Hari ini jadwal Anda: {$detail}. Sampai jumpa!",
        );
    }

    /**
     * Kirim H-1 untuk satu booking (pelanggan + installer) & tandai terkirim. Return true kalau terkirim (false kalau
     * tidak memenuhi syarat atau push gagal).
     */
    public function sendH1(Booking $b): bool
    {
        if ($b->status !== 'confirmed' || $b->h1_reminder_sent_at !== null) {
            return false;
        }

        try {
            if ($b->customer_id) {
                $this->push->sendToCustomer(
                    $b->customer_id,
                    'Pengingat Jadwal Besok',
                    "Besok jadwal Anda: {$this->detail($b)}. Jika berhalangan, ubah jadwal atau batalkan dari booking Anda.",
                    ['type' => 'booking_reminder_h1', 'booking_id' => $b->id, 'route' => self::CUSTOMER_ROUTE, 'params' => ['reschedule' => (string) $b->id]]
                );
            }

            $installerIds = $b->installers()->pluck('users.id');
            if ($installerIds->isNotEmpty()) {
                app(\App\Services\StaffNotifier::class)->toUsers(
                    $installerIds,
                    'Jadwal Instalasi Besok',
                    "Besok ada jadwal booking #{$b->booking_number}.",
                    ['type' => 'booking_reminder_h1', 'booking_id' => $b->id, 'route' => "/staff/bookings/{$b->id}"]
                );
            }
        } catch (\Throwable $e) {
            report($e);

            return false; // jangan tandai terkirim kalau push gagal
        }

        DB::table('bookings')->where('id', $b->id)->update(['h1_reminder_sent_at' => now()]);

        return true;
    }

    /** Satu kalimat kebijakan pembatalan (dipotong) untuk badan push; kosong bila admin mengosongkannya. */
    public static function policyLine(): string
    {
        $policy = trim(AppSetting::get(AppSetting::CANCELLATION_POLICY));

        return $policy === '' ? '' : 'Kebijakan: ' . Str::limit($policy, 110);
    }

    private function sendToCustomer(Booking $b, string $flagColumn, string $type, string $title, \Closure $body, bool $withReschedule = false): bool
    {
        if ($b->status !== 'confirmed' || ! $b->customer_id || $b->{$flagColumn} !== null) {
            return false;
        }

        try {
            $this->push->sendToCustomer(
                $b->customer_id,
                $title,
                $body($this->detail($b)),
                ['type' => $type, 'booking_id' => $b->id, 'route' => self::CUSTOMER_ROUTE]
                + ($withReschedule ? ['params' => ['reschedule' => (string) $b->id]] : [])
            );
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        DB::table('bookings')->where('id', $b->id)->update([$flagColumn => now()]);

        return true;
    }

    /** "Premium Wash di Toko Kemang, 22 Okt 2026 pukul 09:00 - 11:00": layanan, toko, tanggal, jam. */
    private function detail(Booking $b): string
    {
        $services = collect($b->serviceKeys())->map(fn (string $key) => BookingServiceType::labelFor($key))->implode(' + ');
        $services = $services !== '' ? $services : ((string) $b->service_type ?: 'layanan');

        $detail = $services;

        if ($store = $b->store?->name) {
            $detail .= " di {$store}";
        }

        $detail .= ', ' . $b->preferred_date->translatedFormat('j M Y');

        if (filled($b->preferred_time)) {
            $detail .= " pukul {$b->preferred_time}";
        }

        return $detail;
    }
}
