<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Booking extends Model
{
    use LogsActivity;
    // Global Scope store-id (audit framework 2026-09-14, "Isolasi data
    // multi-tenant") — lihat App\Models\Scopes\StoreScope. Pola manual
    // yang sudah ada di BookingResource::getEloquentQuery() SENGAJA
    // DIBIARKAN (bukan dihapus) sebagai defense-in-depth; filter dari
    // scope ini no-op/redundan di sana, tapi jadi satu-satunya
    // proteksi untuk query LANGSUNG ke Booking:: di tempat lain
    // (widget/report/service) yang sebelumnya rawan lupa di-scope.
    use HasStoreScope;

    /**
     * Audit framework 2026-09-14, "Penanganan data pribadi (PII)
     * pelanggan" -- customer_name/phone_number di kolom asli SENGAJA
     * TETAP UTUH (kebutuhan bukti pajak/audit transaksi), tapi kalau
     * akun customer terkait sudah dihapus (customer_id ada tapi relasi
     * customer() tidak ketemu lagi karena soft-delete), tampilan di
     * Filament diganti generik. Dipakai BUKAN nama attribute asli,
     * supaya form/aksi lain yang baca customer_name/phone_number
     * langsung tidak ikut ketimpa string generik ini. Lihat pola sama
     * di Warranty::getDisplayCustomerNameAttribute().
     */
    public function getDisplayCustomerNameAttribute(): string
    {
        if ($this->customer_id && ! $this->customer) {
            return 'Pelanggan Terhapus';
        }

        return $this->customer_name ?? $this->customer?->name ?? $this->customer?->email ?? '—';
    }

    public function getDisplayPhoneNumberAttribute(): string
    {
        if ($this->customer_id && ! $this->customer) {
            return '—';
        }

        return $this->phone_number ?? $this->customer?->phone_number ?? '—';
    }

    // Default lama pengerjaan (hari) per jenis produk kalau staff tidak
    // isi manual — dipakai getEffectiveDurationDaysAttribute() &
    // Booking::booted(). PPF butuh beberapa hari, Kaca Film biasanya
    // selesai 1 hari.
    public const DEFAULT_DURATION_DAYS_PPF     = 3;
    public const DEFAULT_DURATION_DAYS_DEFAULT = 1;

    // Keputusan atasan 2026-09-19 (Topik 1, "Keputusan-PPN-DP-Produk-
    // Stok-Ginnva.docx"): harga customer SUDAH inclusive PPN 11%.
    public const PPN_RATE = 0.11;

    /**
     * Cap nego harga kasir (audit Majoo f31) -- keputusan user
     * 2026-09-22: staff booking non-full-access boleh proses transaksi
     * dengan harga didiskon SENDIRI (tanpa approval) selama diskonnya
     * ≤ 5% dari harga acuan matriks (PriceCalculator::priceFor()).
     * Global untuk semua toko/role (bukan per-toko), angka tetap
     * (bukan diedit lewat UI) -- pola sama dengan PPN_RATE di atas.
     */
    public const NEGOTIATION_DISCOUNT_MAX_PCT = 5.0;

    /**
     * @param  float|null  $referencePrice  Harga acuan dari
     *         PriceCalculator::priceFor() -- null kalau film_product_id/
     *         vehicle_size booking ini tidak diisi atau harga matriksnya
     *         belum ada. Referensi tidak diketahui SELALU dianggap DI
     *         LUAR cap (wajib approval) -- tidak pernah diloloskan tanpa
     *         verifikasi.
     */
    public static function isWithinNegotiationCap(?float $referencePrice, float $enteredAmount): bool
    {
        if ($referencePrice === null || $referencePrice <= 0) {
            return false;
        }

        if ($enteredAmount >= $referencePrice) {
            return true; // Bukan diskon (harga penuh atau lebih tinggi).
        }

        $discountPct = ($referencePrice - $enteredAmount) / $referencePrice * 100;

        return $discountPct <= self::NEGOTIATION_DISCOUNT_MAX_PCT;
    }

    /** Label kanal pembayaran (audit Majoo f3, "Breakdown Metode Pembayaran"). */
    public const PAYMENT_METHOD_LABELS = [
        'tunai' => 'Tunai',
        'transfer' => 'Transfer Bank',
        'qris' => 'QRIS',
        'edc' => 'EDC (Kartu Debit/Kredit)',
    ];

    protected $fillable = [
        'booking_number',
        'customer_id',
        'customer_name',
        'phone_number',
        'store_id',
        'service_type',
        'product_kaca_film',
        'product_ppf',
        // Penanda booking mencakup jasa detailing (sendiri / tambahan).
        // Lihat migrasi 2026_09_10_000003.
        'product_detailing',
        // Penanda booking mencakup jasa Premium Wash (sendiri / tambahan)
        // -- pola sama persis product_detailing. Lihat migrasi
        // 2026_09_22_000002.
        'product_premium_wash',
        // Varian/SKU FilmProduct spesifik yang dipasang -- opsional,
        // diisi staff/teknisi (biasanya saat booking selesai) supaya
        // laporan "Produk Terlaris" bisa dihitung dari transaksi
        // sungguhan. Lihat migrasi 2026_09_08_000001.
        'film_product_id',
        // Ukuran kendaraan (S/M/L/XL/XXL, lihat PriceCalculator::VEHICLE_SIZES)
        // -- opsional, dipakai hitung harga acuan matriks utk validasi
        // cap nego harga (lihat isWithinNegotiationCap()). Lihat migrasi
        // 2026_09_22_000012.
        'vehicle_size',
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
        // Rincian PPN (inclusive 11%) dari transaction_amount -- diisi
        // OTOMATIS oleh Booking::booted() 'saving', bukan diinput staff.
        // Tetap $fillable supaya seeder/test bisa override kalau perlu.
        'dpp_amount',
        'ppn_amount',
        'amount_received',
        // Kanal pembayaran (tunai/transfer/qris/edc) -- diisi saat Proses
        // Referral bareng nominal, opsional (nullable) supaya booking
        // lama tidak dipaksa punya nilai. Lihat migrasi
        // 2026_09_22_000009.
        'payment_method',
        'partner_id',
        'voucher_claim_id',
        // Promo Per Total Pembelian (potongan flat, diterapkan manual).
        // transaction_amount disimpan NET; spend_promo_discount = snapshot
        // potongan. Lihat migrasi 2026_09_10_000014.
        'spend_promo_id',
        'spend_promo_discount',
        'journal_entry_id',
    ];

    protected $casts = [
        'preferred_date' => 'date',
        'transaction_amount' => 'decimal:2',
        'dpp_amount' => 'decimal:2',
        'ppn_amount' => 'decimal:2',
        'amount_received' => 'decimal:2',
        'product_kaca_film' => 'boolean',
        'product_ppf' => 'boolean',
        'product_detailing' => 'boolean',
        'product_premium_wash' => 'boolean',
        'spend_promo_discount' => 'decimal:2',
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
     * GAP DIPERBAIKI 2026-09-25 (audit Kalender Kapasitas) -- versi batch
     * confirmedOverlapCount() di atas, khusus untuk kebutuhan grid
     * kalender bulan penuh (App\Filament\Pages\CapacityCalendar). Method
     * di atas 1 query per tanggal -- kalau dipanggil dalam loop 35-42
     * hari (1 grid kalender), jadi 35-42 query terpisah tiap kali halaman
     * dibuka. Method ini SATU query untuk seluruh rentang, lalu hitung
     * overlap tiap tanggal di PHP (murah, cuma iterasi array, bukan query
     * DB lagi).
     *
     * @return array<string, int> [tanggal Y-m-d => jumlah booking confirmed yang overlap]
     */
    public static function confirmedOverlapCountsForRange(int $storeId, Carbon $rangeStart, Carbon $rangeEnd, ?int $excludeBookingId = null): array
    {
        $store = Store::find($storeId);

        $counts = [];
        $cursor = $rangeStart->copy();
        while ($cursor->lte($rangeEnd)) {
            $counts[$cursor->toDateString()] = 0;
            $cursor->addDay();
        }

        $bookings = static::query()
            ->where('store_id', $storeId)
            ->where('status', 'confirmed')
            ->when($excludeBookingId, fn ($q) => $q->where('id', '!=', $excludeBookingId))
            ->whereDate('preferred_date', '<=', $rangeEnd)
            ->whereDate('preferred_date', '>=', $rangeStart->copy()->subDays(45))
            ->get(['id', 'preferred_date', 'duration_days', 'product_ppf']);

        foreach ($bookings as $booking) {
            $bookingStart = $booking->preferred_date->copy();
            $bookingEnd = self::nthWorkingDay($store, $bookingStart->copy(), $booking->duration_days ?? $booking->effective_duration_days);

            $day = $bookingStart->greaterThan($rangeStart) ? $bookingStart->copy() : $rangeStart->copy();
            $last = $bookingEnd->lessThan($rangeEnd) ? $bookingEnd->copy() : $rangeEnd->copy();

            while ($day->lte($last)) {
                $key = $day->toDateString();
                if (array_key_exists($key, $counts)) {
                    $counts[$key]++;
                }
                $day->addDay();
            }
        }

        return $counts;
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
     * Kapasitas instalasi 1 toko di 1 tanggal spesifik — SATU sumber
     * kebenaran (redesain 2026-09-25, diminta user langsung, audit
     * Booking Instalasi): override tersimpan (StoreCapacityOverride) kalau
     * ada, kalau tidak fallback ke Store::install_capacity_per_day.
     * SEBELUMNYA angka ini TIDAK PERNAH disimpan sama sekali — staff
     * mengetik ulang dari nol setiap kali approve booking yang menyentuh
     * tanggal itu (lewat Repeater 'capacities' di form Booking / payload
     * 'capacities' endpoint mobile /confirm), sehingga staff berbeda bisa
     * mengisi angka BERBEDA untuk tanggal yang SAMA tanpa sistem menegur
     * inkonsistensi. Sekarang kapasitas dikelola terpusat lewat kalender
     * (App\Filament\Pages\CapacityCalendar), booking tinggal membacanya.
     */
    public static function capacityForDate(int $storeId, Carbon $date): int
    {
        $override = StoreCapacityOverride::where('store_id', $storeId)
            ->whereDate('date', $date)
            ->first();

        if ($override) {
            return $override->capacity;
        }

        return max(1, (int) (Store::find($storeId)?->install_capacity_per_day ?: 3));
    }

    /**
     * Cek kapasitas SETIAP HARI KERJA dalam rentang $durationDays hari
     * kerja mulai $startDate di toko $storeId — dipakai sebelum booking
     * di-approve jadi 'confirmed' (BookingResource maupun endpoint mobile
     * /confirm) supaya tidak mungkin lolos approve kalau salah satu
     * harinya sudah penuh. Kapasitas tiap tanggal dibaca dari
     * capacityForDate() di atas — BUKAN lagi parameter yang diisi manual
     * staff tiap panggilan (lihat catatan lengkap di capacityForDate()).
     *
     * Return array tanggal (Y-m-d) yang SUDAH PENUH; array kosong =
     * seluruh rentang masih ada slot.
     */
    public static function fullDatesInRange(int $storeId, Carbon $startDate, int $durationDays, ?int $excludeBookingId = null): array
    {
        $fullDates = [];

        foreach (self::workingDatesInRange($storeId, $startDate, $durationDays) as $dateStr) {
            $day = Carbon::parse($dateStr);
            $capacity = self::capacityForDate($storeId, $day);

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

    /**
     * Varian/SKU FilmProduct UTAMA yang dipasang di booking ini --
     * opsional (nullable), lihat catatan di $fillable. TETAP field
     * tunggal (BUKAN diganti relasi hasMany) supaya 15+ file yang sudah
     * bergantung padanya (Master Resep, Invoice, Warranty, Laporan
     * Produk Terlaris, dst) tidak perlu diubah -- lihat filmProducts()
     * di bawah untuk produk TAMBAHAN per bagian kendaraan lain.
     */
    public function filmProduct()
    {
        // withTrashed() (audit Daftar Produk 2026-09-25, sejak FilmProduct
        // pakai SoftDeletes) -- booking lama tetap tampilkan nama produk
        // asli walau produknya sudah "dihapus" dari katalog aktif. Beda
        // dari Customer (PII, sengaja disembunyikan) -- data produk bukan
        // privasi, jadi tidak perlu placeholder generik.
        return $this->belongsTo(FilmProduct::class)->withTrashed();
    }

    /**
     * Produk TAMBAHAN per bagian kendaraan (keputusan atasan 2026-09-19,
     * Topik 3, "Keputusan-PPN-DP-Produk-Stok-Ginnva.docx") -- dicatat
     * staff toko saat booking dikonfirmasi, kalau booking ini genuinely
     * pakai lebih dari 1 varian produk (mis. kaca depan beda dari kaca
     * samping/belakang). Produk UTAMA tetap di film_product_id/
     * filmProduct() di atas -- ini cuma yang KEDUA dst. Lihat
     * BookingFilmProduct & migrasi create_booking_film_products_table.
     */
    public function filmProducts()
    {
        return $this->hasMany(BookingFilmProduct::class);
    }

    /**
     * Promo Per Total Pembelian yang diterapkan ke booking ini (opsional).
     * Lihat SpendPromo & migrasi 2026_09_10_000014.
     */
    public function spendPromo()
    {
        return $this->belongsTo(SpendPromo::class);
    }

    /**
     * Memo Barang (pengambilan/pengembalian bahan) yang terhubung ke
     * booking ini -- OPSIONAL, hasOne karena selalu 1 booking = 1 memo
     * (dikonfirmasi 2026-09-09, lihat migrasi
     * 2026_09_09_000001_add_booking_id_to_material_memos_table).
     * Dipakai section "Inventori Terpakai" di View Booking.
     */
    public function materialMemo()
    {
        return $this->hasOne(MaterialMemo::class);
    }

    /**
     * SPK (Surat Perintah Kerja) untuk booking ini -- 1 booking = 1 SPK
     * (booking_id unique, lihat migrasi create_spks_table).
     */
    public function spk()
    {
        return $this->hasOne(Spk::class);
    }

    /**
     * Riwayat refund booking ini -- BISA lebih dari 1 (refund parsial
     * bertahap), lihat RefundService & migrasi
     * 2026_09_09_000002_create_refunds_table.
     */
    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * Riwayat Uang Muka (DP) booking ini -- BISA lebih dari 1 (dibayar
     * bertahap), lihat DownPaymentService & migrasi
     * create_booking_down_payments_table (keputusan atasan 2026-09-19,
     * Topik 2).
     */
    public function downPayments()
    {
        return $this->hasMany(BookingDownPayment::class);
    }

    /**
     * Total DP yang sudah diterima DAN BELUM dikembalikan -- dipakai
     * tampilan (badge "DP: Rp X") & validasi ("tidak bisa refund lebih
     * dari yang belum di-refund"). BUKAN net dari transaction_amount
     * (DP itu Pendapatan Diterima Dimuka/liabilitas, terpisah total dari
     * pendapatan jasa).
     */
    public function getOutstandingDownPaymentAttribute(): float
    {
        return (float) $this->downPayments()->whereNull('refunded_at')->sum('amount');
    }

    /**
     * Jurnal Pendapatan yang otomatis dibuat/diperbarui saat
     * transaction_amount diisi/diubah — lihat BookingPostingService.
     */
    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
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

        // Rincian PPN (Topik 1, "Keputusan-PPN-DP-Produk-Stok-Ginnva.docx"
        // 2026-09-19) -- OTOMATIS dihitung ulang tiap kali transaction_amount
        // diisi/diubah (create ATAU edit), tidak pernah diinput manual staff.
        // 'saving' (bukan cuma 'creating') supaya booking lama yang
        // transaction_amount-nya diedit setelah fitur ini aktif ikut dapat
        // rincian -- konsisten dengan keputusan "booking baru saja" (booking
        // lama yang TIDAK disentuh lagi tetap dpp_amount/ppn_amount null).
        static::saving(function (Booking $booking) {
            if (! $booking->isDirty('transaction_amount')) {
                return;
            }

            $booking->applyPpnBreakdown();
        });
    }

    /**
     * Hitung dpp_amount & ppn_amount dari transaction_amount saat ini,
     * asumsi harga SUDAH inclusive PPN 11% (DPP = Total / 1.11, PPN =
     * Total - DPP). transaction_amount kosong/0 -> kedua kolom di-null-kan
     * (bukan 0) supaya "belum ada transaksi" tetap beda dari "transaksi
     * Rp 0 dgn PPN Rp 0".
     */
    public function applyPpnBreakdown(): void
    {
        $total = (float) ($this->transaction_amount ?? 0);

        if ($total <= 0) {
            $this->dpp_amount = null;
            $this->ppn_amount = null;

            return;
        }

        $dpp = round($total / (1 + self::PPN_RATE), 2);

        $this->dpp_amount = $dpp;
        $this->ppn_amount = round($total - $dpp, 2);
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
