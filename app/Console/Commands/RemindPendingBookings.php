<?php

namespace App\Console\Commands;

use App\Filament\Resources\BookingResource;
use App\Models\Booking;
use App\Models\User;
use App\Services\PushNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SLA booking pending (keputusan user 2026-10-02, audit alur Booking) --
 * SEBELUMNYA booking pending bisa menunggu tanpa ada yang diingatkan.
 * Booking pending > 4 jam diingatkan ke staff toko (yang punya akses menu
 * Booking) DAN Store Manager toko itu. Booking yang tetap pending diingatkan
 * LAGI tiap 24 jam (sengaja berulang sampai ditindak, sama pola
 * NotifyStaleQuotations). TIDAK ada pembatalan otomatis. Jalan tiap jam
 * (lihat routes/console.php).
 */
class RemindPendingBookings extends Command
{
    protected $signature = 'bookings:remind-pending';

    protected $description = 'Ingatkan staff & Store Manager untuk booking pending lebih dari 4 jam';

    private const FIRST_REMINDER_HOURS = 4;

    private const REPEAT_HOURS = 24;

    private const ESCALATE_AFTER = 2;

    public function handle(PushNotificationService $push): int
    {
        $due = Booking::where('status', 'pending')
            ->whereNotNull('store_id')
            ->where('created_at', '<=', now()->subHours(self::FIRST_REMINDER_HOURS))
            ->where(fn ($q) => $q->whereNull('pending_reminder_sent_at')
                ->orWhere('pending_reminder_sent_at', '<=', now()->subHours(self::REPEAT_HOURS)))
            ->get();

        $escalated = [];

        foreach ($due->groupBy('store_id') as $storeId => $group) {
            $count = $group->count();
            $title = 'Booking Menunggu Konfirmasi';
            $body = $count === 1
                ? "Booking #{$group->first()->booking_number} belum dikonfirmasi."
                : "{$count} booking belum dikonfirmasi. Segera tindak lanjuti.";
            $data = ['type' => 'booking_pending_reminder', 'route' => '/staff/bookings?status=pending'];

            // Satu daftar penerima tanpa duplikat (2026-10-03): staff toko yang
            // punya akses menu Booking + Store Manager toko itu (selalu) +
            // super_admin -- sebelumnya Store Manager bisa menerima 2x.
            $recipientIds = User::where('store_id', $storeId)
                ->where('is_active', true)
                ->get()
                ->filter(fn (User $u) => $u->isStoreManager()
                    || ($u->isRestrictedStaff() && $u->hasMenuAccess(BookingResource::class)))
                ->pluck('id')
                ->merge(User::role('super_admin')->pluck('id'))
                ->unique()
                ->values();

            try {
                $push->sendToUsers($recipientIds, $title, $body, $data);
            } catch (\Throwable $e) {
                // Penanda TIDAK diisi kalau gagal -- dicoba lagi jam berikutnya.
                report($e);
                continue;
            }

            // Eskalasi: booking yang sudah diingatkan >= 2 kali (keputusan
            // 2026-10-03) ikut dikumpulkan untuk direksi.
            foreach ($group as $b) {
                // Tepat SEKALI saat ambang tercapai -- sebelumnya >= membuat
                // direksi dapat push eskalasi tiap 24 jam selamanya (2026-10-03).
                if ($b->pending_reminder_count === self::ESCALATE_AFTER) {
                    $escalated[$storeId][] = $b->booking_number;
                }
            }

            // Lewat query builder (bukan Eloquent) supaya updated_at TIDAK
            // berubah: token versi form Edit Filament memakainya, cron tidak
            // boleh memicu "Booking sudah berubah" palsu.
            DB::table('bookings')->whereIn('id', $group->pluck('id'))->update([
                'pending_reminder_sent_at' => now(),
                'pending_reminder_count'   => DB::raw('pending_reminder_count + 1'),
            ]);
        }

        // SATU push ringkasan ke direksi untuk seluruh toko (bukan per toko).
        if (! empty($escalated)) {
            $total = collect($escalated)->flatten()->count();
            $stores = \App\Models\Store::whereIn('id', array_keys($escalated))->pluck('name')->implode(', ');
            $direksiIds = User::role('direksi')->where('is_active', true)->pluck('id');

            try {
                $push->sendToUsers(
                    $direksiIds,
                    'Booking Pending Belum Ditindak',
                    "{$total} booking sudah diingatkan berulang kali tapi belum dikonfirmasi ({$stores}).",
                    ['type' => 'booking_pending_escalation', 'route' => '/staff/bookings?status=pending']
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->info("Pengingat dikirim untuk {$due->count()} booking pending.");

        return self::SUCCESS;
    }
}
