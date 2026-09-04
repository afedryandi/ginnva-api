<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Partner;
use App\Models\PartnerPointTransaction;
use App\Models\PointTransaction;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use App\Services\RewardRedemptionService;
use App\Services\VoucherService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demo/testing manual untuk sisa nav group "Marketing/Konten" seputar
 * poin & reward: Riwayat Poin Customer, Partner, Riwayat Poin Partner,
 * Voucher Promo, Katalog Reward, Klaim Reward.
 *
 * Prinsip sama dengan quotations:demo/booking-group:demo/marketing-group:
 * demo — pakai ulang SERVICE/LOGIC ASLI (RewardRedemptionService,
 * VoucherService, pola manual entry dari CreatePointTransaction/
 * CreatePartnerPointTransaction) supaya ledger poin & stok tetap
 * konsisten seperti alur produksi sungguhan, bukan insert mentah yang
 * bisa bikin saldo/stok tidak sinkron.
 *
 * reward.image NULLABLE di DB (beda dari carousels/featured_products/
 * case_studies/customer_gallery_photos yang NOT NULL) — kalau ada
 * reward asli dengan gambar, path-nya dipakai ulang; kalau tidak ada,
 * reward demo tetap dibuat TANPA gambar (bukan dilewati).
 *
 * Tidak ada Observer terdaftar untuk PointTransaction/PartnerPointTransaction/
 * Voucher/VoucherClaim/Reward/Partner (lihat AppServiceProvider::boot()) —
 * RewardRedemptionObserver cuma bereaksi ke PERUBAHAN status jadi/dari
 * 'cancelled' (updated(), bukan created()), jadi baris baru dari
 * RewardRedemptionService::redeem() TIDAK memicu apa pun; withoutEvents()
 * TIDAK diperlukan di sini sama sekali.
 *
 * Semua baris demo ditandai lewat marker "Demo - " (nama/deskripsi) dan
 * email customer/partner berakhiran '@demo.ginnva.test', supaya gampang
 * dibersihkan lagi lewat --cleanup.
 */
class DemoRewardsGroup extends Command
{
    protected $signature = 'rewards-group:demo
        {--cleanup : Hapus semua data demo grup ini, bukan generate}';

    protected $description = 'Bikin data dummy untuk grup Marketing/Konten seputar Poin, Partner, Voucher & Reward (demo/testing UI)';

    private const MARK_PREFIX = 'Demo - ';
    private const DEMO_EMAIL_SUFFIX = '@demo.ginnva.test';

    private const FIRST_NAMES = ['Budi', 'Siti', 'Andi', 'Rina', 'Dedi', 'Wulan', 'Hendra', 'Sri', 'Agus', 'Dewi'];
    private const LAST_NAMES = ['Santoso', 'Wijaya', 'Kurniawan', 'Saputra', 'Pratama', 'Halim', 'Setiawan', 'Gunawan'];

    public function handle(): int
    {
        if ($this->option('cleanup')) {
            return $this->cleanup();
        }

        $customers = $this->createCustomers();
        $partners = $this->createPartners();

        $this->createManualPointTransactions($customers);
        $this->createManualPartnerPointTransactions($partners);

        $vouchers = $this->createVouchers();
        $this->createVoucherClaims($vouchers, $customers);

        $rewards = $this->createRewards();
        $this->createRedemptions($rewards, $customers, $partners);

        $this->newLine();
        $this->info('Data dummy Poin/Partner/Voucher/Reward selesai dibuat. Cek Filament: Marketing/Konten.');
        $this->info('Kalau sudah selesai lihat-lihat, bersihkan datanya dengan: php artisan rewards-group:demo --cleanup');

        return self::SUCCESS;
    }

    private function randomName(): string
    {
        return self::FIRST_NAMES[array_rand(self::FIRST_NAMES)] . ' ' . self::LAST_NAMES[array_rand(self::LAST_NAMES)];
    }

    private function createCustomers(): \Illuminate\Support\Collection
    {
        $created = collect();

        for ($i = 0; $i < 4; $i++) {
            $name = $this->randomName();
            $emailLocal = str()->slug($name) . rand(10, 999);

            $created->push(Customer::create([
                'name' => $name,
                'email' => "{$emailLocal}" . self::DEMO_EMAIL_SUFFIX,
                'phone_number' => '08' . rand(11, 99) . rand(1000000, 9999999),
                'email_verified_at' => Carbon::now()->subDays(rand(1, 60)),
                'loyalty_points' => 0,
            ]));
        }

        $this->info("{$created->count()} akun customer dummy dibuat.");

        return $created;
    }

    /**
     * Partner::createAccount() bikin User login (role 'partner') + profil
     * Partner sekaligus — dipakai ulang di sini SENGAJA (bukan insert
     * manual ke tabel partners) supaya akun demo tetap konsisten dengan
     * alur produksi (password di-hash, referral_code unik, role tersinkron).
     * Password dummy tidak pernah diekspos/dipakai untuk login sungguhan.
     */
    private function createPartners(): \Illuminate\Support\Collection
    {
        $created = collect();
        $types = ['partner', 'influencer', 'komunitas'];
        $sources = ['giias', 'partner', null];

        for ($i = 0; $i < 3; $i++) {
            $name = $this->randomName();
            $emailLocal = str()->slug($name) . rand(10, 999);

            $partner = Partner::createAccount([
                'business_name' => self::MARK_PREFIX . $name . ' Autocare',
                'email' => "{$emailLocal}" . self::DEMO_EMAIL_SUFFIX,
                'password' => Str::random(24),
                'phone' => '08' . rand(11, 99) . rand(1000000, 9999999),
                'status' => 'active',
                'type' => $types[array_rand($types)],
                'source' => $sources[array_rand($sources)],
            ]);

            $created->push($partner);
        }

        $this->info("{$created->count()} partner dummy dibuat (beserta akun login-nya).");

        return $created;
    }

    /**
     * Meniru persis CreatePointTransaction::handleRecordCreation() — bikin
     * baris ledger SEKALIGUS update customers.loyalty_points dalam 1
     * transaction, supaya ledger dan saldo tetap konsisten seperti input
     * manual staff yang sungguhan (creating PointTransaction TIDAK auto
     * update saldo, lihat komentar class-level).
     */
    private function createManualPointTransactions(\Illuminate\Support\Collection $customers): void
    {
        $count = 0;

        foreach ($customers as $customer) {
            $entries = [
                ['type' => 'earn', 'points' => rand(50, 300), 'description' => self::MARK_PREFIX . 'Poin dari promo demo/testing'],
                ['type' => 'earn', 'points' => rand(20, 100), 'description' => self::MARK_PREFIX . 'Bonus referral demo/testing'],
            ];

            foreach ($entries as $entry) {
                DB::transaction(function () use ($customer, $entry) {
                    $locked = Customer::where('id', $customer->id)->lockForUpdate()->first();

                    PointTransaction::create([
                        'customer_id' => $locked->id,
                        'type' => $entry['type'],
                        'points' => $entry['points'],
                        'description' => $entry['description'],
                        'reference_type' => 'manual',
                        'reference_id' => null,
                    ]);

                    $locked->increment('loyalty_points', $entry['points']);
                });
                $count++;
            }
        }

        $this->info("{$count} riwayat poin customer dummy dibuat (tipe 'earn' manual).");
    }

    private function createManualPartnerPointTransactions(\Illuminate\Support\Collection $partners): void
    {
        $count = 0;

        foreach ($partners as $partner) {
            $entries = [
                ['type' => 'earn', 'points' => rand(100, 500), 'description' => self::MARK_PREFIX . 'Poin referral booking demo/testing'],
                ['type' => 'earn', 'points' => rand(50, 200), 'description' => self::MARK_PREFIX . 'Bonus performa demo/testing'],
            ];

            foreach ($entries as $entry) {
                DB::transaction(function () use ($partner, $entry) {
                    $locked = Partner::where('id', $partner->id)->lockForUpdate()->first();

                    PartnerPointTransaction::create([
                        'partner_id' => $locked->id,
                        'type' => $entry['type'],
                        'points' => $entry['points'],
                        'description' => $entry['description'],
                        'reference_type' => 'manual',
                        'reference_id' => null,
                    ]);

                    $locked->increment('points_balance', $entry['points']);
                });
                $count++;
            }
        }

        $this->info("{$count} riwayat poin partner dummy dibuat (tipe 'earn' manual).");
    }

    private function createVouchers(): \Illuminate\Support\Collection
    {
        $vouchers = collect([
            Voucher::create([
                'name' => self::MARK_PREFIX . 'Voucher Rp50.000 — 50 Pembeli Pertama',
                'description' => 'Voucher dummy untuk demo/testing.',
                'discount_amount' => 50000,
                'total_stock' => 50,
                'claimed_count' => 0,
                'expires_at' => Carbon::now()->addMonths(2),
                'is_active' => true,
            ]),
            Voucher::create([
                'name' => self::MARK_PREFIX . 'Voucher Rp100.000 — Tanpa Batas Waktu',
                'description' => 'Voucher dummy untuk demo/testing.',
                'discount_amount' => 100000,
                'total_stock' => 20,
                'claimed_count' => 0,
                'expires_at' => null,
                'is_active' => true,
            ]),
        ]);

        $this->info("{$vouchers->count()} kampanye voucher promo dummy dibuat.");

        return $vouchers;
    }

    /**
     * VoucherService::assignToCustomer() dipakai ulang persis — kode unik
     * di-generate di sini (marker "DEMO-"), stok/claimed_count ikut
     * ter-update otomatis lewat service, sama seperti staff input kode
     * voucher fisik sungguhan lewat ClaimsRelationManager.
     */
    private function createVoucherClaims(\Illuminate\Support\Collection $vouchers, \Illuminate\Support\Collection $customers): void
    {
        $service = new VoucherService();
        $count = 0;

        foreach ($vouchers as $voucher) {
            foreach ($customers->take(2) as $customer) {
                $code = 'DEMO-' . Str::upper(Str::random(6));

                try {
                    $service->assignToCustomer($voucher, $code, $customer->id);
                    $count++;
                } catch (\RuntimeException $e) {
                    $this->warn("Lewati klaim voucher demo: {$e->getMessage()}");
                }
            }
        }

        $this->info("{$count} klaim voucher dummy dibuat.");
    }

    private function createRewards(): \Illuminate\Support\Collection
    {
        $existingImage = Reward::whereNotNull('image')->inRandomOrder()->value('image');

        $rewards = collect([
            ['name' => 'Voucher Cuci Mobil Gratis', 'points_cost' => 100, 'stock' => 30],
            ['name' => 'Merchandise Ginnva (Tumbler)', 'points_cost' => 250, 'stock' => 15],
            ['name' => 'Diskon Rp200.000 Layanan PPF', 'points_cost' => 800, 'stock' => null],
        ]);

        $created = $rewards->map(fn (array $r) => Reward::create([
            'name' => self::MARK_PREFIX . $r['name'],
            'description' => 'Reward dummy untuk demo/testing.',
            'image' => $existingImage,
            'points_cost' => $r['points_cost'],
            'stock' => $r['stock'],
            'is_active' => true,
        ]));

        $this->info($created->count() . ' item katalog reward dummy dibuat' . ($existingImage ? ' (gambar dipakai ulang dari reward asli yang sudah ada).' : ' (tanpa gambar — belum ada reward asli dengan gambar).'));

        return $created;
    }

    /**
     * RewardRedemptionService::redeem() dipakai ulang persis — saldo poin
     * redeemer di-top-up dulu secukupnya (lewat jalur manual yang sama di
     * atas, bukan langsung update kolom) supaya redeem() lolos validasi
     * saldo cukup, baru redeem() dipanggil supaya ledger (Point/PartnerPoint
     * Transaction tipe 'spend') & stok reward ikut konsisten seperti alur
     * customer/partner menukar reward sungguhan lewat mobile app.
     */
    private function createRedemptions(\Illuminate\Support\Collection $rewards, \Illuminate\Support\Collection $customers, \Illuminate\Support\Collection $partners): void
    {
        $service = new RewardRedemptionService();
        $statuses = ['pending', 'pending', 'fulfilled', 'cancelled'];
        $count = 0;

        $redeemers = $customers->take(2)->concat($partners->take(1));

        foreach ($redeemers as $redeemer) {
            $reward = $rewards->random();
            $isPartner = $redeemer instanceof Partner;
            $balanceField = $isPartner ? 'points_balance' : 'loyalty_points';

            // Top-up saldo secukupnya supaya redeem() lolos validasi —
            // ledger top-up ini juga tercatat sebagai PointTransaction/
            // PartnerPointTransaction 'earn' manual, bukan saldo disulap
            // langsung tanpa jejak.
            DB::transaction(function () use ($redeemer, $isPartner, $balanceField, $reward) {
                $locked = $redeemer->newQuery()->where('id', $redeemer->id)->lockForUpdate()->first();
                $shortfall = max(0, $reward->points_cost - $locked->{$balanceField});

                if ($shortfall > 0) {
                    $payload = [
                        'type' => 'earn',
                        'points' => $shortfall,
                        'description' => self::MARK_PREFIX . 'Top-up saldo untuk demo klaim reward',
                        'reference_type' => 'manual',
                        'reference_id' => null,
                    ];

                    if ($isPartner) {
                        PartnerPointTransaction::create($payload + ['partner_id' => $locked->id]);
                    } else {
                        PointTransaction::create($payload + ['customer_id' => $locked->id]);
                    }

                    $locked->increment($balanceField, $shortfall);
                }
            });

            $redeemerFresh = $redeemer->newQuery()->find($redeemer->id);

            try {
                $redemption = $service->redeem($redeemerFresh, $reward);

                $status = $statuses[array_rand($statuses)];
                if ($status !== 'pending') {
                    // ->update() (bukan set langsung) SENGAJA — supaya
                    // RewardRedemptionObserver ikut bereaksi wajar kalau
                    // status 'cancelled' (refund saldo & stok otomatis),
                    // sama seperti staff ubah status lewat Filament.
                    $redemption->update(['status' => $status, 'notes' => self::MARK_PREFIX . 'Data dummy untuk demo/testing.']);
                }

                $count++;
            } catch (\RuntimeException $e) {
                $this->warn("Lewati klaim reward demo: {$e->getMessage()}");
            }
        }

        $this->info("{$count} klaim reward dummy dibuat.");
    }

    private function cleanup(): int
    {
        $total = 0;

        $demoCustomerIds = Customer::where('email', 'like', '%' . self::DEMO_EMAIL_SUFFIX)->pluck('id');
        $demoPartnerUserIds = User::where('email', 'like', '%' . self::DEMO_EMAIL_SUFFIX)->pluck('id');
        $demoPartnerIds = Partner::whereIn('user_id', $demoPartnerUserIds)->pluck('id');

        $total += RewardRedemption::where(function ($q) use ($demoCustomerIds, $demoPartnerIds) {
            $q->where(fn ($q2) => $q2->where('redeemer_type', 'customer')->whereIn('redeemer_id', $demoCustomerIds))
                ->orWhere(fn ($q2) => $q2->where('redeemer_type', 'partner')->whereIn('redeemer_id', $demoPartnerIds));
        })->delete();

        $total += PointTransaction::whereIn('customer_id', $demoCustomerIds)->delete();
        $total += PartnerPointTransaction::whereIn('partner_id', $demoPartnerIds)->delete();

        $demoVoucherIds = Voucher::where('name', 'like', self::MARK_PREFIX . '%')->pluck('id');
        $total += VoucherClaim::whereIn('voucher_id', $demoVoucherIds)->delete();
        $total += Voucher::whereIn('id', $demoVoucherIds)->delete();

        $total += Reward::where('name', 'like', self::MARK_PREFIX . '%')->delete();

        $total += Partner::whereIn('id', $demoPartnerIds)->delete();
        $total += User::whereIn('id', $demoPartnerUserIds)->delete();
        $total += Customer::whereIn('id', $demoCustomerIds)->delete();

        if ($total === 0) {
            $this->info('Tidak ada data demo untuk dibersihkan.');
            return self::SUCCESS;
        }

        $this->info("{$total} baris data dummy Poin/Partner/Voucher/Reward sudah dihapus.");

        return self::SUCCESS;
    }
}
