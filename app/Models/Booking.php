<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Booking extends Model
{
    use LogsActivity;

    // Default lama pengerjaan (hari) per jenis produk kalau staff tidak
    // isi manual — dipakai getEffectiveDurationDaysAttribute() &
    // Booking::booted(). PPF butuh beberapa hari, Kaca Film biasanya
    // selesai 1 hari.
    public const DEFAULT_DURATION_DAYS_PPF     = 3;
    public const DEFAULT_DURATION_DAYS_DEFAULT = 1;

    protected $fillable = [
        'booking_number',
        'customer_id',
        'customer_name',
        'phone_number',
        'store_id',
        'service_type',
        'product_kaca_film',
        'product_ppf',
        'preferred_date',
        'preferred_time',
        'duration_days',
        'notes',
        'source',
        'status',
        'current_stage',
        'secondary_stage',
        'next_service_reminder_at',
        'service_reminder_sent_at',
        'referral_code',
        'transaction_amount',
        'partner_id',
        'voucher_claim_id',
    ];

    protected $casts = [
        'preferred_date' => 'date',
        'transaction_amount' => 'decimal:2',
        'product_kaca_film' => 'boolean',
        'product_ppf' => 'boolean',
        'duration_days' => 'integer',
        'next_service_reminder_at' => 'date',
        'service_reminder_sent_at' => 'datetime',
    ];

    protected $appends = ['end_date'];

    /**
     * duration_days SELALU diisi Booking::booted() saat dibuat (lihat di
     * bawah), jadi accessor ini cuma jaring pengaman untuk row lama/edge
     * case — tidak boleh dipakai untuk query DB (query kapasitas pakai
     * kolom duration_days langsung, lihat confirmedOverlapCount()).
     */
    public function getEffectiveDurationDaysAttribute(): int
    {
        return $this->duration_days
            ?? ($this->product_ppf ? self::DEFAULT_DURATION_DAYS_PPF : self::DEFAULT_DURATION_DAYS_DEFAULT);
    }

    /**
     * Tanggal terakhir mobil ini dikerjakan (inklusif) — dipakai buat cek
     * tumpang tindih kapasitas slot per hari.
     */
    public function getEndDateAttribute(): ?Carbon
    {
        if (! $this->preferred_date) return null;

        return $this->preferred_date->copy()->addDays($this->effective_duration_days - 1);
    }

    /**
     * Berapa booking berstatus 'confirmed' di toko ini yang jadwalnya
     * menyentuh tanggal $date (rentang preferred_date..end_date-nya
     * overlap $date) — dasar hitung sisa slot per hari. Cuma booking
     * 'confirmed' yang dihitung; 'pending' SENGAJA tidak diikutkan supaya
     * banyak customer boleh ajukan tanggal yang sama sebelum staff
     * triase & approve sampai maksimal kapasitas (mirip waiting list).
     *
     * Dibatasi preferred_date maksimal 30 hari sebelum $date (booking
     * lebih lama dari itu tidak realistis) supaya tidak scan semua
     * booking 'confirmed' sepanjang sejarah toko.
     */
    public static function confirmedOverlapCount(int $storeId, Carbon $date, ?int $excludeBookingId = null): int
    {
        return static::query()
            ->where('store_id', $storeId)
            ->where('status', 'confirmed')
            ->when($excludeBookingId, fn ($q) => $q->where('id', '!=', $excludeBookingId))
            ->whereDate('preferred_date', '<=', $date)
            ->whereDate('preferred_date', '>=', $date->copy()->subDays(30))
            ->get(['id', 'preferred_date', 'duration_days', 'product_ppf'])
            ->filter(fn (Booking $b) => $b->end_date?->greaterThanOrEqualTo($date))
            ->count();
    }

    /**
     * Cek kapasitas SETIAP hari dalam rentang [$startDate, $startDate +
     * $durationDays - 1] di toko $storeId — dipakai sebelum booking
     * di-approve jadi 'confirmed' (BookingResource maupun endpoint mobile
     * /confirm) supaya tidak mungkin lolos approve kalau salah satu
     * harinya sudah penuh. $capacityPerDay SENGAJA bukan setting tetap
     * per toko — staff yang input manual tiap kali approve (lihat form
     * Booking di Filament & payload POST .../confirm), supaya fleksibel
     * kalau kapasitas real hari itu beda dari biasanya. Return array
     * tanggal (Y-m-d) yang SUDAH PENUH; array kosong = seluruh rentang
     * masih ada slot.
     */
    public static function fullDatesInRange(int $storeId, Carbon $startDate, int $durationDays, int $capacityPerDay, ?int $excludeBookingId = null): array
    {
        $fullDates = [];

        for ($i = 0; $i < $durationDays; $i++) {
            $day = $startDate->copy()->addDays($i);

            if (self::confirmedOverlapCount($storeId, $day, $excludeBookingId) >= $capacityPerDay) {
                $fullDates[] = $day->toDateString();
            }
        }

        return $fullDates;
    }

    /**
     * Booking dengan 2 produk (Kaca Film + PPF) punya progress PARALEL —
     * Kaca Film selalu di `current_stage` (kolom lama, jadi single-product
     * booking tidak perlu berubah sama sekali), PPF di `secondary_stage`
     * KHUSUS saat dua-duanya dipesan. Tahap bersama (qc/completed) selalu
     * balik ke `current_stage` apa pun kondisi produknya.
     */
    public static function stageColumnFor(bool $hasBothProducts, string $stage): string
    {
        $isPpfStage = array_key_exists($stage, BookingMessage::PRODUCT_STAGES['ppf']);

        if ($hasBothProducts && $isPpfStage) {
            return 'secondary_stage';
        }

        return 'current_stage';
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Bisa lebih dari 1 installer per booking (mis. tim instalasi berdua)
     * — dulu cuma 1 lewat kolom installer_user_id, sekarang pivot
     * many-to-many sama seperti watchers().
     */
    public function installers()
    {
        return $this->belongsToMany(User::class, 'booking_installers')->withTimestamps();
    }

    /**
     * Direksi yang ditunjuk Store Manager untuk memantau booking ini secara
     * khusus — dipakai supaya notifikasi chat (push & email) tidak di-blast
     * ke SEMUA direksi, cukup ke yang memang ditugaskan (lihat
     * PushNotificationService::sendToBookingWatchers()).
     */
    public function watchers()
    {
        return $this->belongsToMany(User::class, 'booking_watchers')->withTimestamps();
    }

    public function messages()
    {
        return $this->hasMany(BookingMessage::class)->orderBy('created_at');
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function voucherClaim()
    {
        return $this->belongsTo(VoucherClaim::class);
    }

    protected static function booted(): void
    {
        static::creating(function (Booking $booking) {
            if (empty($booking->booking_number)) {
                $booking->booking_number = static::generateBookingNumber();
            }

            // duration_days SELALU diisi eksplisit di sini (bukan cuma
            // fallback di accessor) supaya query kapasitas
            // (confirmedOverlapCount) bisa langsung pakai kolomnya tanpa
            // perlu tahu product_ppf row lain satu-satu.
            if (empty($booking->duration_days)) {
                $booking->duration_days = $booking->product_ppf
                    ? self::DEFAULT_DURATION_DAYS_PPF
                    : self::DEFAULT_DURATION_DAYS_DEFAULT;
            }
        });
    }

    protected static function generateBookingNumber(): string
    {
        do {
            $candidate = 'BKG-' . now()->format('Ym') . '-' . Str::upper(Str::random(4));
        } while (static::where('booking_number', $candidate)->exists());

        return $candidate;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'current_stage', 'secondary_stage', 'store_id', 'referral_code', 'transaction_amount', 'partner_id', 'voucher_claim_id', 'next_service_reminder_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('booking')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Booking #{$this->booking_number} dibuat",
                'updated' => "Booking #{$this->booking_number} diubah",
                'deleted' => "Booking #{$this->booking_number} dihapus",
                default   => "Booking #{$this->booking_number} — {$eventName}",
            });
    }
}
