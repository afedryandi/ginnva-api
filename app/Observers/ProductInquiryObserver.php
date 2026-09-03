<?php

namespace App\Observers;

use App\Filament\Resources\ProductInquiryResource;
use App\Models\ProductInquiry;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;

/**
 * SEBELUMNYA tidak ada notifikasi apa pun saat inquiry baru masuk — cuma
 * mengandalkan badge angka pasif di sidebar (getNavigationBadge()), yang
 * cuma kelihatan kalau staff kebetulan sedang buka Filament. Inquiry ini
 * adalah lead penjualan produk premium (Color Change & Architectural
 * Film) — sama pentingnya dengan review negatif yang sudah punya
 * notifikasi (lihat StoreReviewObserver), jadi dibuatkan pola yang sama.
 * Ditemukan & dibangun saat audit modul Marketing > Inquiry Produk.
 */
class ProductInquiryObserver
{
    public function created(ProductInquiry $inquiry): void
    {
        // Company-wide (bukan per-toko, lihat komentar canViewAny() di
        // ProductInquiryResource) — semua full-access + staff yang
        // dicentang akses menu ini yang dapat notifikasi, bukan cuma
        // 1 toko tertentu.
        $recipients = User::where('is_active', true)->get()->filter(fn (User $user) => $user->isFullAccess()
            || $user->hasMenuAccess(ProductInquiryResource::class));

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title('Inquiry Produk Baru')
                ->body("{$inquiry->customer_name} menanyakan produk yang belum tersedia. Segera follow up.")
                ->warning()
                ->actions([
                    Action::make('view')
                        ->label('Lihat')
                        ->url(ProductInquiryResource::getUrl('edit', ['record' => $inquiry]))
                        ->markAsRead(),
                ])
                ->sendToDatabase($recipient);
        }
    }
}
