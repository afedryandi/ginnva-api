<?php

namespace App\Services;

use App\Models\CustomerNotification;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Extract dari NotificationController::send() supaya logic kirim push
 * bisa dipakai ulang dari tempat lain (mis. BookingMessageObserver saat
 * ada update progress instalasi), tanpa harus hit endpoint HTTP dari
 * dalam kode sendiri.
 */
class PushNotificationService
{
    /**
     * Kirim push ke satu customer tertentu (dipakai untuk notifikasi
     * booking/progress yang sifatnya personal, bukan broadcast).
     */
    public function sendToCustomer(int $customerId, string $title, string $body, array $data = []): void
    {
        CustomerNotification::create([
            'customer_id' => $customerId,
            'title'       => $title,
            'body'        => $body,
            'data'        => $data,
        ]);

        $tokens = DeviceToken::where('customer_id', $customerId)->pluck('token')->toArray();

        if (empty($tokens)) return;

        $this->pushToTokens($tokens, $title, $body, $data);
    }

    /**
     * Kirim push ke staff yang relevan dengan sebuah toko: admin toko
     * (regional_admin) toko itu + semua super_admin (supaya management
     * juga bisa pantau). Dipakai saat CUSTOMER mengirim pesan di booking
     * chat — staff perlu tahu ada pesan/pertanyaan baru masuk.
     */
    public function sendToStoreStaff(int $storeId, string $title, string $body, array $data = []): void
    {
        $regionalAdminIds = User::where('store_id', $storeId)
            ->get()
            ->filter(fn (User $u) => $u->hasRole('regional_admin'))
            ->pluck('id');

        $superAdminIds = User::role('super_admin')->pluck('id');

        $userIds = $regionalAdminIds->merge($superAdminIds)->unique();

        if ($userIds->isEmpty()) return;

        $tokens = DeviceToken::whereIn('user_id', $userIds)->pluck('token')->toArray();

        if (empty($tokens)) return;

        $this->pushToTokens($tokens, $title, $body, $data);
    }

    private function pushToTokens(array $tokens, string $title, string $body, array $data): void
    {
        foreach (array_chunk($tokens, 100) as $chunk) {
            $messages = array_map(fn ($token) => [
                'to'    => $token,
                'title' => $title,
                'body'  => $body,
                'data'  => (object) $data,
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
                        if (($result['status'] ?? '') !== 'ok') {
                            $details = $result['details'] ?? [];
                            if (($details['error'] ?? '') === 'DeviceNotRegistered') {
                                DeviceToken::where('token', $chunk[$i])->delete();
                            } else {
                                Log::warning('[Expo Push] Send gagal', ['error' => $result]);
                            }
                        }
                    }
                } else {
                    Log::error('[Expo Push] HTTP error: ' . $response->body());
                }
            } catch (\Exception $e) {
                Log::error('[Expo Push] Exception: ' . $e->getMessage());
            }
        }
    }
}
