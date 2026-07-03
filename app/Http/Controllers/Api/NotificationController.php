<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    /**
     * POST /api/notifications/register-token
     * Request: { token: string, platform: 'android'|'ios' }
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
     * Request: { token: string }
     */
    public function linkToken(Request $request)
    {
        $request->validate(['token' => 'required|string|max:500']);

        DeviceToken::where('token', $request->token)
            ->update(['customer_id' => auth('customer')->id()]);

        return response()->json(['message' => 'Token linked.']);
    }

    /**
     * Kirim notifikasi via Expo Push Service.
     * Tidak perlu service account / FCM key — Expo yang handle delivery ke FCM/APNs.
     *
     * Docs: https://docs.expo.dev/push-notifications/sending-notifications/
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

        if (!empty($request->customer_ids)) {
            $query->whereIn('customer_id', $request->customer_ids);
        }

        $tokens = $query->pluck('token')->toArray();

        if (empty($tokens)) {
            return response()->json(['message' => 'No tokens found.', 'sent' => 0]);
        }

        // Expo Push Service: max 100 token per request
        $sent   = 0;
        $failed = 0;

        foreach (array_chunk($tokens, 100) as $chunk) {
            $messages = array_map(fn($token) => [
                'to'    => $token,
                'title' => $request->title,
                'body'  => $request->body,
                'data'  => $request->data ?? [],
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

                            // Hapus token tidak valid
                            if (($details['error'] ?? '') === 'DeviceNotRegistered') {
                                DeviceToken::where('token', $chunk[$i])->delete();
                                Log::info('[Expo Push] Token dihapus (DeviceNotRegistered): ' . $chunk[$i]);
                            } else {
                                Log::warning('[Expo Push] Send gagal', [
                                    'token'  => substr($chunk[$i], 0, 30) . '...',
                                    'error'  => $result,
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