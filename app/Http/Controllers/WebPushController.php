<?php

namespace App\Http\Controllers;

use App\Models\WebPushSubscription;
use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Langganan push web perangkat (PWA panel admin, 2026-11-24): simpan, cabut, dan kirim uji untuk akun yang sedang login di panel.
 */
class WebPushController extends Controller
{
    public function subscribe(Request $request, WebPushService $service): JsonResponse
    {
        if (! $service->enabled()) {
            return response()->json(['message' => 'Push web belum diaktifkan di server.'], 503);
        }

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
        ]);

        WebPushSubscription::remember(
            (int) $request->user()->id,
            $data['endpoint'],
            $data['keys']['p256dh'],
            $data['keys']['auth'],
            $data['content_encoding'] ?? null,
            $request->userAgent(),
        );

        return response()->json(['message' => 'Perangkat ini sudah terdaftar untuk notifikasi.']);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);

        WebPushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint_hash', WebPushSubscription::hashEndpoint($data['endpoint']))
            ->delete();

        return response()->json(['message' => 'Notifikasi di perangkat ini dimatikan.']);
    }

    public function test(Request $request, WebPushService $service): JsonResponse
    {
        if (! $service->enabled()) {
            return response()->json(['message' => 'Push web belum diaktifkan di server.'], 503);
        }

        if (! $service->sendingAllowed()) {
            return response()->json(['message' => 'Pengiriman dimatikan di lingkungan lokal (PUSH_ALLOW_LOCAL).'], 409);
        }

        $result = $service->pushTo([$request->user()->id], 'Tes notifikasi Ginnva', 'Kalau pesan ini muncul, notifikasi di perangkat ini sudah aktif.', url('/admin'));

        if ($result['sent'] === 0) {
            return response()->json(['message' => 'Belum ada perangkat yang menerima. Aktifkan notifikasi dulu di perangkat ini.', 'result' => $result], 422);
        }

        return response()->json(['message' => "Tes terkirim ke {$result['sent']} perangkat.", 'result' => $result]);
    }
}
