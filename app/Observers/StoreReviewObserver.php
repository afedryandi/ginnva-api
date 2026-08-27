<?php

namespace App\Observers;

use App\Filament\Resources\StoreReviewResource;
use App\Models\Store;
use App\Models\StoreReview;
use App\Models\User;
use App\Services\PushNotificationService;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * SEBELUMNYA tidak ada observer untuk StoreReview sama sekali — dua celah
 * yang ditutup di sini:
 * 1. Agregat rating (reviews_count/positive_reviews_count di Store) tidak
 *    pernah dihitung, jadi customer_facing "silo data mati".
 * 2. Review negatif tidak memicu apa pun ke staff — cuma badge merah di
 *    sidebar Filament yang baru kelihatan kalau staff login manual.
 * Lihat audit modul Review Toko 2026-08-27.
 */
class StoreReviewObserver
{
    public function __construct(private PushNotificationService $push)
    {
    }

    public function created(StoreReview $review): void
    {
        $this->incrementStoreAggregate($review);

        if ($review->sentiment === 'negative') {
            $this->notifyNegativeReview($review);
        }
    }

    /**
     * Dikunci per-row Store (lockForUpdate) — dua review yang masuk nyaris
     * bersamaan untuk toko yang sama tidak boleh dua-duanya baca angka
     * lama sebelum salah satu commit (lost update pada increment()
     * manual). increment() Eloquent sebenarnya sudah atomic di level SQL
     * (UPDATE ... SET x = x + 1), tapi tetap dibungkus transaction supaya
     * kedua kolom (reviews_count & positive_reviews_count kalau positif)
     * konsisten sebagai satu unit.
     */
    private function incrementStoreAggregate(StoreReview $review): void
    {
        if (! $review->store_id) return;

        DB::transaction(function () use ($review) {
            $store = Store::where('id', $review->store_id)->lockForUpdate()->first();
            if (! $store) return;

            $store->increment('reviews_count');
            if ($review->sentiment === 'positive') {
                $store->increment('positive_reviews_count');
            }
        });
    }

    private function notifyNegativeReview(StoreReview $review): void
    {
        if (! $review->store_id) return;

        // Push real-time ke staff toko (mobile app) — sama pola dengan
        // notifikasi booking chat.
        $this->push->sendToStoreStaff(
            $review->store_id,
            'Review Negatif Masuk',
            'Ada customer yang memberi review negatif untuk toko ini. Segera tindak lanjuti.',
            ['type' => 'store_review_negative', 'route' => '/staff/bookings']
        );

        // Bell notifikasi Filament — supaya tetap kelihatan walau staff
        // sedang tidak buka mobile app / push gagal terkirim.
        $recipients = User::all()->filter(fn (User $user) => $user->isFullAccess()
            || ((int) $user->store_id === (int) $review->store_id && $user->hasMenuAccess(StoreReviewResource::class))
            || $user->hasRole('direksi'));

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title('Review Negatif Masuk')
                ->body('Ada customer yang memberi review negatif. Perlu ditindaklanjuti.')
                ->danger()
                ->actions([
                    Action::make('view')
                        ->label('Lihat')
                        ->url(StoreReviewResource::getUrl('view', ['record' => $review]))
                        ->markAsRead(),
                ])
                ->sendToDatabase($recipient);
        }
    }
}
