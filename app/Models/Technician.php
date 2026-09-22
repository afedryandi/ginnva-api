<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Technician extends Model
{
    // Global Scope store-id (audit framework 2026-09-14, "Isolasi data
    // multi-tenant") — lihat App\Models\Scopes\StoreScope. Pola manual
    // yang sudah ada di TechnicianResource::getEloquentQuery() SENGAJA
    // DIBIARKAN sebagai defense-in-depth.
    use HasStoreScope;

    // Audit framework 2026-09-14, "Jejak audit (audit trail) perubahan
    // data" -- commission_amount menentukan nominal Laporan Komisi
    // Teknisi (uang sungguhan dibayarkan), sebelumnya perubahan nilainya
    // (siapa/kapan/dari-berapa-ke-berapa) tidak tercatat sama sekali.
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['store_id', 'name', 'phone', 'level', 'commission_amount', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('technician');
    }

    protected $fillable = [
        'store_id',
        'user_id',
        'name',
        'phone',
        'level',
        // Nominal komisi TETAP per pekerjaan/booking untuk teknisi ini --
        // NULL = belum diatur (bukan Rp 0). Lihat migrasi
        // 2026_09_08_000002 & Laporan Komisi Teknisi.
        'commission_amount',
        'status',
        'notes',
    ];

    protected $casts = [
        'commission_amount' => 'decimal:2',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Akun installer (User ber-role 'installer') yang benar-benar
     * ditugaskan ke booking (lihat BookingResource "Installer Bertugas") —
     * opsional, supaya roster ini bisa ada duluan (mis. teknisi baru
     * direkrut) sebelum akun login-nya dibuat.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Tarif komisi per jenis layanan (audit Majoo, f34) — opsional,
     * lihat commissionForBooking() untuk aturan lengkap & fallback ke
     * commission_amount flat.
     */
    public function serviceRates(): HasMany
    {
        return $this->hasMany(TechnicianServiceRate::class);
    }

    /**
     * Komisi untuk 1 booking spesifik, INI satu-satunya tempat aturan
     * komisi teknisi dihitung — dipakai ulang oleh Laporan Komisi
     * Teknisi, Penjualan Per Periode, Penjualan Produk, & grafiknya,
     * supaya angkanya konsisten di mana pun ditampilkan.
     *
     * Aturan (keputusan user 2026-09-22):
     * - Teknisi TANPA baris serviceRates sama sekali -> pakai
     *   commission_amount FLAT (mode lama, backward compatible), null
     *   kalau belum diatur.
     * - Teknisi PUNYA minimal 1 baris serviceRates -> SELURUH booking
     *   dihitung mode per-layanan: commission_amount flat DIABAIKAN.
     *   Untuk tiap flag produk booking yang true (ppf/kaca_film/
     *   detailing/premium_wash), tarif jenis itu DIJUMLAH (bukan
     *   diambil salah satu) -- booking kombo PPF+Detailing bayar
     *   tarif PPF + tarif Detailing sekaligus. Kalau ADA flag true yang
     *   tarifnya belum diatur (atau tidak ada flag true sama sekali),
     *   seluruh booking itu dianggap BELUM BISA dihitung -> null
     *   (ditandai "belum diatur" oleh pemanggil, BUKAN dihitung
     *   sebagian/Rp 0 yang menyesatkan).
     */
    public function commissionForBooking(Booking $booking): ?float
    {
        if ($this->serviceRates->isEmpty()) {
            return $this->commission_amount !== null ? (float) $this->commission_amount : null;
        }

        $ratesByType = $this->serviceRates->pluck('commission_amount', 'service_type');

        $matchedTypes = array_filter([
            'ppf' => (bool) $booking->product_ppf,
            'kaca_film' => (bool) $booking->product_kaca_film,
            'detailing' => (bool) $booking->product_detailing,
            'premium_wash' => (bool) $booking->product_premium_wash,
        ]);

        if (empty($matchedTypes)) {
            return null;
        }

        $total = 0.0;
        foreach (array_keys($matchedTypes) as $type) {
            if (! $ratesByType->has($type)) {
                return null;
            }

            $total += (float) $ratesByType->get($type);
        }

        return $total;
    }
}
