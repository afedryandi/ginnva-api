<?php

namespace App\Console\Commands;

use App\Filament\Resources\QuotationResource;
use App\Models\FilmProduct;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Store;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Demo/testing manual untuk Quotation (Lead) — bikin sejumlah lead
 * dummy tersebar di beberapa toko aktif, campuran status (new/contacted/
 * closed/cancelled) dan tanggal (30 hari terakhir), supaya Dashboard
 * (kartu "Quotation Baru", chart tren) dan listing/filter Quotation
 * kelihatan terisi wajar saat demo/testing UI.
 *
 * source SENGAJA 'staff' (bukan 'customer') — lihat QuotationObserver::
 * created(), ini mencegah data demo memicu email + push notification
 * "Lead Baru" sungguhan ke staff. quotation_number pakai generator YANG
 * SAMA dengan QuotationResource (prefix "QTN-"), bukan salinan sendiri.
 *
 * TIDAK menyentuh data quotation asli sama sekali — semua baris demo
 * ditandai lewat customer_email berakhiran '@demo.ginnva.test' supaya
 * gampang dibersihkan lagi (--cleanup).
 */
class DemoQuotations extends Command
{
    protected $signature = 'quotations:demo
        {--count=15 : Jumlah lead dummy yang dibuat}
        {--store_id= : Batasi ke 1 toko saja (default: tersebar ke semua toko aktif)}
        {--cleanup : Hapus semua data demo ini, bukan generate}';

    protected $description = 'Bikin data dummy Quotation (Lead) untuk demo/testing UI Dashboard & listing';

    private const DEMO_EMAIL_SUFFIX = '@demo.ginnva.test';

    private const FIRST_NAMES = ['Budi', 'Siti', 'Andi', 'Rina', 'Dedi', 'Wulan', 'Hendra', 'Sri', 'Agus', 'Dewi', 'Rizky', 'Putri', 'Bayu', 'Ayu', 'Fajar', 'Lestari', 'Yoga', 'Indah', 'Wawan', 'Nur'];
    private const LAST_NAMES = ['Santoso', 'Wijaya', 'Kurniawan', 'Saputra', 'Pratama', 'Halim', 'Setiawan', 'Gunawan', 'Hartono', 'Susanto'];

    private const MESSAGES = [
        'Mau tanya harga PPF full body untuk mobil sedan.',
        'Ada promo kaca film bulan ini? Mobil SUV.',
        'Ingin pasang PPF depan saja dulu, budget terbatas.',
        'Mobil baru, mau full protection PPF + kaca film.',
        'Sebelumnya pernah pasang di sini, mau tanya garansi masih berlaku atau tidak.',
        'Minta rekomendasi jenis film yang paling gelap tapi masih sesuai aturan.',
        'Mau booking untuk minggu depan, ada slot kosong?',
        'Tanya-tanya dulu soal PPF, belum tahu tipe mobil cocok yang mana.',
        null,
        null,
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

        $vehicles = Vehicle::all();
        if ($vehicles->isEmpty()) {
            $this->error('Tabel vehicles masih kosong — Quotation butuh vehicle_id yang valid. Isi Master Data > Kendaraan dulu, baru jalankan perintah ini lagi.');
            return self::FAILURE;
        }

        $filmProducts = FilmProduct::where('is_active', true)->get();
        if ($filmProducts->isEmpty()) {
            $this->warn('Tidak ada Produk Film aktif — lead akan dibuat TANPA rincian item (cuma data kontak/kendaraan).');
        }

        $statusPool = [
            'new', 'new', 'new',              // lebih banyak 'new' — mencerminkan lead masuk belum sempat semua ditindak
            'contacted', 'contacted', 'contacted',
            'closed', 'closed',
            'cancelled',
        ];

        $created = [];

        for ($i = 0; $i < $count; $i++) {
            $store = $stores->random();
            $vehicle = $vehicles->random();
            $status = $statusPool[array_rand($statusPool)];
            $daysAgo = rand(0, 29);
            $createdAt = Carbon::now()->subDays($daysAgo)->setTime(rand(8, 20), rand(0, 59));
            $contactedAt = $status !== 'new'
                ? $createdAt->copy()->addHours(rand(1, 48))
                : null;

            $name = self::FIRST_NAMES[array_rand(self::FIRST_NAMES)] . ' ' . self::LAST_NAMES[array_rand(self::LAST_NAMES)];
            $emailLocal = str()->slug($name) . rand(10, 999);

            $quotation = new Quotation([
                'quotation_number' => QuotationResource::generateQuotationNumber(),
                'vehicle_id' => $vehicle->id,
                'customer_name' => $name,
                'customer_phone' => '08' . rand(11, 99) . rand(1000000, 9999999),
                'customer_email' => "{$emailLocal}" . self::DEMO_EMAIL_SUFFIX,
                'license_plate' => chr(rand(65, 90)) . ' ' . rand(1000, 9999) . ' ' . chr(rand(65, 90)) . chr(rand(65, 90)) . chr(rand(65, 90)),
                'store_id' => $store->id,
                'status' => $status,
                // 'staff' SENGAJA — cegah email + push notification "Lead
                // Baru" sungguhan terkirim ke staff toko (lihat QuotationObserver).
                'source' => 'staff',
                'message' => self::MESSAGES[array_rand(self::MESSAGES)],
            ]);
            $quotation->contacted_at = $contactedAt;
            $quotation->created_at = $createdAt;
            $quotation->updated_at = $contactedAt ?? $createdAt;
            $quotation->save();

            if ($filmProducts->isNotEmpty()) {
                $itemCount = rand(1, 2);
                $picked = $filmProducts->random(min($itemCount, $filmProducts->count()));
                foreach ((is_iterable($picked) ? $picked : [$picked]) as $product) {
                    QuotationItem::create([
                        'quotation_id' => $quotation->id,
                        'film_product_id' => $product->id,
                        'quantity' => 1,
                        'notes' => null,
                    ]);
                }
            }

            $created[] = [$quotation->quotation_number, $store->name, $status, $createdAt->toDateString()];
        }

        $this->info("{$count} lead dummy berhasil dibuat, tersebar di " . $stores->count() . ' toko.');
        $this->table(['No. Quotation', 'Toko', 'Status', 'Tanggal'], $created);
        $this->newLine();
        $this->info('Bisa dilihat di Filament: Booking > Quotation (Lead), atau di Dashboard (kartu & chart tren).');
        $this->info('Kalau sudah selesai lihat-lihat, bersihkan datanya dengan: php artisan quotations:demo --cleanup');

        return self::SUCCESS;
    }

    private function cleanup(): int
    {
        $ids = Quotation::where('customer_email', 'like', '%' . self::DEMO_EMAIL_SUFFIX)->pluck('id');

        if ($ids->isEmpty()) {
            $this->info('Tidak ada data demo untuk dibersihkan.');
            return self::SUCCESS;
        }

        QuotationItem::whereIn('quotation_id', $ids)->delete();
        Quotation::whereIn('id', $ids)->delete();

        $this->info("{$ids->count()} lead dummy (beserta item-nya) sudah dihapus.");
        return self::SUCCESS;
    }
}
