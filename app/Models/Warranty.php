<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Warranty extends Model
{
    use LogsActivity;

    protected $fillable = [
        'warranty_code',
        'customer_name',
        'phone_number',
        'car_plate',
        'car_type',
        'product_series',
        'product_category',
        'vin',
        'installation_position',
        'installation_position_detail',
        'roll_number',
        'roll_number_2',
        'roll_number_front',
        'roll_number_side_rear',
        'film_model_front',
        'film_model_side_rear',
        'installation_date',
        'expiry_date',
        'dealer_name',
        'store_id',
        'customer_id',
        'status',
        'review_status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
        'extension_years',
        'original_expiry_date',
    ];

    protected $casts = [
        'installation_date'    => 'date',
        'expiry_date'          => 'date',
        'original_expiry_date' => 'date',
        'reviewed_at'          => 'datetime',
    ];

    // Field tambahan yang otomatis ikut saat model di-convert ke JSON / array
    protected $appends = ['remaining_days'];

    /**
     * warranty_code PPF & Window Film SAMA-SAMA nomor sekuensial sendiri
     * (GNV-PPF-00001 / GNV-WF-00001, lihat generateSequentialWarrantyCode()),
     * lepas total dari kode roll fisik. Kode roll (roll_number/
     * roll_number_2 untuk PPF, roll_number_front/roll_number_side_rear
     * untuk Window Film) tetap tersimpan sebagai metadata audit internal
     * (dipakai ScrollCodeResource/WarrantyObserver untuk lacak status/
     * usage_count gulungan), TAPI TIDAK PERNAH dipakai sebagai kunci
     * pencarian publik (lihat WarrantyController::check() — hanya cari
     * lewat warranty_code/car_plate/vin/phone_number).
     *
     * Kenapa tidak langsung pakai kode roll sebagai warranty_code (seperti
     * PPF sebelumnya)? Karena:
     * 1. Sekarang PPF bisa pakai 2 gulungan (mobil besar) — tidak ada lagi
     *    "1 roll = 1 kode garansi" yang valid begitu saja.
     * 2. Window Film: 1 gulungan dipakai berkali-kali (~30 mobil/customer
     *    berbeda dengan roll fisik SAMA) — kalau warranty_code dari kode
     *    roll, siapa pun yang tahu kode roll fisik (tertera di kardus
     *    produk di toko) bisa menebak warranty_code mobil LAIN yang
     *    kebetulan pakai roll yang sama. Kode garansi harus jadi
     *    identitas PER MOBIL, bukan identitas per-gulungan-fisik.
     */
    protected static function booted(): void
    {
        static::saving(function (Warranty $warranty) {
            // Cuma di-generate SEKALI saat kode roll pertama kali diisi —
            // TIDAK regenerate lagi walau roll number diedit/diganti/
            // ditambah roll ke-2 belakangan, supaya warranty_code yang
            // sudah tercetak di E-Warranty PDF / sudah dikirim ke customer
            // tidak pernah berubah sendiri.
            if ($warranty->product_category === 'ppf') {
                if (
                    ! $warranty->warranty_code
                    && ($warranty->roll_number || $warranty->roll_number_2)
                ) {
                    $warranty->warranty_code = static::generateSequentialWarrantyCode('GNV-PPF');
                }
                return;
            }

            if ($warranty->product_category === 'window_film') {
                if (
                    ! $warranty->warranty_code
                    && ($warranty->roll_number_front || $warranty->roll_number_side_rear)
                ) {
                    $warranty->warranty_code = static::generateSequentialWarrantyCode('GNV-WF');
                }

                // Tipe film tidak lagi dipilih manual di form — otomatis
                // ikut produk yang terdaftar di ScrollCode kode gulungan
                // yang dipilih (lihat WarrantyResource, Detail Instalasi
                // Window Film).
                if ($warranty->isDirty('roll_number_front')) {
                    $warranty->film_model_front = $warranty->roll_number_front
                        ? ScrollCode::with('filmProduct')->where('code', $warranty->roll_number_front)->first()?->filmProduct?->name
                        : null;
                }

                if ($warranty->isDirty('roll_number_side_rear')) {
                    $warranty->film_model_side_rear = $warranty->roll_number_side_rear
                        ? ScrollCode::with('filmProduct')->where('code', $warranty->roll_number_side_rear)->first()?->filmProduct?->name
                        : null;
                }
            }
        });
    }

    /**
     * Nomor 5 digit ACAK GNV-PPF-XXXXX / GNV-WF-XXXXX — bukan sekuensial
     * (00001, 00002, dst). Sama pola dengan generator nomor lain di
     * codebase ini (QuotationResource::generateQuotationNumber(),
     * WarrantyClaim::generateClaimNumber()): suffix random + loop
     * exists() sebagai jaring pengaman. Suffix random berarti peluang 2
     * proses dapat kandidat yang SAMA PERSIS nyaris nol (beda dari
     * pendekatan sekuensial sebelumnya yang justru MENJAMIN tabrakan
     * kalau 2 proses baca "nomor terakhir" bersamaan) — jadi generator
     * ini TIDAK perlu DB::transaction()/lockForUpdate() sama sekali.
     */
    private static function generateSequentialWarrantyCode(string $prefix): string
    {
        do {
            $candidate = "{$prefix}-" . str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        } while (static::where('warranty_code', $candidate)->exists());

        return $candidate;
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Akun customer (mobile app) yang submit warranty ini, kalau ada.
     * Nullable — warranty yang disubmit sebagai guest (tanpa login)
     * tidak terhubung ke akun manapun, tapi tetap valid seperti biasa.
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Riwayat klaim after-sales (Worry Free Wrap / Product Warranty /
     * Other). 1 warranty bisa punya banyak klaim sepanjang waktu.
     */
    public function claims()
    {
        return $this->hasMany(WarrantyClaim::class);
    }

    /**
     * remaining_days TIDAK disimpan di kolom database — dihitung otomatis
     * setiap kali data diambil, supaya selalu akurat tanpa perlu update manual setiap hari.
     */
    public function getRemainingDaysAttribute(): int
    {
        $days = Carbon::now()->diffInDays($this->expiry_date, false);
        return max(0, (int) $days);
    }

    /**
     * Status otomatis disesuaikan:
     * - Kalau review_status belum approved (masih pending_review atau
     *   rejected), warranty TIDAK dianggap aktif sama sekali — apapun
     *   nilai kolom `status` di database. Ini konsekuensi dari QA
     *   Certificate review: submission baru wajib di-approve dulu oleh
     *   super_admin sebelum garansi benar-benar berlaku.
     * - Kalau sudah approved tapi sudah lewat expiry_date -> expired.
     * - Selain itu, ikuti nilai kolom `status` apa adanya.
     */
    public function getStatusAttribute($value): string
    {
        if (($this->attributes['review_status'] ?? null) === 'pending_review') {
            return 'pending_review';
        }

        if (($this->attributes['review_status'] ?? null) === 'rejected') {
            return 'rejected';
        }

        if ($this->expiry_date && Carbon::now()->greaterThan($this->expiry_date)) {
            return 'expired';
        }

        return $value;
    }

    public function getActivitylogOptions(): LogOptions
    {
        // Field keputusan approve/reject & perpanjangan garansi — bukan
        // 'status' (itu computed accessor, bukan nilai mentah, gampang
        // membingungkan kalau ditampilkan sebagai "sebelum/sesudah" di log).
        return LogOptions::defaults()
            ->logOnly(['review_status', 'rejection_reason', 'reviewed_by', 'extension_years', 'expiry_date', 'store_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('warranty')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Garansi {$this->warranty_code} dibuat",
                'updated' => "Garansi {$this->warranty_code} diubah",
                'deleted' => "Garansi {$this->warranty_code} dihapus",
                default   => "Garansi {$this->warranty_code} — {$eventName}",
            });
    }
}