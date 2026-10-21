<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\StaffInboxNotification;
use Illuminate\Support\Collection;

/**
 * Satu jalur kirim notifikasi ke staf (Notifikasi Fase 1, 2026-10-21): setiap pesan menghasilkan SATU baris kotak masuk (tabel
 * notifications, dibaca lonceng panel dan, Fase 2, aplikasi mobile) DAN push lewat PushNotificationService. Dengan begitu pesan yang
 * terlewat di ponsel tidak hilang. Kegagalan salah satu sisi tidak menggagalkan sisi lain maupun proses pemanggil.
 *
 * Pesan berfrekuensi tinggi (mis. chat) memakai $coalesceKey: selama baris sebelumnya dengan kunci sama belum dibaca, barisnya diperbarui
 * (isi baru, waktu baru) alih-alih menambah baris, supaya kotak masuk tidak banjir.
 *
 * Titik pengiriman lama yang memanggil PushNotificationService::sendTo*() langsung dipindahkan ke sini bertahap; daftar yang sudah dan
 * belum dipindahkan ada di App\Support\NotificationCatalog.
 */
class StaffNotifier
{
    public function __construct(private PushNotificationService $push)
    {
    }

    /** @param  iterable<int|string>  $userIds */
    public function toUsers(iterable $userIds, string $title, string $body, array $data = [], ?string $coalesceKey = null, string $color = 'info'): void
    {
        $ids = collect($userIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        $this->inbox($ids, $title, $body, $data, $coalesceKey, $color);

        // Argumen push dipertahankan persis seperti panggilan langsung sebelumnya (larik id polos; data hanya bila ada).
        $data === []
            ? $this->push->sendToUsers($ids->all(), $title, $body)
            : $this->push->sendToUsers($ids->all(), $title, $body, $data);
    }

    /** Staf toko (dan super admin) seperti PushNotificationService::sendToStoreStaff(), dengan kotak masuk. */
    public function toStoreStaff(int $storeId, string $title, string $body, array $data = [], ?string $resourceClass = null, ?string $coalesceKey = null, string $color = 'info'): void
    {
        try {
            $this->inbox($this->push->storeStaffIds($storeId, $resourceClass)->filter()->unique()->values(), $title, $body, $data, $coalesceKey, $color);
        } catch (\Throwable $e) {
            report($e);
        }

        // Push lewat metode aslinya, dengan argumen persis seperti panggilan langsung sebelumnya (data dan kelas resource hanya bila ada).
        $args = [$storeId, $title, $body];
        if ($data !== [] || $resourceClass !== null) {
            $args[] = $data;
        }
        if ($resourceClass !== null) {
            $args[] = $resourceClass;
        }

        $this->push->sendToStoreStaff(...$args);
    }

    /** Direksi pemantau sebuah booking, dengan kotak masuk. */
    public function toBookingWatchers(\App\Models\Booking $booking, string $title, string $body, array $data = [], ?string $coalesceKey = null, string $color = 'info'): void
    {
        try {
            $this->inbox($booking->watchers()->pluck('users.id')->filter()->unique()->values(), $title, $body, $data, $coalesceKey, $color);
        } catch (\Throwable $e) {
            report($e);
        }

        $data === []
            ? $this->push->sendToBookingWatchers($booking, $title, $body)
            : $this->push->sendToBookingWatchers($booking, $title, $body, $data);
    }

    /** @param  Collection<int, int|string>  $ids */
    private function inbox(Collection $ids, string $title, string $body, array $data, ?string $coalesceKey, string $color): void
    {
        try {
            foreach (User::whereIn('id', $ids)->get() as $user) {
                if ($coalesceKey !== null) {
                    $existing = $user->unreadNotifications()
                        ->where('type', StaffInboxNotification::class)
                        ->where('data->viewData->coalesce_key', $coalesceKey)
                        ->first();

                    if ($existing) {
                        $existing->forceFill([
                            'data' => StaffInboxNotification::format($title, $body, $data, $coalesceKey, $color),
                            'created_at' => now(),
                        ])->save();

                        continue;
                    }
                }

                $user->notify(new StaffInboxNotification($title, $body, $data, $coalesceKey, $color));
            }
        } catch (\Throwable $e) {
            // Kotak masuk gagal tidak boleh menghalangi push maupun proses pemanggil.
            report($e);
        }
    }
}
