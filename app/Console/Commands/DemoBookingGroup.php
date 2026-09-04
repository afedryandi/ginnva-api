<?php

namespace App\Console\Commands;

use App\Models\BlockedDate;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\StoreReview;
use App\Models\Technician;
use App\Models\Warranty;
use App\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Demo/testing manual untuk seluruh grup menu "Booking" (Booking
 * Instalasi, Teknisi, Tanggal Tidak Tersedia, Garansi, Review Toko) —
 * Quotation (Lead) sudah punya command sendiri, lihat `quotations:demo`.
 *
 * Dirancang SENGAJA tidak memicu efek samping nyata (email/push
 * notification/poin) walau lewat Eloquent create() biasa:
 * - Warranty dibuat dengan warranty_code & roll_number* awalan "DEMO-"
 *   yang TIDAK PERNAH match kode gulungan asli manapun — WarrantyObserver
 *   aman no-op untuk kode yang tidak ditemukan (lihat incrementScrollCodeUsage()),
 *   dan customer_id SENGAJA dibiarkan null (guest) supaya tidak ada
 *   push/email nyasar ke akun customer sungguhan.
 * - Booking/Warranty dibuat lewat create() langsung (bukan ->update()
 *   setelahnya) — logika notifikasi WarrantyObserver/BookingObserver ada
 *   di method updated(), bukan created(), jadi tidak ikut terpicu.
 * - Quotation TIDAK dibuat di sini — pakai `quotations:demo` terpisah
 *   (source='staff' di sana supaya tidak mengirim notifikasi lead baru).
 *
 * Semua baris demo ditandai lewat marker yang gampang dibersihkan lagi
 * (--cleanup): booking_number/warranty_code berawalan "DEMO-", technician
 * name berawalan "Demo Teknisi", blocked_date reason berawalan "Demo -".
 * TIDAK menyentuh data booking/warranty/teknisi/review asli sama sekali.
 */
class DemoBookingGroup extends Command
{
    protected $signature = 'booking-group:demo
        {--count=10 : Jumlah Booking dummy yang dibuat}
        {--store_id= : Batasi ke 1 toko saja (default: tersebar ke semua toko aktif)}
        {--cleanup : Hapus semua data demo grup Booking ini, bukan generate}';

    protected $description = 'Bikin data dummy Booking, Teknisi, Tanggal Tidak Tersedia, Garansi & Review Toko untuk demo/testing UI';

    private const BOOKING_PREFIX = 'DEMO-BKG-';
    private const WARRANTY_PREFIX = 'DEMO-GNV-';
    private const TECH_PREFIX = 'Demo Teknisi ';
    private const BLOCKED_REASON_PREFIX = 'Demo - ';

    private const FIRST_NAMES = ['Budi', 'Siti', 'Andi', 'Rina', 'Dedi', 'Wulan', 'Hendra', 'Sri', 'Agus', 'Dewi', 'Rizky', 'Putri', 'Bayu', 'Ayu', 'Fajar'];
    private const LAST_NAMES = ['Santoso', 'Wijaya', 'Kurniawan', 'Saputra', 'Pratama', 'Halim', 'Setiawan', 'Gunawan', 'Hartono', 'Susanto'];
    private const CAR_TYPES = ['Toyota Avanza', 'Honda Brio', 'Toyota Fortuner', 'Mitsubishi Xpander', 'Honda CR-V', 'Daihatsu Xenia', 'Toyota Innova', 'Suzuki Ertiga'];

    private const REVIEW_COMMENTS = [
        'positive' => ['Hasilnya rapi banget, puas!', 'Pelayanan ramah, prosesnya cepat.', 'Worth it, sesuai harga.', null],
        'neutral' => ['Lumayan, sesuai ekspektasi saja.', 'Standar, tidak ada yang spesial.', null],
        'negative' => ['Prosesnya lama dari perkiraan.', 'Ada sedikit gelembung di beberapa bagian.', 'Kurang rapi di bagian pojok kaca.'],
    ];

    public function handle(): int
    {
        if ($this->option('cleanup')) {
            return $this->cleanup();
        }

        $count = max(1, (int) $this->option('count'));

        $stores = $this->option('store_id')
            ? Store::where('id', $this->option('store_id'))->get()
            : Store::where('is_active', true)->get();

        if ($stores->isEmpty()) {
            $this->error('Tidak ada toko aktif ditemukan. Isi --store_id=<id> atau pastikan minimal 1 toko aktif.');
            return self::FAILURE;
        }

        $customers = Customer::all();
        if ($customers->isEmpty()) {
            $this->error('Tabel customers masih kosong — Booking butuh customer_id yang valid. Perlu minimal 1 akun customer terdaftar dulu.');
            return self::FAILURE;
        }

        $this->createTechnicians($stores);
        $this->createBlockedDates($stores);
        $bookings = $this->createBookings($stores, $customers, $count);
        $this->createWarranties($bookings);
        $this->createStoreReviews($bookings);

        $this->newLine();
        $this->info("Selesai: {$count} booking + teknisi + tanggal blokir + garansi + review toko dummy berhasil dibuat.");
        $this->info('Bisa dilihat di Filament: menu grup "Booking".');
        $this->info('Kalau sudah selesai lihat-lihat, bersihkan datanya dengan: php artisan booking-group:demo --cleanup');
        $this->comment('Catatan: Quotation (Lead) tidak dibuat di sini — pakai "php artisan quotations:demo" terpisah.');

        return self::SUCCESS;
    }

    private function randomName(): string
    {
        return self::FIRST_NAMES[array_rand(self::FIRST_NAMES)] . ' ' . self::LAST_NAMES[array_rand(self::LAST_NAMES)];
    }

    private function randomPlate(): string
    {
        return chr(rand(65, 90)) . ' ' . rand(1000, 9999) . ' ' . chr(rand(65, 90)) . chr(rand(65, 90)) . chr(rand(65, 90));
    }

    private function createTechnicians($stores): void
    {
        $n = 0;
        foreach ($stores as $store) {
            foreach (range(1, 2) as $i) {
                Technician::create([
                    'store_id' => $store->id,
                    'name' => self::TECH_PREFIX . $this->randomName(),
                    'phone' => '08' . rand(11, 99) . rand(1000000, 9999999),
                    'level' => ['intermediate', 'advanced', 'mentor'][array_rand(['intermediate', 'advanced', 'mentor'])],
                    'status' => 'active',
                    'notes' => 'Data dummy untuk demo/testing.',
                ]);
                $n++;
            }
        }
        $this->info("{$n} teknisi dummy dibuat.");
    }

    private function createBlockedDates($stores): void
    {
        $reasons = ['Libur Nasional (contoh)', 'Cuti Bersama (contoh)', 'Renovasi Toko (contoh)'];
        $n = 0;
        foreach ($stores as $store) {
            foreach ([now()->addDays(rand(10, 20)), now()->addDays(rand(35, 50))] as $date) {
                BlockedDate::updateOrCreate(
                    ['store_id' => $store->id, 'date' => $date->toDateString()],
                    ['reason' => self::BLOCKED_REASON_PREFIX . $reasons[array_rand($reasons)]]
                );
                $n++;
            }
        }
        $this->info("{$n} tanggal blokir dummy dibuat.");
    }

    private function createBookings($stores, $customers, int $count): array
    {
        $statusPool = ['pending', 'pending', 'confirmed', 'confirmed', 'confirmed', 'completed', 'completed', 'completed', 'cancelled'];
        $rows = [];
        $bookings = [];

        for ($i = 0; $i < $count; $i++) {
            $store = $stores->random();
            $customer = $customers->random();
            $status = $statusPool[array_rand($statusPool)];

            $wantsPpf = (bool) rand(0, 1);
            $wantsKacaFilm = ! $wantsPpf || (bool) rand(0, 1);
            $serviceType = $wantsPpf && $wantsKacaFilm ? 'PPF + Kaca Film' : ($wantsPpf ? 'PPF' : 'Kaca Film');

            $currentStage = match ($status) {
                'completed' => 'completed',
                'confirmed' => $wantsPpf ? 'ppf_washing' : 'kf_cleaning',
                default => null,
            };

            $daysOffset = $status === 'completed' ? -rand(1, 25) : rand(0, 20);
            $preferredDate = Carbon::now()->addDays($daysOffset);

            // withoutEvents() — BookingObserver::created() mengirim email +
            // push notification SUNGGUHAN ke staff toko untuk booking APA
            // PUN (tidak ada pengecualian source, beda dari Quotation).
            // Tanpa ini, tiap booking dummy di sini akan nge-spam inbox/HP
            // staff asli. Konsekuensinya: booted()'s creating hook (auto-
            // isi duration_days) IKUT tidak jalan, jadi duration_days
            // diisi manual di sini juga.
            $booking = Booking::withoutEvents(function () use ($customer, $store, $serviceType, $wantsKacaFilm, $wantsPpf, $preferredDate, $status, $currentStage) {
                $b = new Booking([
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->name,
                    'phone_number' => $customer->phone_number,
                    'store_id' => $store->id,
                    'service_type' => $serviceType,
                    'product_kaca_film' => $wantsKacaFilm,
                    'product_ppf' => $wantsPpf,
                    'preferred_date' => $preferredDate->toDateString(),
                    'preferred_time' => ['09:00 - 11:00', '11:00 - 13:00', '13:00 - 15:00'][array_rand(['09:00 - 11:00', '11:00 - 13:00', '13:00 - 15:00'])],
                    'duration_days' => $wantsPpf ? Booking::DEFAULT_DURATION_DAYS_PPF : Booking::DEFAULT_DURATION_DAYS_DEFAULT,
                    'notes' => 'Data dummy untuk demo/testing.',
                    'source' => 'staff',
                    'status' => $status,
                    'current_stage' => $currentStage,
                ]);
                // booking_number di-set eksplisit — booted() (yang biasanya
                // generate otomatis kalau kosong) tidak jalan sama sekali di
                // dalam withoutEvents(). Awalan "DEMO-" (bukan format asli
                // "BKG-") sekaligus jadi marker untuk --cleanup.
                $b->booking_number = self::BOOKING_PREFIX . now()->format('Ym') . '-' . Str::upper(Str::random(4));
                $b->save();

                return $b;
            });

            $bookings[] = $booking;
            $rows[] = [$booking->booking_number, $store->name, $status, $preferredDate->toDateString()];
        }

        $this->info(count($bookings) . ' booking dummy dibuat.');
        $this->table(['No. Booking', 'Toko', 'Status', 'Tgl Preferensi'], $rows);

        return $bookings;
    }

    /**
     * @param  array<Booking>  $bookings
     */
    private function createWarranties(array $bookings): void
    {
        $completed = array_filter($bookings, fn (Booking $b) => $b->status === 'completed');
        $reviewStatusPool = ['pending_review', 'pending_review', 'approved', 'approved', 'approved', 'rejected'];
        $n = 0;

        foreach ($completed as $booking) {
            $category = $booking->product_ppf ? 'ppf' : 'window_film';
            $reviewStatus = $reviewStatusPool[array_rand($reviewStatusPool)];
            $installDate = $booking->preferred_date instanceof Carbon ? $booking->preferred_date->copy() : Carbon::parse($booking->preferred_date);

            $warranty = new Warranty([
                'customer_name' => $booking->customer_name,
                'phone_number' => $booking->phone_number,
                'car_plate' => $this->randomPlate(),
                'car_type' => self::CAR_TYPES[array_rand(self::CAR_TYPES)],
                'product_series' => $category === 'ppf' ? 'Demo PPF Series' : 'Demo Window Film Series',
                'product_category' => $category,
                'installation_date' => $installDate->toDateString(),
                'expiry_date' => $installDate->copy()->addYears(5)->toDateString(),
                'dealer_name' => $booking->store?->name ?? 'Demo Dealer',
                'store_id' => $booking->store_id,
                'customer_id' => null, // sengaja guest — hindari notifikasi/poin nyasar ke akun asli
                'status' => 'active',
                'review_status' => $reviewStatus,
                'rejection_reason' => $reviewStatus === 'rejected' ? 'Data contoh ditolak untuk keperluan demo.' : null,
            ]);

            if ($category === 'ppf') {
                $warranty->installation_position = 'full_body';
                $warranty->roll_number = 'DEMO-ROLL-' . Str::upper(Str::random(6));
            } else {
                $warranty->installation_position = 'full_body';
                $warranty->roll_number_front = 'DEMO-ROLL-' . Str::upper(Str::random(6));
                $warranty->roll_number_side_rear = 'DEMO-ROLL-' . Str::upper(Str::random(6));
            }

            // warranty_code di-set eksplisit — mencegah Warranty::booted()
            // menggenerate nomor sekuensial ASLI (GNV-PPF-xxxxx/GNV-WF-xxxxx),
            // sekaligus jadi marker untuk --cleanup.
            $warranty->warranty_code = self::WARRANTY_PREFIX . strtoupper($category) . '-' . Str::upper(Str::random(6));
            $warranty->save();
            $n++;
        }

        $this->info("{$n} garansi dummy dibuat (dari booking berstatus Selesai).");
    }

    /**
     * @param  array<Booking>  $bookings
     */
    private function createStoreReviews(array $bookings): void
    {
        $completed = array_filter($bookings, fn (Booking $b) => $b->status === 'completed');
        $sentiments = ['positive', 'positive', 'positive', 'neutral', 'negative'];
        $n = 0;

        foreach ($completed as $booking) {
            $sentiment = $sentiments[array_rand($sentiments)];
            $tagPool = array_keys(array_filter(StoreReview::TAGS, fn ($label, $key) => match ($sentiment) {
                'positive' => ! str_contains($key, 'kurang') && $key !== 'proses_lambat',
                'negative' => str_contains($key, 'kurang') || $key === 'proses_lambat',
                default => true,
            }, ARRAY_FILTER_USE_BOTH));

            $followedUp = $sentiment === 'negative' && (bool) rand(0, 1);

            // withoutEvents() — StoreReviewObserver::created() mengirim
            // Filament bell notification + push SUNGGUHAN ke staff untuk
            // review bersentimen negatif. Konsekuensinya: agregat
            // Store::reviews_count/positive_reviews_count (biasanya ikut
            // di-update observer yang sama) tidak ikut jalan — direplikasi
            // manual di bawah (aman, cuma increment counter, tidak ada
            // notifikasi) supaya Dashboard/listing Toko tetap konsisten,
            // dan dibalik lagi saat --cleanup.
            StoreReview::withoutEvents(function () use ($booking, $sentiment, $tagPool, $followedUp) {
                StoreReview::create([
                    'booking_id' => $booking->id,
                    'customer_id' => $booking->customer_id,
                    'store_id' => $booking->store_id,
                    'sentiment' => $sentiment,
                    'tags' => ! empty($tagPool) ? [$tagPool[array_rand($tagPool)]] : null,
                    'comment' => self::REVIEW_COMMENTS[$sentiment][array_rand(self::REVIEW_COMMENTS[$sentiment])] ?? null,
                    'followed_up_at' => $followedUp ? now() : null,
                    'follow_up_note' => $followedUp ? 'Sudah dihubungi untuk klarifikasi (data contoh).' : null,
                ]);
            });

            if ($booking->store_id) {
                Store::where('id', $booking->store_id)->increment('reviews_count');
                if ($sentiment === 'positive') {
                    Store::where('id', $booking->store_id)->increment('positive_reviews_count');
                }
            }
            $n++;
        }

        $this->info("{$n} review toko dummy dibuat (dari booking berstatus Selesai).");
    }

    private function cleanup(): int
    {
        $bookingIds = Booking::where('booking_number', 'like', self::BOOKING_PREFIX . '%')->pluck('id');

        // Balikkan agregat Store::reviews_count/positive_reviews_count yang
        // di-increment manual saat generate (lihat createStoreReviews())
        // SEBELUM baris review-nya dihapus — supaya angka di Dashboard/
        // listing Toko tidak tertinggal lebih tinggi dari yang seharusnya.
        $demoReviews = StoreReview::whereIn('booking_id', $bookingIds)->get(['id', 'store_id', 'sentiment']);
        foreach ($demoReviews as $review) {
            if (! $review->store_id) continue;
            Store::where('id', $review->store_id)->decrement('reviews_count');
            if ($review->sentiment === 'positive') {
                Store::where('id', $review->store_id)->decrement('positive_reviews_count');
            }
        }
        $reviewCount = $demoReviews->count();
        StoreReview::whereIn('booking_id', $bookingIds)->delete();

        $bookingCount = $bookingIds->count();
        Booking::whereIn('id', $bookingIds)->delete();

        $warrantyCount = Warranty::where('warranty_code', 'like', self::WARRANTY_PREFIX . '%')->count();
        Warranty::where('warranty_code', 'like', self::WARRANTY_PREFIX . '%')->delete();

        $techCount = Technician::where('name', 'like', self::TECH_PREFIX . '%')->count();
        Technician::where('name', 'like', self::TECH_PREFIX . '%')->delete();

        $blockedCount = BlockedDate::where('reason', 'like', self::BLOCKED_REASON_PREFIX . '%')->count();
        BlockedDate::where('reason', 'like', self::BLOCKED_REASON_PREFIX . '%')->delete();

        $this->info("Dibersihkan: {$bookingCount} booking, {$reviewCount} review toko, {$warrantyCount} garansi, {$techCount} teknisi, {$blockedCount} tanggal blokir.");

        return self::SUCCESS;
    }
}
