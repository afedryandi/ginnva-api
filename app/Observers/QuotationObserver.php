<?php

namespace App\Observers;

use App\Mail\NewQuotationMail;
use App\Models\Quotation;
use App\Models\User;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class QuotationObserver
{
    public function __construct(private PushNotificationService $push)
    {
    }

    /**
     * Kirim notifikasi email + push ke staff toko (role apa pun SELAIN
     * installer/partner — role divisi seperti Store Manager, dst) yang
     * terkait saat ada quotation (lead sales) baru masuk dari mobile app.
     * Sama pola dengan BookingObserver — fallback ke semua isFullAccess()
     * kalau toko tersebut belum punya staff terdaftar.
     */
    public function created(Quotation $quotation): void
    {
        // Quotation yang staff input manual lewat Filament (mis. lead
        // walk-in) tidak perlu notifikasi — staff yang bersangkutan sudah
        // tahu karena mereka sendiri yang baru saja membuatnya.
        if ($quotation->source === 'staff') {
            return;
        }

        $staff = User::where('store_id', $quotation->store_id)
            ->get()
            ->filter(fn (User $u) => $u->isRestrictedStaff());

        $recipients = $staff->pluck('email');

        if ($recipients->isEmpty()) {
            $recipients = User::role(['super_admin', 'direksi'])->pluck('email');
        }

        if ($recipients->isNotEmpty()) {
            try {
                Mail::to($recipients->all())->send(new NewQuotationMail($quotation));
            } catch (\Exception $e) {
                // Jangan sampai kegagalan kirim email menggagalkan proses
                // pembuatan quotation itu sendiri — cukup dicatat di log.
                Log::error('Gagal mengirim notifikasi email quotation baru', [
                    'quotation_id' => $quotation->id,
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        if ($quotation->store_id) {
            // SENGAJA tidak ada 'route' — belum ada layar detail Quotation
            // di mobile app staff (dikelola lewat Filament), jadi tap
            // notifikasi ini cukup buka app tanpa deep link.
            $this->push->sendToStoreStaff(
                $quotation->store_id,
                'Lead Baru',
                "Permintaan penawaran baru dari {$quotation->customer_name}.",
                ['type' => 'quotation_new', 'quotation_id' => $quotation->id]
            );
        }
    }
}
