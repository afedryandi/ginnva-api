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
     * Tanggal terakhir mobil ini dikerjakan (inklusif) — hari libur toko
     * DILEWATI, tidak dihitung sebagai hari kerja (mis. lama pengerjaan 3
     * hari mulai Jumat, Minggu toko libur, jadi selesainya Senin — bukan
     * Minggu). Dipakai buat cek tumpang tindih kapasitas slot per hari.
     */
    public function getEndDateAttribute(): ?Carbon
    {
        if (! $this->preferred_date) return null;

        return self::nthWorkingDay($this->store, $this->preferred_date->copy(), $this->effective_duration_days);
    }

    /**
     * Majukan $startDate sampai hari kerja ke-$n (hari libur toko
     * dilewati, tidak dihitung) — $startDate SENDIRI dihitung hari ke-1
     * kalau toko buka di hari itu. $store null (mis. toko sudah
     * terhapus) diperlakukan seolah tidak pernah libur.
     *
     * Dibatasi maksimal 90 hari kalender ke depan — jaring pengaman kalau
     * data jam operasional toko salah isi (mis. ke-7 hari ditandai libur
     * semua), supaya tidak jadi infinite loop yang nge-hang request.
     */
    public static function nthWorkingDay(?Store $store, Carbon $startDate, int $n): Carbon
    {
        $day = $startDate->copy();
        $count = 0;

        for ($i = 0; $i < 90; $i++) {
            if (! $store?->isClosedOn($day)) {
                $count++;
                if ($count >= $n) {
                    return $day;
                }
            }

            $day->addDay();
        }

        throw new \RuntimeException("Toko \"{$store?->name}\" sepertinya tutup terus-menerus (>90 hari) — cek lagi Jam Operasional toko ini.");
    }

    /**
     * SAMA seperti workingDatesInRange(), tapi hari libur toko IKUT
     * disertakan (ditandai closed=true) — murni untuk TAMPILAN, supaya
     * staff tahu kenapa ada lompatan tanggal (mis. 08 → 10 karena 09
     * libur), bukan dikira sistem salah hitung. TIDAK dipakai untuk
     * validasi kapasitas (itu tetap lewat workingDatesInRange()/
     * fullDatesInRange() — kolom kapasitas cuma ada untuk hari kerja).
     *
     * Return ['complete' => bool, 'dates' => [['date' => Y-m-d, 'closed' => bool], ...]].
     * 'complete' false berarti kena batas 90 hari kalender sebelum
     * $durationDays hari kerja tercapai (data Jam Operasional toko
     * kemungkinan salah isi, mis. semua hari ditandai libur).
     */
    public static function calendarWalkWithClosedDays(int $storeId, Carbon $startDate, int $durationDays): array
    {
        $store = Store::find($storeId);
        $dates = [];
        $day = $startDate->copy();
        $counted = 0;
        $daysScanned = 0;

        while ($counted < $durationDays && $daysScanned < 90) {
            $daysScanned++;
            $closed = (bool) $store?->isClosedOn($day);

            $dates[] = ['date' => $day->toDateString(), 'closed' => $closed];

            if (! $closed) {
                $counted++;
            }

            $day->addDay();
        }

        return ['complete' => $counted >= $durationDays, 'dates' => $dates];
    }

    /**
     * Berapa booking berstatus 'confirmed' di toko ini yang jadwalnya
     * menyentuh tanggal $date (rentang preferred_date..end_date-nya
     * overlap $date, hari libur toko sudah dilewati saat hitung end_date
     * masing-masing) — dasar hitung sisa slot per hari. Cuma booking
     * 'confirmed' yang dihitung; 'pending' SENGAJA tidak diikutkan supaya
     * banyak customer boleh ajukan tanggal yang sama sebelum staff
     * triase & approve sampai maksimal kapasitas (mirip waiting list).
     *
     * Dibatasi preferred_date maksimal 45 hari sebelum $date (dilebihkan
     * dari 30 supaya tetap aman menampung hari libur yang memperpanjang
     * rentang booking lama) supaya tidak scan semua booking 'confirmed'
     * sepanjang sejarah toko.
     */
    public static function confirmedOverlapCount(int $storeId, Carbon $date, ?int $excludeBookingId = null): int
    {
        $store = Store::find($storeId);

        return static::query()
            ->where('store_id', $storeId)
            ->where('status', 'confirmed')
            ->when($excludeBookingId, fn ($q) => $q->where('id', '!=', $excludeBookingId))
            ->whereDate('preferred_date', '<=', $date)
            ->whereDate('preferred_date', '>=', $date->copy()->subDays(45))
            ->get(['id', 'preferred_date', 'duration_days', 'product_ppf'])
            ->filter(fn (Booking $b) => self::nthWorkingDay(
                $store,
                $b->preferred_date->copy(),
                $b->duration_days ?? $b->effective_duration_days
            )->greaterThanOrEqualTo($date))
            ->count();
    }

    /**
     * Daftar tanggal (Y-m-d) HARI KERJA saja (hari libur toko dilewati)
     * dalam rentang $durationDays hari kerja mulai $startDate di toko
     * $storeId — dasar untuk minta staff isi kapasitas PER TANGGAL (tim
     * instalasi bisa beda-beda tiap hari, mis. 1 tim masih ngerjain mobil
     * dari hari sebelumnya atau izin) dan untuk preview "Sisa Slot
     * Instalasi". Dipakai Filament (BookingResource) dan endpoint mobile
     * GET .../capacity-preview.
     */
    public static function workingDatesInRange(int $storeId, Carbon $startDate, int $durationDays): array
    {
        $store = Store::find($storeId);
        $dates = [];
        $day = $startDate->copy();
        $daysScanned = 0;

        // Batas 90 hari kalender — jaring pengaman kalau data Jam
        // Operasional toko salah isi (mis. ke-7 hari ditandai libur).
        while (count($dates) < $durationDays && $daysScanned < 90) {
            $daysScanned++;

            if ($store?->isClosedOn($day)) {
                $day->addDay();
                continue;
            }

            $dates[] = $day->toDateString();
            $day->addDay();
        }

        if (count($dates) < $durationDays) {
            throw new \RuntimeException("Toko \"{$store?->name}\" sepertinya tutup terus-menerus (>90 hari) — cek lagi Jam Operasional toko ini.");
        }

        return $dates;
    }

    /**
     * Cek kapasitas SETIAP HARI KERJA dalam rentang $durationDays hari
     * kerja mulai $startDate di toko $storeId — dipakai sebelum booking
     * di-approve jadi 'confirmed' (BookingResource maupun endpoint mobile
     * /confirm) supaya tidak mungkin lolos approve kalau salah satu
     * harinya sudah penuh.
     *
     * $capacityByDate = [tanggal Y-m-d => kapasitas hari itu] — SENGAJA
     * per tanggal (bukan 1 angka global) karena kapasitas tim instalasi
     * riil bisa beda tiap hari (mis. 1 tim masih ngerjain mobil dari hari
     * sebelumnya, atau izin). Staff input manual tiap kali approve (lihat
     * form Booking di Filament & payload POST .../confirm) — TIDAK
     * pernah jadi setting tetap tersimpan. Tanggal yang tidak ada di
     * $capacityByDate fallback ke $defaultCapacity.
     *
     * Return array tanggal (Y-m-d) yang SUDAH PENUH; array kosong =
     * seluruh rentang masih ada slot.
     */
    public static function fullDatesInRange(int $storeId, Carbon $startDate, int $durationDays, array $capacityByDate, int $defaultCapacity = 3, ?int $excludeBookingId = null): array
    {
        $fullDates = [];

        foreach (self::workingDatesInRange($storeId, $startDate, $durationDays) as $dateStr) {
            $capacity = max(1, (int) ($capacityByDate[$dateStr] ?? $defaultCapacity));
            $day = Carbon::parse($dateStr);

            if (self::confirmedOverlapCount($storeId, $day, $excludeBookingId) >= $capacity) {
                $fullDates[] = $dateStr;
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
