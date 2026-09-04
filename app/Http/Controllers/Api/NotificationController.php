<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerNotification;
use App\Models\CustomerNotificationRead;
use App\Models\DeviceToken;
use App\Models\Partner;
use App\Models\PartnerNotification;
use App\Models\PartnerNotificationRead;
use App\Services\PushNotificationService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * POST /api/notifications/register-token
     */
    public function registerToken(Request $request)
    {
        $request->validate([
            'token'    => 'required|string|max:500',
            'platform' => 'required|in:android,ios',
        ]);

        $customerId = null;
        try {
            $customerId = auth('customer')->user()?->id;
        } catch (\Exception) {}

        DeviceToken::updateOrCreate(
            ['token' => $request->token],
            [
                'customer_id' => $customerId,
                'platform'    => $request->platform,
            ]
        );

        return response()->json(['success' => true, 'message' => 'Token registered.']);
    }

    /**
     * POST /api/notifications/link-token
     * Requires: auth:customer
     */
    public function linkToken(Request $request)
    {
        $request->validate(['token' => 'required|string|max:500']);

        DeviceToken::where('token', $request->token)
            ->update(['customer_id' => auth('customer')->id()]);

        return response()->json(['success' => true, 'message' => 'Token linked.']);
    }

    /**
     * POST /api/staff/notifications/link-token
     * Requires: auth:api (staff)
     *
     * Sama seperti linkToken() tapi menautkan ke user_id staff, bukan
     * customer_id — supaya token HP yang sama bisa dipakai baik saat app
     * dibuka sebagai customer maupun setelah login sebagai staff.
     */
    public function linkTokenStaff(Request $request)
    {
        $request->validate(['token' => 'required|string|max:500']);

        DeviceToken::updateOrCreate(
            ['token' => $request->token],
            ['user_id' => $request->user('api')->id]
        );

        return response()->json(['success' => true, 'message' => 'Token linked.']);
    }

    /**
     * GET /api/customer/notifications
     * Requires: auth:customer
     * Returns: paginated notification history (targeted + broadcast)
     */
    public function history(Request $request)
    {
        $customerId = auth('customer')->id();

        $since = now()->subDays(90);

        $notifications = CustomerNotification::forCustomer($customerId)
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->paginate(30);

        // Broadcast (customer_id null) tidak punya read_at per-customer —
        // ambil status baca dari customer_notification_reads (lihat
        // CustomerNotification::isReadBy()) supaya "sudah dibaca" 1
        // customer tidak ikut menandai baca untuk customer lain.
        $broadcastIds = collect($notifications->items())
            ->filter(fn (CustomerNotification $n) => $n->customer_id === null)
            ->pluck('id');

        $readBroadcastIds = CustomerNotificationRead::where('customer_id', $customerId)
            ->whereIn('customer_notification_id', $broadcastIds)
            ->pluck('customer_notification_id')
            ->all();

        $notifications->getCollection()->transform(function (CustomerNotification $n) use ($readBroadcastIds) {
            $n->is_read = $n->customer_id === null
                ? in_array($n->id, $readBroadcastIds)
                : $n->read_at !== null;
            return $n;
        });

        $unreadCount = $this->countUnread($customerId, $since);

        return response()->json([
            'success'      => true,
            'data'         => $notifications->items(),
            'meta'         => [
                'current_page' => $notifications->currentPage(),
                'last_page'    => $notifications->lastPage(),
                'total'        => $notifications->total(),
            ],
            'unread_count' => $unreadCount,
        ]);
    }

    private function countUnread(int $customerId, \Illuminate\Support\Carbon $since): int
    {
        $targetedUnread = CustomerNotification::where('customer_id', $customerId)
            ->where('created_at', '>=', $since)
            ->whereNull('read_at')
            ->count();

        $broadcastIds = CustomerNotification::whereNull('customer_id')
            ->where('created_at', '>=', $since)
            ->pluck('id');

        $broadcastReadCount = CustomerNotificationRead::where('customer_id', $customerId)
            ->whereIn('customer_notification_id', $broadcastIds)
            ->count();

        return $targetedUnread + ($broadcastIds->count() - $broadcastReadCount);
    }

    /**
     * POST /api/customer/notifications/{id}/read
     * Requires: auth:customer
     */
    public function markRead(Request $request, int $id)
    {
        $customerId = auth('customer')->id();

        $notif = CustomerNotification::forCustomer($customerId)->findOrFail($id);

        if ($notif->customer_id === null) {
            // Broadcast — catat status baca KHUSUS untuk customer ini,
            // jangan sentuh read_at baris asli (dipakai bersama semua
            // customer lain).
            CustomerNotificationRead::firstOrCreate(
                ['customer_id' => $customerId, 'customer_notification_id' => $notif->id],
                ['read_at' => now()]
            );
        } elseif (! $notif->read_at) {
            $notif->update(['read_at' => now()]);
        }

        return response()->json(['success' => true, 'message' => 'Marked as read.']);
    }

    /**
     * POST /api/customer/notifications/read-all
     * Requires: auth:customer
     */
    public function markAllRead(Request $request)
    {
        $customerId = auth('customer')->id();
        $since = now()->subDays(90);

        CustomerNotification::where('customer_id', $customerId)
            ->where('created_at', '>=', $since)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $alreadyReadIds = CustomerNotificationRead::where('customer_id', $customerId)
            ->pluck('customer_notification_id');

        $unreadBroadcastIds = CustomerNotification::whereNull('customer_id')
            ->where('created_at', '>=', $since)
            ->whereNotIn('id', $alreadyReadIds)
            ->pluck('id');

        if ($unreadBroadcastIds->isNotEmpty()) {
            $now = now();
            CustomerNotificationRead::insertOrIgnore(
                $unreadBroadcastIds->map(fn ($id) => [
                    'customer_id'               => $customerId,
                    'customer_notification_id'  => $id,
                    'read_at'                   => $now,
                ])->all()
            );
        }

        return response()->json(['success' => true, 'message' => 'All marked as read.']);
    }

    private function partnerOrAbort(Request $request): Partner
    {
        $partner = $request->user('api')->partner;

        abort_if(! $partner, 403, 'Akun ini bukan akun partner.');

        return $partner;
    }

    /**
     * GET /api/partner/notifications
     * Requires: auth:api, role partner
     */
    public function partnerHistory(Request $request)
    {
        $partner = $this->partnerOrAbort($request);
        $since   = now()->subDays(90);

        $notifications = PartnerNotification::forPartner($partner->id)
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->paginate(30);

        $broadcastIds = collect($notifications->items())
            ->filter(fn (PartnerNotification $n) => $n->partner_id === null)
            ->pluck('id');

        $readBroadcastIds = PartnerNotificationRead::where('partner_id', $partner->id)
            ->whereIn('partner_notification_id', $broadcastIds)
            ->pluck('partner_notification_id')
            ->all();

        $notifications->getCollection()->transform(function (PartnerNotification $n) use ($readBroadcastIds) {
            $n->is_read = $n->partner_id === null
                ? in_array($n->id, $readBroadcastIds)
                : $n->read_at !== null;
            return $n;
        });

        $unreadCount = $this->countUnreadForPartner($partner->id, $since);

        return response()->json([
            'success'      => true,
            'data'         => $notifications->items(),
            'meta'         => [
                'current_page' => $notifications->currentPage(),
                'last_page'    => $notifications->lastPage(),
                'total'        => $notifications->total(),
            ],
            'unread_count' => $unreadCount,
        ]);
    }

    private function countUnreadForPartner(int $partnerId, \Illuminate\Support\Carbon $since): int
    {
        $targetedUnread = PartnerNotification::where('partner_id', $partnerId)
            ->where('created_at', '>=', $since)
            ->whereNull('read_at')
            ->count();

        $broadcastIds = PartnerNotification::whereNull('partner_id')
            ->where('created_at', '>=', $since)
            ->pluck('id');

        $broadcastReadCount = PartnerNotificationRead::where('partner_id', $partnerId)
            ->whereIn('partner_notification_id', $broadcastIds)
            ->count();

        return $targetedUnread + ($broadcastIds->count() - $broadcastReadCount);
    }

    /**
     * POST /api/partner/notifications/{id}/read
     * Requires: auth:api, role partner
     */
    public function partnerMarkRead(Request $request, int $id)
    {
        $partner = $this->partnerOrAbort($request);

        $notif = PartnerNotification::forPartner($partner->id)->findOrFail($id);

        if ($notif->partner_id === null) {
            PartnerNotificationRead::firstOrCreate(
                ['partner_id' => $partner->id, 'partner_notification_id' => $notif->id],
                ['read_at' => now()]
            );
        } elseif (! $notif->read_at) {
            $notif->update(['read_at' => now()]);
        }

        return response()->json(['success' => true, 'message' => 'Marked as read.']);
    }

    /**
     * POST /api/partner/notifications/read-all
     * Requires: auth:api, role partner
     */
    public function partnerMarkAllRead(Request $request)
    {
        $partner = $this->partnerOrAbort($request);
        $since   = now()->subDays(90);

        PartnerNotification::where('partner_id', $partner->id)
            ->where('created_at', '>=', $since)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $alreadyReadIds = PartnerNotificationRead::where('partner_id', $partner->id)
            ->pluck('partner_notification_id');

        $unreadBroadcastIds = PartnerNotification::whereNull('partner_id')
            ->where('created_at', '>=', $since)
            ->whereNotIn('id', $alreadyReadIds)
            ->pluck('id');

        if ($unreadBroadcastIds->isNotEmpty()) {
            $now = now();
            PartnerNotificationRead::insertOrIgnore(
                $unreadBroadcastIds->map(fn ($id) => [
                    'partner_id'                => $partner->id,
                    'partner_notification_id'   => $id,
                    'read_at'                   => $now,
                ])->all()
            );
        }

        return response()->json(['success' => true, 'message' => 'All marked as read.']);
    }

    /**
     * Kirim notifikasi ke Partner via Expo Push Service dan simpan ke
     * history. Dipanggil dari Filament\Pages\SendNotification — token
     * push Partner ditautkan lewat DeviceToken.user_id (lihat
     * linkTokenStaff()), BUKAN customer_id, jadi resolve dulu ke user_id
     * lewat Partner::whereIn('id', ...).
     */
    public function sendPartner(Request $request)
    {
        $request->validate([
            'title'         => 'required|string|max:200',
            'body'          => 'required|string|max:500',
            'partner_ids'   => 'nullable|array',
            'partner_ids.*' => 'integer|exists:partners,id',
            'data'          => 'nullable|array',
        ]);

        $partnersQuery = Partner::query();
        if (! empty($request->partner_ids)) {
            $partnersQuery->whereIn('id', $request->partner_ids);
        }
        $partners = $partnersQuery->get(['id', 'user_id']);

        $tokens = DeviceToken::whereIn('user_id', $partners->pluck('user_id'))
            ->pluck('token')
            ->toArray();

        if (! empty($request->partner_ids)) {
            foreach ($partners as $partner) {
                PartnerNotification::create([
                    'partner_id' => $partner->id,
                    'title'      => $request->title,
                    'body'       => $request->body,
                    'data'       => $request->data,
                ]);
            }
        } else {
            PartnerNotification::create([
                'partner_id' => null,
                'title'      => $request->title,
                'body'       => $request->body,
                'data'       => $request->data,
            ]);
        }

        if (empty($tokens)) {
            return response()->json(['success' => true, 'message' => 'Saved to history. No push tokens found.', 'sent' => 0]);
        }

        $result = app(PushNotificationService::class)
            ->pushToTokens($tokens, $request->title, $request->body, $request->data ?? []);

        return response()->json([
            'success' => true,
            'message' => 'Done.',
            'sent'    => $result['sent'],
            'failed'  => $result['failed'],
        ]);
    }

    /**
     * Kirim notifikasi via Expo Push Service dan simpan ke history.
     */
    public function send(Request $request)
    {
        $request->validate([
            'title'          => 'required|string|max:200',
            'body'           => 'required|string|max:500',
            'customer_ids'   => 'nullable|array',
            // exists dicek supaya 1 ID basi (mis. customer sempat dihapus
            // di antara halaman kirim notif dimuat & disubmit) tidak bikin
            // seluruh batch gagal kena FK constraint error mentah —
            // customer_notifications.customer_id bukan nullable-safe
            // terhadap ID yang tidak ada.
            'customer_ids.*' => 'integer|exists:customers,id',
            'data'           => 'nullable|array',
        ]);

        // SEBELUMNYA broadcast ("kirim ke semua pengguna") tidak difilter
        // sama sekali — device_tokens adalah tabel GABUNGAN (customer_id
        // untuk Customer, user_id untuk staff/Partner, lihat migrasi
        // 2026_xx add_user_id_to_device_tokens). Tanpa whereNotNull di
        // sini, broadcast Customer ikut mem-push ke HP staff & Partner
        // juga — notifikasi marketing untuk Customer (mis. "Promo 20%")
        // nyasar ke akun internal. sendPartner() di bawah sudah benar
        // (whereIn('user_id', ...) eksplisit); endpoint ini sekarang
        // dikunci whereNotNull('customer_id') supaya selalu customer-only,
        // baik targeted maupun broadcast.
        $query = DeviceToken::query()->whereNotNull('customer_id');

        if (! empty($request->customer_ids)) {
            $query->whereIn('customer_id', $request->customer_ids);
        }

        $tokens = $query->pluck('token')->toArray();

        // Simpan ke history
        if (! empty($request->customer_ids)) {
            // Targeted: satu record per customer
            foreach ($request->customer_ids as $customerId) {
                CustomerNotification::create([
                    'customer_id' => $customerId,
                    'title'       => $request->title,
                    'body'        => $request->body,
                    'data'        => $request->data,
                ]);
            }
        } else {
            // Broadcast: satu record customer_id null, tampil ke semua
            CustomerNotification::create([
                'customer_id' => null,
                'title'       => $request->title,
                'body'        => $request->body,
                'data'        => $request->data,
            ]);
        }

        if (empty($tokens)) {
            return response()->json(['success' => true, 'message' => 'Saved to history. No push tokens found.', 'sent' => 0]);
        }

        // Sebelumnya ada salinan logic kirim push di sini, nyaris identik
        // dengan PushNotificationService::pushToTokens() — dipakai
        // bersama sekarang supaya cuma ada satu sumber kebenaran.
        $result = app(PushNotificationService::class)
            ->pushToTokens($tokens, $request->title, $request->body, $request->data ?? []);

        $sent   = $result['sent'];
        $failed = $result['failed'];

        return response()->json([
            'success' => true,
            'message' => 'Done.',
            'sent'    => $sent,
            'failed'  => $failed,
        ]);
    }
}
