<?php

namespace App\Console\Commands;

use App\Models\Carousel;
use App\Models\CaseStudy;
use App\Models\Customer;
use App\Models\CustomerGalleryPhoto;
use App\Models\FeaturedProduct;
use App\Models\FilmProduct;
use App\Models\JobOpening;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\News;
use App\Models\PartnershipInquiry;
use App\Models\ProductInquiry;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Demo/testing manual untuk seluruh nav group "Marketing/Konten":
 * Banner/Carousel, Seri Produk (Beranda), Berita, Galeri Pemasangan,
 * Galeri Customer, Kategori Materi, Lowongan Kerja, Materi Download,
 * Inquiry Produk, Kemitraan & Sales Referral, Akun Customer.
 *
 * PENTING soal gambar/file: carousels.image, featured_products.image,
 * case_studies.image, customer_gallery_photos.image, materials.file
 * SEMUA kolom NOT NULL di DB (lihat migrations masing-masing) dan
 * di-upload lewat Filament FileUpload — command ini TIDAK upload file
 * baru (tidak ada sumber file nyata yang aman dipakai lintas server).
 * Sebagai gantinya, path gambar/file DEMO dipakai ulang dari baris
 * ASLI yang sudah ada di tabel yang sama (kalau ada minimal 1), supaya
 * tidak ada icon gambar rusak. Kalau tabelnya masih kosong sama sekali,
 * fitur itu DILEWATI (bukan dipaksa dengan path palsu) dan command
 * kasih peringatan supaya user upload dulu minimal 1 item asli.
 *
 * Observer notifikasi: ProductInquiryObserver & PartnershipInquiryObserver
 * mengirim Filament database notification (bukan email/push) ke semua
 * staff yang punya akses menu tsb tiap kali record baru dibuat — dibungkus
 * withoutEvents() di sini supaya tidak nge-spam notification bell staff
 * asli dengan puluhan "Inquiry Baru"/"Pengajuan Kemitraan Baru" demo.
 * Resource lain di grup ini (Carousel, FeaturedProduct, News, CaseStudy,
 * CustomerGalleryPhoto, MaterialCategory, JobOpening, Material, Customer)
 * TIDAK punya Observer terdaftar (lihat AppServiceProvider::boot()) jadi
 * dibuat dengan create() biasa — termasuk Customer, supaya creating hook
 * bawaannya (auto-generate referral_code) tetap jalan normal.
 *
 * Semua baris demo ditandai lewat marker yang gampang dibersihkan lagi
 * (--cleanup): title/name berawalan "Demo -", customer email berakhiran
 * '@demo.ginnva.test', dsb — lihat masing-masing method create*().
 */
class DemoMarketingGroup extends Command
{
    protected $signature = 'marketing-group:demo
        {--count=6 : Jumlah baris dummy per fitur (sebagian fitur pakai jumlah tetap lebih kecil)}
        {--cleanup : Hapus semua data demo grup ini, bukan generate}';

    protected $description = 'Bikin data dummy untuk semua fitur nav group Marketing/Konten (demo/testing UI)';

    private const MARK_PREFIX = 'Demo - ';
    private const DEMO_EMAIL_SUFFIX = '@demo.ginnva.test';

    private const FIRST_NAMES = ['Budi', 'Siti', 'Andi', 'Rina', 'Dedi', 'Wulan', 'Hendra', 'Sri', 'Agus', 'Dewi', 'Rizky', 'Putri', 'Bayu', 'Ayu', 'Fajar', 'Lestari'];
    private const LAST_NAMES = ['Santoso', 'Wijaya', 'Kurniawan', 'Saputra', 'Pratama', 'Halim', 'Setiawan', 'Gunawan', 'Hartono', 'Susanto'];

    public function handle(): int
    {
        if ($this->option('cleanup')) {
            return $this->cleanup();
        }

        $count = max(1, (int) $this->option('count'));

        $customers = $this->createCustomers();
        $this->createCarousels($count);
        $this->createFeaturedProducts($count);
        $this->createNews($count);
        $this->createCaseStudies($count);
        $this->createCustomerGalleryPhotos($customers);
        $categories = $this->createMaterialCategories();
        $this->createMaterials($categories);
        $this->createJobOpenings();
        $this->createProductInquiries($count);
        $this->createPartnershipInquiries($count);

        $this->newLine();
        $this->info('Data dummy grup Marketing/Konten selesai dibuat. Cek Filament: Marketing/Konten.');
        $this->info('Kalau sudah selesai lihat-lihat, bersihkan datanya dengan: php artisan marketing-group:demo --cleanup');

        return self::SUCCESS;
    }

    private function createCustomers(): \Illuminate\Support\Collection
    {
        $created = collect();

        for ($i = 0; $i < 5; $i++) {
            $name = self::FIRST_NAMES[array_rand(self::FIRST_NAMES)] . ' ' . self::LAST_NAMES[array_rand(self::LAST_NAMES)];
            $emailLocal = str()->slug($name) . rand(10, 999);

            $customer = Customer::create([
                'name' => $name,
                'email' => "{$emailLocal}" . self::DEMO_EMAIL_SUFFIX,
                'phone_number' => '08' . rand(11, 99) . rand(1000000, 9999999),
                'email_verified_at' => Carbon::now()->subDays(rand(1, 60)),
                'loyalty_points' => rand(0, 500),
            ]);

            $created->push($customer);
        }

        $this->info("{$created->count()} akun customer dummy dibuat.");

        return $created;
    }

    private function createCarousels(int $count): void
    {
        $existingImage = Carousel::whereNotNull('image')->inRandomOrder()->value('image');

        if (! $existingImage) {
            $this->warn('Tabel carousels masih kosong — dilewati (butuh minimal 1 banner asli dengan gambar untuk dipakai ulang path-nya).');
            return;
        }

        $titles = ['Promo PPF Full Body', 'Diskon Kaca Film Akhir Tahun', 'Garansi 5 Tahun Semua Produk', 'Booking Online Lebih Mudah', 'Cabang Baru Sudah Buka'];

        for ($i = 0; $i < min($count, 5); $i++) {
            Carousel::create([
                'title' => self::MARK_PREFIX . $titles[$i % count($titles)],
                'subtitle' => 'Data dummy untuk demo/testing.',
                'image' => $existingImage,
                'audience' => 'both',
                'is_active' => true,
                'sort_order' => 100 + $i,
            ]);
        }

        $this->info(min($count, 5) . ' banner/carousel dummy dibuat (gambar dipakai ulang dari banner asli yang sudah ada).');
    }

    private function createFeaturedProducts(int $count): void
    {
        $existingImage = FeaturedProduct::whereNotNull('image')->inRandomOrder()->value('image');

        if (! $existingImage) {
            $this->warn('Tabel featured_products masih kosong — dilewati (butuh minimal 1 seri produk asli dengan gambar untuk dipakai ulang path-nya).');
            return;
        }

        $titles = ['Ginnva Ziwei 70', 'Ginnva Ceramic Pro', 'PPF Matte Series', 'Kaca Film Hybrid'];

        for ($i = 0; $i < min($count, 4); $i++) {
            FeaturedProduct::create([
                'title' => self::MARK_PREFIX . $titles[$i % count($titles)],
                'subtitle' => 'Data dummy untuk demo/testing.',
                'image' => $existingImage,
                'is_active' => true,
                'sort_order' => 100 + $i,
            ]);
        }

        $this->info(min($count, 4) . ' seri produk (beranda) dummy dibuat.');
    }

    private function createNews(int $count): void
    {
        $existingCover = News::whereNotNull('cover_image')->inRandomOrder()->value('cover_image');

        $titles = [
            'Tips Merawat PPF Agar Tahan Lama',
            'Perbedaan Kaca Film Ceramic dan Carbon',
            'Ginnva Buka Cabang Baru Bulan Ini',
            'Cara Klaim Garansi PPF dengan Mudah',
            'Promo Spesial Akhir Tahun 2026',
            'Kenali Jenis-Jenis Film Proteksi Mobil',
        ];

        for ($i = 0; $i < min($count, count($titles)); $i++) {
            $title = self::MARK_PREFIX . $titles[$i];

            News::create([
                'title' => $title,
                'excerpt' => 'Ringkasan berita dummy untuk demo/testing tampilan Berita.',
                'content' => 'Ini adalah isi berita dummy yang dibuat untuk keperluan demo/testing UI. Tidak ditampilkan ke customer sungguhan kecuali sengaja dipublish.',
                'cover_image' => $existingCover,
                'is_published' => $i % 3 !== 0,
                'published_at' => $i % 3 !== 0 ? Carbon::now()->subDays(rand(1, 30)) : null,
            ]);
        }

        $this->info(min($count, count($titles)) . ' berita dummy dibuat' . ($existingCover ? ' (cover dipakai ulang dari berita asli yang sudah ada).' : ' (tanpa cover — belum ada berita asli dengan cover_image).'));
    }

    private function createCaseStudies(int $count): void
    {
        $existingImage = CaseStudy::whereNotNull('image')->inRandomOrder()->value('image');

        if (! $existingImage) {
            $this->warn('Tabel case_studies masih kosong — dilewati (butuh minimal 1 galeri pemasangan asli dengan gambar untuk dipakai ulang path-nya).');
            return;
        }

        $vehicles = Vehicle::all();
        $filmProducts = FilmProduct::where('is_active', true)->get();

        if ($vehicles->isEmpty() || $filmProducts->isEmpty()) {
            $this->warn('Tabel vehicles atau film_products kosong — Galeri Pemasangan dilewati (butuh vehicle_id & film_product_id yang valid).');
            return;
        }

        for ($i = 0; $i < min($count, 5); $i++) {
            $vehicle = $vehicles->random();
            $filmProduct = $filmProducts->random();
            $vehicleName = trim("{$vehicle->brand} {$vehicle->model} {$vehicle->variant}");

            CaseStudy::create([
                'vehicle_id' => $vehicle->id,
                'film_product_id' => $filmProduct->id,
                'title' => self::MARK_PREFIX . "{$filmProduct->name} — {$vehicleName}",
                'short_title' => self::MARK_PREFIX . $vehicleName,
                'image' => $existingImage,
                'is_active' => true,
                'sort_order' => 100 + $i,
            ]);
        }

        $this->info(min($count, 5) . ' galeri pemasangan dummy dibuat (gambar dipakai ulang dari galeri asli yang sudah ada).');
    }

    private function createCustomerGalleryPhotos(\Illuminate\Support\Collection $customers): void
    {
        $existingImage = CustomerGalleryPhoto::whereNotNull('image')->inRandomOrder()->value('image');

        if (! $existingImage) {
            $this->warn('Tabel customer_gallery_photos masih kosong — dilewati (butuh minimal 1 foto galeri asli untuk dipakai ulang path-nya).');
            return;
        }

        if ($customers->isEmpty()) {
            $this->warn('Tidak ada customer dummy untuk dilampiri foto galeri.');
            return;
        }

        foreach ($customers as $i => $customer) {
            CustomerGalleryPhoto::create([
                'customer_id' => $customer->id,
                'image' => $existingImage,
                'caption' => self::MARK_PREFIX . 'Hasil pemasangan (dummy)',
                'is_featured' => $i % 2 === 0,
                'sort_order' => 100 + $i,
            ]);
        }

        $this->info($customers->count() . ' foto galeri customer dummy dibuat (gambar dipakai ulang dari foto asli yang sudah ada).');
    }

    private function createMaterialCategories(): \Illuminate\Support\Collection
    {
        $names = ['Brosur Produk (Demo)', 'Materi Training Sales (Demo)'];
        $lastSort = MaterialCategory::max('sort_order') ?? 0;
        $created = collect();

        foreach ($names as $i => $name) {
            $created->push(MaterialCategory::create([
                'name' => self::MARK_PREFIX . $name,
                'sort_order' => $lastSort + $i + 1,
            ]));
        }

        $this->info($created->count() . ' kategori materi dummy dibuat.');

        return $created;
    }

    private function createMaterials(\Illuminate\Support\Collection $categories): void
    {
        $existingFile = Material::whereNotNull('file')->inRandomOrder()->value('file');

        if (! $existingFile) {
            $this->warn('Tabel materials masih kosong — dilewati (butuh minimal 1 materi asli dengan file untuk dipakai ulang path-nya).');
            return;
        }

        if ($categories->isEmpty()) {
            return;
        }

        $names = ['Brosur PPF 2026 (Demo)', 'Brosur Kaca Film 2026 (Demo)', 'Panduan Sales Pitch (Demo)'];

        foreach ($names as $i => $name) {
            Material::create([
                'material_category_id' => $categories->random()->id,
                'name' => $name,
                'file' => $existingFile,
                'file_type' => 'application/pdf',
                'file_size' => rand(200_000, 5_000_000),
                'is_active' => true,
                'sort_order' => 100 + $i,
            ]);
        }

        $this->info(count($names) . ' materi download dummy dibuat (file dipakai ulang dari materi asli yang sudah ada).');
    }

    private function createJobOpenings(): void
    {
        $jobs = [
            ['title' => 'Sales Consultant', 'department' => 'Sales', 'type' => 'Full-time'],
            ['title' => 'Teknisi Instalasi PPF', 'department' => 'Operasional', 'type' => 'Full-time'],
            ['title' => 'Admin Toko', 'department' => 'Operasional', 'type' => 'Part-time'],
            ['title' => 'Magang Marketing', 'department' => 'Marketing', 'type' => 'Magang'],
        ];

        foreach ($jobs as $i => $job) {
            JobOpening::create([
                'title' => self::MARK_PREFIX . $job['title'],
                'department' => $job['department'],
                'location' => 'Jakarta',
                'type' => $job['type'],
                'description' => 'Deskripsi lowongan dummy untuk demo/testing tampilan Lowongan Kerja.',
                'requirements' => ['Minimal SMA/SMK sederajat (dummy)', 'Berpengalaman di bidang terkait (dummy)'],
                'is_published' => true,
                'sort_order' => 100 + $i,
            ]);
        }

        $this->info(count($jobs) . ' lowongan kerja dummy dibuat.');
    }

    private function createProductInquiries(int $count): void
    {
        $products = ['Color Change Film warna matte', 'Architectural Film untuk kaca rumah', 'PPF warna (colored PPF)', 'Kaca film ceramic paling gelap'];
        $statusPool = ['new', 'new', 'contacted', 'closed'];

        // withoutEvents() — ProductInquiryObserver::created() kirim
        // Filament database notification ke semua staff yang akses menu
        // ini tiap ada inquiry baru. Dibungkus supaya tidak nge-spam
        // notification bell staff asli dengan data dummy.
        ProductInquiry::withoutEvents(function () use ($count, $products, $statusPool) {
            for ($i = 0; $i < min($count, 4); $i++) {
                $name = self::FIRST_NAMES[array_rand(self::FIRST_NAMES)] . ' ' . self::LAST_NAMES[array_rand(self::LAST_NAMES)];

                $inquiry = new ProductInquiry([
                    'customer_name' => self::MARK_PREFIX . $name,
                    'customer_contact' => '08' . rand(11, 99) . rand(1000000, 9999999),
                    'message' => "Tanya ketersediaan: {$products[$i % count($products)]} — data dummy demo/testing.",
                    'status' => $statusPool[array_rand($statusPool)],
                ]);
                // inquiry_number di-generate manual — booted()'s creating
                // hook tidak jalan di dalam withoutEvents().
                $inquiry->inquiry_number = 'AVL-' . now()->format('Ym') . '-DEMO' . Str::upper(Str::random(2));
                $inquiry->save();
            }
        });

        $this->info(min($count, 4) . ' inquiry produk dummy dibuat.');
    }

    private function createPartnershipInquiries(int $count): void
    {
        $categories = ['sales', 'franchise'];
        $sources = ['giias', 'partner'];
        $statusPool = ['new', 'new', 'contacted', 'deal', 'rejected'];
        $cities = ['Jakarta', 'Bandung', 'Surabaya', 'Semarang', 'Medan'];

        // withoutEvents() — PartnershipInquiryObserver::created() kirim
        // Filament database notification ke semua staff yang akses menu
        // ini, sama seperti ProductInquiryObserver. Dibungkus supaya
        // tidak nge-spam notification bell staff asli.
        PartnershipInquiry::withoutEvents(function () use ($count, $categories, $sources, $statusPool, $cities) {
            for ($i = 0; $i < min($count, 4); $i++) {
                $name = self::FIRST_NAMES[array_rand(self::FIRST_NAMES)] . ' ' . self::LAST_NAMES[array_rand(self::LAST_NAMES)];

                PartnershipInquiry::create([
                    'category' => $categories[array_rand($categories)],
                    'source' => $sources[array_rand($sources)],
                    'applicant_name' => self::MARK_PREFIX . $name,
                    'phone_number' => '08' . rand(11, 99) . rand(1000000, 9999999),
                    'email' => str()->slug($name) . rand(10, 999) . self::DEMO_EMAIL_SUFFIX,
                    'city' => $cities[array_rand($cities)],
                    'car_brand' => null,
                    'dealer_name' => null,
                    'message' => 'Pengajuan kemitraan dummy untuk demo/testing.',
                    'status' => $statusPool[array_rand($statusPool)],
                ]);
            }
        });

        $this->info(min($count, 4) . ' pengajuan kemitraan/sales referral dummy dibuat.');
    }

    private function cleanup(): int
    {
        $total = 0;

        $total += Carousel::where('title', 'like', self::MARK_PREFIX . '%')->delete();
        $total += FeaturedProduct::where('title', 'like', self::MARK_PREFIX . '%')->delete();
        $total += News::where('title', 'like', self::MARK_PREFIX . '%')->delete();
        $total += CaseStudy::where('title', 'like', self::MARK_PREFIX . '%')->delete();

        $demoCustomerIds = Customer::where('email', 'like', '%' . self::DEMO_EMAIL_SUFFIX)->pluck('id');
        $total += CustomerGalleryPhoto::whereIn('customer_id', $demoCustomerIds)->delete();

        $demoCategoryIds = MaterialCategory::where('name', 'like', self::MARK_PREFIX . '%')->pluck('id');
        $total += Material::whereIn('material_category_id', $demoCategoryIds)->delete();
        $total += MaterialCategory::whereIn('id', $demoCategoryIds)->delete();

        $total += JobOpening::where('title', 'like', self::MARK_PREFIX . '%')->delete();
        $total += ProductInquiry::where('customer_name', 'like', self::MARK_PREFIX . '%')->delete();
        $total += PartnershipInquiry::where('applicant_name', 'like', self::MARK_PREFIX . '%')->delete();
        $total += Customer::whereIn('id', $demoCustomerIds)->delete();

        if ($total === 0) {
            $this->info('Tidak ada data demo untuk dibersihkan.');
            return self::SUCCESS;
        }

        $this->info("{$total} baris data dummy grup Marketing/Konten sudah dihapus.");

        return self::SUCCESS;
    }
}
