<?php

namespace App\Console\Commands;

use App\Models\CustomerNotification;
use App\Models\CustomerNotificationRead;
use App\Models\PartnerNotification;
use App\Models\PartnerNotificationRead;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Audit Notifikasi 2026-10-01 -- SEBELUMNYA tabel bell Filament
 * (`notifications`, bawaan Laravel), `customer_notifications`, dan
 * `partner_notifications` tumbuh permanen tanpa batas: NotificationController
 * ::history()/partnerHistory() hanya MEMFILTER tampilan 90 hari terakhir,
 * baris yang lebih lama tetap ada selamanya di database. Berbeda dari OTP
 * yang sudah punya PruneExpiredOtpCodes (retensi 7 hari).
 *
 * Retensi 180 hari (2x jendela tampilan 90 hari NotificationController) --
 * default yang konservatif, BUKAN keputusan final: belum ada kebijakan
 * retensi notifikasi yang dikonfirmasi user (lihat project_data_retention_
 * policy.md, belum menyebut notifikasi sama sekali). Sesuaikan angka ini
 * kalau ada keputusan retensi yang berbeda.
 */
class PruneOldNotifications extends Command
{
    protected $signature = 'notifications:prune-old {--days=180 : Hapus notifikasi lebih tua dari N hari}';

    protected $description = 'Hapus baris lama di tabel notifications (bell Filament), customer_notifications, dan partner_notifications';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) $this->option('days'));

        $bell = DB::table('notifications')->where('created_at', '<', $cutoff)->delete();

        // Reads dihapus DULU (FK ke baris notification induknya) supaya
        // tidak ada baris *_reads yatim kalau notification induknya sudah
        // dihapus duluan.
        $customerReadIds = CustomerNotification::where('created_at', '<', $cutoff)->pluck('id');
        $customerReads = CustomerNotificationRead::whereIn('customer_notification_id', $customerReadIds)->delete();
        $customerNotifs = CustomerNotification::where('created_at', '<', $cutoff)->delete();

        $partnerReadIds = PartnerNotification::where('created_at', '<', $cutoff)->pluck('id');
        $partnerReads = PartnerNotificationRead::whereIn('partner_notification_id', $partnerReadIds)->delete();
        $partnerNotifs = PartnerNotification::where('created_at', '<', $cutoff)->delete();

        $this->info("Dihapus: {$bell} bell notifikasi, {$customerNotifs} notifikasi customer (+{$customerReads} baris read), {$partnerNotifs} notifikasi partner (+{$partnerReads} baris read).");

        return self::SUCCESS;
    }
}
