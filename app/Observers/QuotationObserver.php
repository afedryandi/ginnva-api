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
     * contacted_at diisi OTOMATIS begitu status pertama kali berganti
     * dari 'new' — satu titik logika (event Eloquent), jadi berlaku sama
     * persis lewat Filament (EditQuotation) MAUPUN mobile app staff
     * (StaffQuotationController::updateStatus()), tidak perlu diulang di
     * 2 tempat. TIDAK ditimpa lagi kalau status berubah-ubah lagi setelah
     * itu — contacted_at mewakili "kapan PERTAMA kali direspons", bukan
     * "terakhir diubah".
     */
    public function updating(Quotation $quotation): void
    {
        if ($quotation->isDirty('status')
            && $quotation->getOriginal('status') === 'new'
            && $quotation->status !== 'new'
            && ! $quotation->contacted_at) {
            $quotation->contacted_at = now();
        }
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
            // 'route' sekarang ADA — layar detail Quotation staff sudah
            // dibangun (app/staff/quotations/[id].tsx), lihat audit modul
            // Quotation 2026-08-27. Sebelumnya notifikasi ini sengaja
            // tanpa deep link karena layarnya belum ada sama sekali.
            $this->push->sendToStoreStaff(
                $quotation->store_id,
                'Lead Baru',
                "Permintaan penawaran baru dari {$quotation->customer_name}.",
                ['type' => 'quotation_new', 'quotation_id' => $quotation->id, 'route' => "/staff/quotations/{$quotation->id}"]
            );
        }
    }
}