<?php

namespace App\Console\Commands;

use App\Filament\Resources\BookingResource;
use App\Models\BookingCancellationRequest;
use App\Models\BookingRescheduleRequest;
use App\Models\User;
use App\Services\PushNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SLA pengajuan customer (jadwal ulang & pembatalan) -- keputusan user
 * 2026-10-06: sama dengan booking pending. Pengajuan yang belum diputuskan
 * > 4 jam diingatkan ke staff toko (akses menu Booking) + Store Manager +
 * super_admin, lalu tiap 24 jam; setelah 2 pengingat dieskalasi SEKALI ke
 * direksi. Tidak ada kedaluwarsa otomatis. Jalan tiap jam.
 */
class RemindPendingBookingRequests extends Command
{
    protected $signature = 'bookings:remind-pending-requests';

    protected $description = 'Ingatkan staff untuk pengajuan jadwal ulang/pembatalan customer yang belum diputuskan';

    private const FIRST_REMINDER_HOURS = 4;

    private const REPEAT_HOURS = 24;

    private const ESCALATE_AFTER = 2;

    public function handle(PushNotificationService $push): int
    {
        $total = 0;
        $escalated = [];

        foreach ([
            [BookingRescheduleRequest::class, 'booking_reschedule_requests', 'jadwal ulang'],
            [BookingCancellationRequest::class, 'booking_cancellation_requests', 'pembatalan'],
        ] as [$model, $table, $label]) {
            $due = $model::with('booking:id,store_id,booking_number')
                ->where('status', 'pending')
                ->where('created_at', '<=', now()->subHours(self::FIRST_REMINDER_HOURS))
                ->where(fn ($q) => $q->whereNull('reminder_sent_at')
                    ->orWhere('reminder_sent_at', '<=', now()->subHours(self::REPEAT_HOURS)))
                ->get()
                ->filter(fn ($r) => $r->booking?->store_id);

            foreach ($due->groupBy(fn ($r) => $r->booking->store_id) as $storeId => $group) {
                $count = $group->count();
                $body = $count === 1
                    ? "Pengajuan {$label} booking #{$group->first()->booking->booking_number} belum diputuskan."
                    : "{$count} pengajuan {$label} customer belum diputuskan. Segera tindak lanjuti.";

                $recipientIds = User::where('store_id', $storeId)
                    ->where('is_active', true)
                    ->with('roles')
                    ->get()
                    ->filter(fn (User $u) => $u->isStoreManager()
                        || ($u->isRestrictedStaff() && $u->hasMenuAccess(BookingResource::class)))
                    ->pluck('id')
                    ->merge(User::role('super_admin')->pluck('id'))
                    ->unique()
                    ->values();

                try {
                    $push->sendToUsers($recipientIds, 'Pengajuan Customer Menunggu', $body, [
                        'type'  => 'booking_request_reminder',
                        'route' => '/staff/bookings',
                    ]);
                } catch (\Throwable $e) {
                    report($e);
                    continue; // penanda tidak diisi, dicoba lagi jam berikutnya
                }

                foreach ($group as $r) {
                    if ((int) $r->reminder_count === self::ESCALATE_AFTER) {
                        $escalated[] = $r->booking->booking_number;
                    }
                }

                DB::table($table)->whereIn('id', $group->pluck('id'))->update([
                    'reminder_sent_at' => now(),
                    'reminder_count'   => DB::raw('reminder_count + 1'),
                ]);

                $total += $count;
            }
        }

        if (! empty($escalated)) {
            try {
                $push->sendToUsers(
                    User::role('direksi')->where('is_active', true)->pluck('id'),
                    'Pengajuan Customer Belum Ditindak',
                    count($escalated) . ' pengajuan jadwal ulang/pembatalan sudah diingatkan berulang kali tapi belum diputuskan.',
                    ['type' => 'booking_request_escalation', 'route' => '/staff/bookings']
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->info("Pengingat dikirim untuk {$total} pengajuan.");

        return self::SUCCESS;
    }
}
