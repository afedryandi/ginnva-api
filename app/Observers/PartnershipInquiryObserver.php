<?php

namespace App\Observers;

use App\Filament\Resources\PartnershipInquiryResource;
use App\Models\PartnershipInquiry;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Sama pola dengan ProductInquiryObserver — sebelumnya tidak ada
 * notifikasi apa pun saat pengajuan kemitraan baru masuk, cuma badge
 * pasif di sidebar. Pengajuan ini malah lebih penting dari Inquiry
 * Produk (calon partner bisnis/franchise, bukan sekadar tanya produk).
 * Ditemukan & dibangun saat audit modul Marketing > Kemitraan & Sales
 * Referral.
 */
class PartnershipInquiryObserver
{
    public function created(PartnershipInquiry $inquiry): void
    {
        $recipients = User::where('is_active', true)->get()->filter(fn (User $user) => $user->isFullAccess()
            || $user->hasMenuAccess(PartnershipInquiryResource::class));

        $categoryLabel = match ($inquiry->category) {
            'sales' => 'Sales / Referral',
            'franchise' => 'Franchise',
            default => $inquiry->category,
        };

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title('Pengajuan Kemitraan Baru')
                ->body("{$inquiry->applicant_name} mengajukan kemitraan ({$categoryLabel}). Segera follow up.")
                ->warning()
                ->actions([
                    Action::make('view')
                        ->label('Lihat')
                        ->url(PartnershipInquiryResource::getUrl('edit', ['record' => $inquiry]))
                        ->markAsRead(),
                ])
                ->sendToDatabase($recipient);
        }
    }
}
