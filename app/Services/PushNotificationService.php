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
     * Kirim push ke staff yang relevan dengan sebuah toko: staff toko itu
     * (role apa pun SELAIN installer/partner — role divisi seperti
     * Store Manager, dst — dicek lewat isRestrictedStaff())
     * + semua super_admin (selalu, karena itu akun tertinggi/pusat kendali).
     * 'direksi' SENGAJA tidak diblast di sini lagi — direksi hanya dapat
     * notif booking yang mereka pantau lewat sendToBookingWatchers(), supaya
     * tidak kebanjiran notif dari semua toko. Dipakai saat CUSTOMER
     * mengirim pesan di booking chat.
     */
    public function sendToStoreStaff(int $storeId, string $title, string $body, array $data = []): void
    {
        $storeStaffIds = User::where('store_id', $storeId)
            ->get()
            ->filter(fn (User $u) => $u->isRestrictedStaff())
            ->pluck('id');

        $superAdminIds = User::role('super_admin')->pluck('id');

        $userIds = $storeStaffIds->merge($superAdminIds)->unique();

        $this->sendToUsers($userIds, $title, $body, $data);
    }

    /**
     * Kirim push HANYA ke direksi yang ditunjuk sebagai watcher booking
     * tertentu (lihat Booking::watchers()) — dipakai bersamaan dengan
     * sendToStoreStaff() saat ada pesan/update baru di booking chat.
     */
    public function sendToBookingWatchers(\App\Models\Booking $booking, string $title, string $body, array $data = []): void
    {
        $this->sendToUsers($booking->watchers()->pluck('users.id'), $title, $body, $data);
    }

    /**
     * Primitif umum: kirim push ke sekumpulan user id apa pun. Dipakai oleh
     * sendToStoreStaff()/sendToBookingWatchers() dan bisa dipakai langsung
     * kalau ada kebutuhan lain di masa depan.
     */
    public function sendToUsers(iterable $userIds, string $title, string $body, array $data = []): void
    {
        $userIds = collect($userIds)->filter()->unique();

        if ($userIds->isEmpty()) return;

        $tokens = DeviceToken::whereIn('user_id', $userIds)->pluck('token')->toArray();

        if (empty($tokens)) return;

        $this->pushToTokens($tokens, $title, $body, $data);
    }

    /**
     * Kirim push mentah ke daftar token, dengan tracking sent/failed —
     * dipakai internal oleh method-method di atas (yang cuma "fire and
     * forget", tidak peduli hasilnya), TAPI juga dibuat public supaya
     * NotificationController::send() (kirim manual dari Filament) bisa
     * pakai method yang SAMA PERSIS ini, bukan salinan terpisah. Dulu ada
     * dua salinan nyaris identik — komentar class ini bahkan bilang
     * "extract dari NotificationController::send()" tapi controllernya
     * sendiri lupa di-refactor untuk benar-benar memakai hasil extract-nya.
     *
     * @return array{sent: int, failed: int}
     */
    public function pushToTokens(array $tokens, string $title, string $body, array $data = []): array
    {
        $sent = 0;
        $failed = 0;

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
                        if (($result['status'] ?? '') === 'ok') {
                            $sent++;
                            continue;
                        }

                        $failed++;
                        $details = $result['details'] ?? [];
                        if (($details['error'] ?? '') === 'DeviceNotRegistered') {
                            DeviceToken::where('token', $chunk[$i])->delete();
                        } else {
                            Log::warning('[Expo Push] Send gagal', ['error' => $result]);
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

        return ['sent' => $sent, 'failed' => $failed];
    }
}
