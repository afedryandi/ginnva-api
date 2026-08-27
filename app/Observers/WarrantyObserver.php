<?php

namespace App\Observers;

use App\Mail\WarrantyRegisteredMail;
use App\Models\Customer;
use App\Models\PointTransaction;
use App\Models\ScrollCode;
use App\Models\Warranty;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WarrantyObserver
{
    const POINTS_PER_APPROVAL = 100;

    public function __construct(private PushNotificationService $push)
    {
    }

    /**
     * Naikkan usage_count kode gulungan yang dipakai warranty ini.
     */
    public function created(Warranty $warranty): void
    {
        $this->markScrollCodesUsed($warranty);
    }

    /**
     * Saat warranty diupdate — cek apakah baru saja disetujui.
     */
    public function updated(Warranty $warranty): void
    {
        // Kalau staff mengganti kode gulungan yang sudah kepilih (salah
        // pilih lalu dikoreksi), lepas dulu kode LAMA-nya sebelum menandai
        // yang baru — kalau tidak, kode lama tetap terkunci 'used'/
        // usage_count-nya tetap naik selamanya walau fisiknya tidak pernah
        // benar-benar dipakai.
        $this->releaseReplacedScrollCode($warranty, 'roll_number');
        $this->releaseReplacedScrollCode($warranty, 'roll_number_2');
        $this->releaseReplacedScrollCode($warranty, 'roll_number_front');
        $this->releaseReplacedScrollCode($warranty, 'roll_number_side_rear');

        // Tandai scroll code baru kalau ada perubahan field roll_number
        if ($warranty->wasChanged(['roll_number', 'roll_number_2', 'roll_number_front', 'roll_number_side_rear'])) {
            $this->markScrollCodesUsed($warranty);
        }

        // SEBELUMNYA tidak ada notifikasi apa pun (push/email) saat status
        // review garansi berubah — customer harus buka app sendiri dan cek
        // manual untuk tahu garansinya di-approve/reject. Push notif
        // ditembak untuk KEDUA transisi (approved & rejected), tapi cuma
        // kalau warranty sudah tertaut ke akun (customer_id ada) — guest
        // yang belum klaim akun tidak punya kanal untuk dikirimi apa pun
        // dari sini. Lihat audit modul Garansi 2026-08-27.
        // Approved: sama seperti kondisi pemberian poin di bawah — dipicu
        // baik saat review_status BARU jadi approved (customer sudah ada),
        // MAUPUN saat customer_id BARU dipasang ke warranty yang SUDAH
        // approved sebelumnya (2 urutan kerja staff yang mungkin, lihat
        // catatan di blok poin). Rejected tidak butuh kondisi kedua itu —
        // warranty yang ditolak tidak pernah baru dapat customer_id setelahnya.
        $justApproved = ($warranty->wasChanged('review_status') || $warranty->wasChanged('customer_id'))
            && $warranty->review_status === 'approved'
            && $warranty->customer_id !== null;
        $justRejected = $warranty->wasChanged('review_status')
            && $warranty->review_status === 'rejected'
            && $warranty->customer_id !== null;

        if ($justApproved || $justRejected) {
            if ($justApproved) {
                $this->push->sendToCustomer(
                    $warranty->customer_id,
                    'Garansi Disetujui',
                    "Garansi {$warranty->warranty_code} Anda sudah disetujui dan sertifikat E-Warranty bisa diunduh.",
                    ['type' => 'warranty_approved', 'route' => "/account/warranty-detail?id={$warranty->id}"]
                );

                // Email "Garansi Terdaftar" — SEBELUMNYA kelas Mailable +
                // view lengkap tersedia tapi tidak pernah dipanggil di
                // mana pun (dead code). Dikirim di titik APPROVED (bukan
                // saat submit mentah) karena warranty_code final baru
                // pasti terisi di titik ini (lihat Warranty::booted()).
                // Cuma untuk warranty yang tertaut akun DAN akun itu
                // punya email — banyak customer daftar cuma pakai nomor
                // HP (OTP WhatsApp), jadi email opsional.
                if ($warranty->customer?->email) {
                    try {
                        Mail::to($warranty->customer->email)->send(new WarrantyRegisteredMail($warranty));
                    } catch (\Exception $e) {
                        Log::error('Gagal mengirim email registrasi garansi', [
                            'warranty_id' => $warranty->id,
                            'error'       => $e->getMessage(),
                        ]);
                    }
                }
            } elseif ($justRejected) {
                $this->push->sendToCustomer(
                    $warranty->customer_id,
                    'Garansi Ditolak',
                    $warranty->rejection_reason
                        ? "Pengajuan garansi Anda ditolak: {$warranty->rejection_reason}"
                        : 'Pengajuan garansi Anda ditolak. Silakan hubungi toko untuk informasi lebih lanjut.',
                    ['type' => 'warranty_rejected', 'route' => "/account/warranty-detail?id={$warranty->id}"]
                );
            }
        }

        // Proses kalau REVIEW_STATUS baru jadi 'approved' (dengan customer
        // sudah terpasang), ATAU CUSTOMER_ID yang baru dipasang/diganti
        // (dengan warranty sudah 'approved' sebelumnya) — dua kemungkinan
        // urutan staff kerja di Filament: approve dulu baru pasang
        // customer, atau pasang customer dulu baru approve. Sebelumnya
        // cuma kemungkinan pertama yang ditangani — staff yang manual
        // pilih customer di warranty yang SUDAH approved (lewat dropdown
        // "Akun Customer") tidak pernah dapat poin sama sekali karena
        // review_status-nya tidak berubah di update itu.
        if (
            (! $warranty->wasChanged('review_status') && ! $warranty->wasChanged('customer_id')) ||
            $warranty->review_status !== 'approved' ||
            $warranty->customer_id === null
        ) {
            return;
        }

        $customer = $warranty->customer;
        if (! $customer) return;

        // SEBELUMNYA: cek "sudah pernah reward atau belum" lalu insert
        // dilakukan TANPA lock & TANPA transaction — dua event `updated()`
        // yang overlap (staff dobel-klik approve, atau update customer_id
        // & review_status hampir bersamaan lewat 2 request) bisa dua-duanya
        // lolos exists()=false sebelum salah satu commit, kasih 100 poin
        // dobel. Dikunci lewat row lock di Customer supaya request kedua
        // WAJIB menunggu request pertama commit dulu — begitu lock
        // didapat, exists() check pasti sudah lihat hasil terbaru.
        DB::transaction(function () use ($warranty, $customer) {
            $lockedCustomer = Customer::where('id', $customer->id)->lockForUpdate()->first();
            if (! $lockedCustomer) return;

            $alreadyRewarded = PointTransaction::where('reference_type', 'warranty')
                ->where('reference_id', $warranty->id)
                ->exists();

            if ($alreadyRewarded) return;

            PointTransaction::create([
                'customer_id'    => $lockedCustomer->id,
                'type'           => 'earn',
                'points'         => self::POINTS_PER_APPROVAL,
                'description'    => "Garansi {$warranty->warranty_code} disetujui",
                'reference_type' => 'warranty',
                'reference_id'   => $warranty->id,
            ]);

            // Update saldo di tabel customers (denormalized untuk performa)
            $lockedCustomer->increment('loyalty_points', self::POINTS_PER_APPROVAL);
        });
    }

    /**
     * Hapus warranty juga harus melepas ScrollCode yang dipakainya —
     * kalau tidak, kode itu terkunci 'used'/usage_count-nya tetap naik
     * selamanya walau warranty yang memakainya sudah tidak ada.
     */
    public function deleted(Warranty $warranty): void
    {
        $this->releaseScrollCode($warranty->roll_number);
        $this->releaseScrollCode($warranty->roll_number_2);
        $this->releaseScrollCode($warranty->roll_number_front);
        $this->releaseScrollCode($warranty->roll_number_side_rear);
    }

    /**
     * Dipanggil saat field roll_number* berubah value-nya (bukan cuma
     * diisi pertama kali) — lepas kode LAMA yang ditinggalkan.
     */
    private function releaseReplacedScrollCode(Warranty $warranty, string $field): void
    {
        if (! $warranty->wasChanged($field)) return;

        $oldCode = $warranty->getOriginal($field);
        $newCode = $warranty->{$field};

        if (! $oldCode || $oldCode === $newCode) return;

        $this->releaseScrollCode($oldCode);
    }

    /**
     * Kembalikan pemakaian 1 ScrollCode — dipanggil saat warranty yang
     * memakainya dihapus, atau kode-nya diganti ke yang lain. Selalu
     * turunkan usage_count 1.
     *
     * Status 'used' HANYA di-auto-reopen ke 'allocated' kalau ada
     * max_usage yang diisi admin DAN usage_count sekarang di bawahnya —
     * itu tandanya kode ini memang dikelola otomatis lewat kapasitas.
     * Kalau max_usage kosong, status 'used' berarti ditandai manual lewat
     * "Tandai Habis" (staff bilang gulungan fisiknya sudah habis) — TIDAK
     * boleh ke-reopen otomatis cuma karena 1 warranty lama yang memakainya
     * dihapus/diganti, staff yang harus buka manual kalau memang keliru.
     */
    private function releaseScrollCode(?string $code): void
    {
        if (! $code) return;

        DB::transaction(function () use ($code) {
            $scrollCode = ScrollCode::where('code', $code)->lockForUpdate()->first();
            if (! $scrollCode) return;

            if ($scrollCode->usage_count > 0) {
                $scrollCode->decrement('usage_count');
            }

            if ($scrollCode->status === 'used' && $scrollCode->max_usage && $scrollCode->usage_count < $scrollCode->max_usage) {
                $scrollCode->update([
                    'status'        => 'allocated',
                    'used_at'       => null,
                    'warranty_code' => null,
                ]);
            }
        });
    }

    /**
     * PPF dan Window Film sekarang pakai mekanisme usage_count/max_usage
     * yang SAMA PERSIS — 1 gulungan boleh dipakai berkali-kali (PPF juga
     * bisa dipakai >1 mobil, mis. sisa potongan gulungan besar), TIDAK
     * otomatis 'used' kecuali admin isi "Kapasitas Gulungan" (max_usage)
     * dan tercapai. Kalau max_usage tidak diisi, kode tetap muncul di
     * pilihan warranty baru terus — admin/staff yang tandai manual lewat
     * "Tandai Habis" begitu gulungan fisiknya benar-benar habis.
     */
    private function markScrollCodesUsed(Warranty $warranty): void
    {
        // roll_number_2 (gulungan PPF kedua, untuk mobil yang butuh 2
        // gulungan) diperlakukan SAMA PERSIS seperti roll_number.
        foreach (array_filter([$warranty->roll_number, $warranty->roll_number_2]) as $code) {
            $this->incrementScrollCodeUsage($code, $warranty->warranty_code);
        }

        foreach (array_filter([$warranty->roll_number_front, $warranty->roll_number_side_rear]) as $code) {
            $this->incrementScrollCodeUsage($code, null);
        }
    }

    /**
     * Naikkan usage_count 1 kode gulungan, lalu tandai 'used' otomatis
     * kalau usage_count sudah mencapai max_usage yang diisi admin. Kalau
     * max_usage kosong, tidak pernah auto-'used' — staff tandai manual
     * lewat "Tandai Habis". Dikunci per kode (bukan update() langsung
     * tanpa lock) — 2 warranty yang kebetulan diisi kode roll fisik yang
     * SAMA (staff salah pilih/duplikat, atau 2 staff beda toko simpan
     * hampir bersamaan) bisa dua-duanya lolos cek "belum mentok" sebelum
     * salah satu commit kalau tanpa lock, jadi usage_count/max_usage bisa
     * salah hitung.
     */
    private function incrementScrollCodeUsage(string $code, ?string $warrantyCode): void
    {
        DB::transaction(function () use ($code, $warrantyCode) {
            $scrollCode = ScrollCode::where('code', $code)->lockForUpdate()->first();
            if (! $scrollCode || $scrollCode->status === 'used') return;

            $scrollCode->increment('usage_count');

            if ($warrantyCode) {
                $scrollCode->warranty_code = $warrantyCode;
            }

            if ($scrollCode->max_usage && $scrollCode->usage_count >= $scrollCode->max_usage) {
                $scrollCode->status  = 'used';
                $scrollCode->used_at = now();
            }

            $scrollCode->save();
        });
    }
}
