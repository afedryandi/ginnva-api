<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DeviceToken;
use App\Models\ProductInquiry;
use App\Models\Quotation;
use Illuminate\Support\Facades\DB;

/**
 * Penghapusan akun pelanggan + anonimisasi data pribadinya. SATU-SATUNYA jalur penghapusan akun: dipakai app mobile
 * (Api\Customer\AuthController::deleteAccount) DAN tombol Hapus di panel admin (CustomerResource), supaya hasilnya sama
 * apa pun jalur permintaannya (permohonan lewat app, WhatsApp, email, atau datang ke toko).
 *
 * Booking & Warranty SENGAJA tidak disentuh -- itu catatan transaksi/garansi yang perlu tetap utuh untuk bukti pajak, audit
 * dan klaim garansi (keputusan 2026-09-14); tampilannya di panel diganti "Pelanggan Terhapus" tanpa mengubah data mentah.
 * Lead marketing publik (Quotation/ProductInquiry, tidak terhubung customer_id) yang memakai kontak yang sama ikut dianonimkan.
 */
class CustomerAccountDeletionService
{
    public function delete(Customer $customer): void
    {
        DB::transaction(function () use ($customer) {
            $oldPhone = $customer->phone_number;
            $oldEmail = $customer->email;

            // Quotation menyimpan telepon DAN email: dicocokkan lewat keduanya, dan KEDUA kolom kontaknya dikosongkan
            // (sebelumnya hanya yang cocok lewat telepon, dan customer_email tidak pernah dihapus).
            if ($oldPhone || $oldEmail) {
                Quotation::where(function ($query) use ($oldPhone, $oldEmail) {
                    if ($oldPhone) {
                        $query->orWhere('customer_phone', $oldPhone);
                    }
                    if ($oldEmail) {
                        $query->orWhere('customer_email', $oldEmail);
                    }
                })->update(['customer_name' => 'Pelanggan Terhapus', 'customer_phone' => null, 'customer_email' => null]);
            }

            if ($oldPhone) {
                ProductInquiry::where('customer_contact', $oldPhone)
                    ->update(['customer_name' => 'Pelanggan Terhapus', 'customer_contact' => '-']);
            }

            if ($oldEmail) {
                ProductInquiry::where('customer_contact', $oldEmail)
                    ->update(['customer_name' => 'Pelanggan Terhapus', 'customer_contact' => '-']);
            }

            DeviceToken::where('customer_id', $customer->id)->delete();

            $customer->update([
                'name'              => null,
                'email'             => null,
                'phone_number'      => null,
                'email_verified_at' => null,
                'phone_verified_at' => null,
            ]);
            $customer->delete();
        });
    }
}
