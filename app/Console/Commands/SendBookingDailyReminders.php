<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\User;
use App\Services\PushNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pengingat harian booking (keputusan user 2026-10-06):
 *  1. H-1: booking confirmed yang jadwalnya BESOK diingatkan ke customer dan
 *     installer yang ditugaskan (sekali per booking, h1_reminder_sent_at).
 *  2. Perlu tindakan: booking confirmed yang tanggalnya sudah lewat tapi
 *     belum dikerjakan (belum ada tahap) -- ringkasan ke Store Manager toko
 *     itu tiap hari sampai ditindak. TIDAK ada pembatalan/perubahan status
 *     otomatis.
 */
class SendBookingDailyReminders extends Command
{
    protected $signature = 'bookings:daily-reminders';

    protected $description = 'Pengingat H-1 booking confirmed & daftar booking lewat tanggal yang perlu tindakan';

    public function handle(PushNotificationService $push): int
    {
        $tomorrow = Booking::where('status', 'confirmed')
            ->whereDate('preferred_date', today()->addDay())
            ->whereNull('h1_reminder_sent_at')
            ->with('installers:id')
            ->get();

        foreach ($tomorrow as $b) {
            try {
                if ($b->customer_id) {
                    $push->sendToCustomer(
                        $b->customer_id,
                        'Pengingat Jadwal Besok',
                        "Besok jadwal booking #{$b->booking_number} Anda. Jika berhalangan, segera hubungi toko.",
                        ['type' => 'booking_reminder_h1', 'booking_id' => $b->id, 'route' => "/booking/{$b->id}/chat"]
                    );
                }

                $installerIds = $b->installers->pluck('id');
                if ($installerIds->isNotEmpty()) {
                    $push->sendToUsers(
                        $installerIds,
                        'Jadwal Instalasi Besok',
                        "Besok ada jadwal booking #{$b->booking_number}.",
                        ['type' => 'booking_reminder_h1', 'booking_id' => $b->id, 'route' => "/staff/bookings/{$b->id}"]
                    );
                }
            } catch (\Throwable $e) {
                report($e);
                continue; // jangan tandai terkirim kalau push gagal
            }

            DB::table('bookings')->where('id', $b->id)->update(['h1_reminder_sent_at' => now()]);
        }

        $overdue = Booking::where('status', 'confirmed')
            ->whereDate('preferred_date', '<', today())
            ->whereNull('current_stage')
            ->whereNotNull('store_id')
            ->get(['id', 'store_id'])
            ->groupBy('store_id');

        foreach ($overdue as $storeId => $group) {
            $managerIds = User::where('store_id', $storeId)
                ->where('is_active', true)
                ->get()
                ->filter(fn (User $u) => $u->isStoreManager())
                ->pluck('id');

            if ($managerIds->isEmpty()) {
                continue;
            }

            try {
                $push->sendToUsers(
                    $managerIds,
                    'Booking Lewat Tanggal',
                    "{$group->count()} booking dikonfirmasi tapi tanggalnya sudah lewat dan belum dikerjakan. Jadwal ulang atau batalkan.",
                    ['type' => 'booking_overdue', 'route' => '/staff/bookings?status=confirmed']
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->info("H-1: {$tomorrow->count()} booking. Lewat tanggal: {$overdue->flatten()->count()} booking.");

        return self::SUCCESS;
    }
}
