<?php

namespace App\Observers;

use App\Models\Customer;
use App\Models\PointTransaction;
use App\Models\ScrollCode;
use App\Models\Warranty;
use Illuminate\Support\Facades\DB;

class WarrantyObserver
{
    const POINTS_PER_APPROVAL = 100;

    /**
     * Tandai scroll code sebagai used saat warranty pertama kali dibuat.
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
        $this->releaseReplacedScrollCode($warranty, 'roll_number', single: true);
        $this->releaseReplacedScrollCode($warranty, 'roll_number_2', single: true);
        $this->releaseReplacedScrollCode($warranty, 'roll_number_front', single: false);
        $this->releaseReplacedScrollCode($warranty, 'roll_number_side_rear', single: false);

        // Tandai scroll code baru kalau ada perubahan field roll_number
        if ($warranty->wasChanged(['roll_number', 'roll_number_2', 'roll_number_front', 'roll_number_side_rear'])) {
            $this->markScrollCodesUsed($warranty);
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
        $this->releaseScrollCode($warranty->roll_number, single: true);
        $this->releaseScrollCode($warranty->roll_number_2, single: true);
        $this->releaseScrollCode($warranty->roll_number_front, single: false);
        $this->releaseScrollCode($warranty->roll_number_side_rear, single: false);
    }

    /**
     * Dipanggil saat field roll_number* berubah value-nya (bukan cuma
     * diisi pertama kali) — lepas kode LAMA yang ditinggalkan.
     */
    private function releaseReplacedScrollCode(Warranty $warranty, string $field, bool $single): void
    {
        if (! $warranty->wasChanged($field)) return;

        $oldCode = $warranty->getOriginal($field);
        $newCode = $warranty->{$field};

        if (! $oldCode || $oldCode === $newCode) return;

        $this->releaseScrollCode($oldCode, $single);
    }

    /**
     * Kembalikan 1 ScrollCode ke kondisi belum-terpakai — dipanggil saat
     * warranty yang memakainya dihapus, atau kode-nya diganti ke yang lain.
     *
     * single=true (PPF, 1 gulungan = 1 mobil): balikin ke status
     * 'allocated' (masih milik store yang sama, siap dipakai lagi).
     * single=false (Window Film, 1 gulungan dipakai berkali-kali): cukup
     * turunkan usage_count 1, dan kalau sebelumnya 'used' karena mentok
     * max_usage, balikin ke 'allocated' karena sekarang di bawah lagi.
     */
    private function releaseScrollCode(?string $code, bool $single): void
    {
        if (! $code) return;

        DB::transaction(function () use ($code, $single) {
            $scrollCode = ScrollCode::where('code', $code)->lockForUpdate()->first();
            if (! $scrollCode) return;

            if ($single) {
                if ($scrollCode->status === 'used') {
                    $scrollCode->update([
                        'status'        => 'allocated',
                        'used_at'       => null,
                        'warranty_code' => null,
                    ]);
                }
                return;
            }

            if ($scrollCode->usage_count > 0) {
                $scrollCode->decrement('usage_count');
            }

            if ($scrollCode->status === 'used'
                && (! $scrollCode->max_usage || $scrollCode->usage_count < $scrollCode->max_usage)
            ) {
                $scrollCode->update(['status' => 'allocated', 'used_at' => null]);
            }
        });
    }

    /**
     * PPF dan Window Film beda karakteristik pemakaian gulungan:
     * - PPF: 1 gulungan = 1 mobil — begitu dipakai, langsung 'used'.
     * - Window Film: 1 gulungan dipakai berkali-kali (kurang lebih 30
     *   mobil, kaca depan & samping/belakang beda gulungan) — TIDAK boleh
     *   langsung 'used' di pemakaian pertama, karena gulungan itu masih
     *   perlu tetap muncul di pilihan untuk mobil-mobil berikutnya yang
     *   pakai gulungan fisik yang sama. Cukup hitung usage_count; auto-
     *   'used' hanya kalau admin sudah isi max_usage dan tercapai — kalau
     *   belum diisi, admin yang manual tandai habis lewat Filament saat
     *   gulungan fisik benar-benar habis.
     */
    private function markScrollCodesUsed(Warranty $warranty): void
    {
        // roll_number_2 (gulungan PPF kedua, untuk mobil yang butuh 2
        // gulungan) diperlakukan SAMA PERSIS seperti roll_number — single-
        // use, langsung 'used' begitu dipakai. Dikunci per kode (bukan
        // update() langsung tanpa lock) — SEBELUMNYA dua warranty PPF
        // berbeda yang kebetulan diisi kode roll fisik yang SAMA (staff
        // salah pilih/duplikat, atau 2 staff beda toko simpan hampir
        // bersamaan) bisa dua-duanya lolos "belum used" sebelum salah
        // satu commit, dan yang commit BELAKANGAN diam-diam menimpa
        // warranty_code attribution milik yang commit duluan tanpa error
        // ke siapa pun.
        foreach (array_filter([$warranty->roll_number, $warranty->roll_number_2]) as $code) {
            DB::transaction(function () use ($code, $warranty) {
                $scrollCode = ScrollCode::where('code', $code)->lockForUpdate()->first();
                if (! $scrollCode || $scrollCode->status === 'used') return;

                $scrollCode->update([
                    'status'        => 'used',
                    'warranty_code' => $warranty->warranty_code,
                    'used_at'       => now(),
                ]);
            });
        }

        foreach (array_filter([$warranty->roll_number_front, $warranty->roll_number_side_rear]) as $code) {
            // Lock row-nya — 1 gulungan window film dipakai bergantian
            // untuk banyak mobil, jadi 2 warranty yang approve/simpan
            // hampir bersamaan dengan roll fisik yang sama BENAR-BENAR bisa
            // terjadi (bukan cuma teori). Tanpa lock, usage_count bisa
            // salah hitung (under/overshoot max_usage).
            DB::transaction(function () use ($code) {
                $scrollCode = ScrollCode::where('code', $code)->lockForUpdate()->first();
                if (! $scrollCode || $scrollCode->status === 'used') return;

                $scrollCode->increment('usage_count');

                if ($scrollCode->max_usage && $scrollCode->usage_count >= $scrollCode->max_usage) {
                    $scrollCode->update(['status' => 'used', 'used_at' => now()]);
                }
            });
        }
    }
}
