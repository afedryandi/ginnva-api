<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerNotification;
use App\Models\DeviceToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        return response()->json(['message' => 'Token registered.']);
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

        return response()->json(['message' => 'Token linked.']);
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

        return response()->json(['message' => 'Token linked.']);
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

        $unreadCount = CustomerNotification::forCustomer($customerId)
            ->where('created_at', '>=', $since)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'data'         => $notifications->items(),
            'meta'         => [
                'current_page' => $notifications->currentPage(),
                'last_page'    => $notifications->lastPage(),
                'total'        => $notifications->total(),
            ],
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * POST /api/customer/notifications/{id}/read
     * Requires: auth:customer
     */
    public function markRead(Request $request, int $id)
    {
        $customerId = auth('customer')->id();

        $notif = CustomerNotification::forCustomer($customerId)->findOrFail($id);

        if (! $notif->read_at) {
            $notif->update(['read_at' => now()]);
        }

        return response()->json(['message' => 'Marked as read.']);
    }

    /**
     * POST /api/customer/notifications/read-all
     * Requires: auth:customer
     */
    public function markAllRead(Request $request)
    {
        $customerId = auth('customer')->id();

        CustomerNotification::forCustomer($customerId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'All marked as read.']);
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
            'customer_ids.*' => 'integer',
            'data'           => 'nullable|array',
        ]);

        $query = DeviceToken::query();

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
            return response()->json(['message' => 'Saved to history. No push tokens found.', 'sent' => 0]);
        }

        $sent   = 0;
        $failed = 0;

        foreach (array_chunk($tokens, 100) as $chunk) {
            $messages = array_map(fn($token) => [
                'to'    => $token,
                'title' => $request->title,
                'body'  => $request->body,
                'data'  => $request->data ? (object) $request->data : new \stdClass(),
                'sound' => 'default',
            ], $chunk);

            try {
                $response = Http::withHeaders([
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                ])->post('https://exp.host/--/api/v2/push/send', $messages);

                if ($response->successful()) {
                    $results = $response->json('data') ?? [];

                    foreach ($results as $i => $result) {
                        if (($result['status'] ?? '') === 'ok') {
                            $sent++;
                        } else {
                            $failed++;
                            $details = $result['details'] ?? [];

                            if (($details['error'] ?? '') === 'DeviceNotRegistered') {
                                DeviceToken::where('token', $chunk[$i])->delete();
                                Log::info('[Expo Push] Token dihapus (DeviceNotRegistered): ' . $chunk[$i]);
                            } else {
                                Log::warning('[Expo Push] Send gagal', [
                                    'token' => substr($chunk[$i], 0, 30) . '...',
                                    'error' => $result,
                                ]);
                            }
                        }
                    }
                } else {
                    $failed += count($chunk);
                    Log::error('[Expo Push] HTTP error: ' . $response->body());
                }
            } catch (\Exception $e) {
                $failed += count($chunk);
                Log::error('[Expo Push] Exception: ' . $e->getMessage());
            }
        }

        return response()->json([
            'message' => 'Done.',
            'sent'    => $sent,
            'failed'  => $failed,
        ]);
    }
}
