<?php

namespace App\Filament\Pages;

use App\Models\PartnerPointTransaction;
use App\Models\PointTransaction;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * "Laporan Promo & Loyalti" — diminta 2026-09-08 setelah eksplorasi menu
 * Laporan Majoo. Voucher/Reward/Poin (Customer & Partner) datanya masih
 * hidup di Marketing/Konten, tapi semuanya cuma ledger/daftar mentah —
 * tidak ada satu pun laporan PERFORMA (voucher mana paling laku, tingkat
 * penukaran reward, total poin diterbitkan vs dipakai). Halaman ini
 * mengagregasi data yang SUDAH ADA (tidak ada tabel/kolom baru), sama
 * pola dengan report Keuangan (IncomeStatementReport dkk): custom Page
 * + form rentang tanggal + Blade view.
 *
 * SEBELUMNYA di cluster Marketing/Konten — dipindah ke PenjualanCluster
 * (permintaan susulan 2026-09-08) supaya semua "Laporan" ala Majoo
 * ngumpul di tab Penjualan bareng SalesResource.
 */
class PromoLoyaltyReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    // Grup sendiri 'Laporan Promo & Loyalti' (diubah 2026-09-09 dari
    // 'Laporan' gabungan) -- sejajar dengan grup kategori laporan lain.
    protected static ?string $navigationGroup = 'Laporan Promo & Loyalti';

    // Diganti dari "Laporan Promo & Loyalti" jadi "Laporan Promo"
    // (diminta 2026-09-09) -- sekarang ada PointReport & CouponReport
    // (extends class ini) sebagai menu terpisah "Laporan Poin"/"Laporan
    // Kupon", jadi label item INI disamakan penamaan Majoo persis.
    // Grup induknya (navigationGroup) TETAP "Laporan Promo & Loyalti".
    protected static ?string $navigationLabel = 'Laporan Promo';

    protected static ?string $title = 'Laporan Promo';

    // 200 -- band grup 'Laporan Promo & Loyalti' (lihat catatan sistem
    // band di ProductSalesReport.php, diperbaiki 2026-09-09).
    protected static ?int $navigationSort = 200;

    protected static string $view = 'filament.pages.promo-loyalty-report';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),
        ])->columns(2)->statePath('data');
    }

    /**
     * Semua angka dihitung dari transaksi/klaim yang terjadi DI DALAM
     * rentang tanggal terpilih — bukan status "sekarang" (mis. voucher
     * yang aktif hari ini tapi klaimnya bulan lalu tidak ikut terhitung
     * pada rentang bulan ini), supaya laporan per-periode benar-benar
     * mencerminkan aktivitas periode itu.
     */
    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();

        $vouchers = Voucher::query()
            ->withCount([
                'claims as claimed_in_period' => fn ($q) => $q->whereBetween('created_at', [$from, $to]),
                'claims as used_in_period' => fn ($q) => $q->where('status', 'used')->whereBetween('used_at', [$from, $to]),
            ])
            ->orderByDesc('claimed_in_period')
            ->get();

        $rewards = Reward::query()
            ->withCount([
                'redemptions as redeemed_in_period' => fn ($q) => $q->whereBetween('created_at', [$from, $to]),
                'redemptions as fulfilled_in_period' => fn ($q) => $q->where('status', 'fulfilled')->whereBetween('created_at', [$from, $to]),
            ])
            ->withSum(['redemptions as points_spent_in_period' => fn ($q) => $q->where('status', 'fulfilled')->whereBetween('created_at', [$from, $to])], 'points_spent')
            ->orderByDesc('redeemed_in_period')
            ->get();

        $pointsIssuedCustomer = (int) PointTransaction::where('type', 'earn')->whereBetween('created_at', [$from, $to])->sum('points');
        $pointsSpentCustomer = (int) PointTransaction::where('type', 'spend')->whereBetween('created_at', [$from, $to])->sum('points');
        $pointsIssuedPartner = (int) PartnerPointTransaction::where('type', 'earn')->whereBetween('created_at', [$from, $to])->sum('points');
        $pointsSpentPartner = (int) PartnerPointTransaction::where('type', 'spend')->whereBetween('created_at', [$from, $to])->sum('points');

        // "Laporan Promo" Majoo (diminta 2026-09-09) -- stat card & detail
        // per transaksi, dari VoucherClaim yang BENAR-BENAR dipakai di
        // sebuah booking (bukan cuma diklaim). used_at dipakai (bukan
        // created_at) karena itu tanggal voucher SUNGGUHAN dipakai
        // transaksi -- SAMA logika dengan SalesSummaryReport supaya
        // "Nilai Promo" di sini konsisten dengan "Promo Voucher" di
        // Ringkasan Penjualan.
        $usedClaims = VoucherClaim::query()
            ->where('status', 'used')
            ->whereNotNull('booking_id')
            ->whereBetween('used_at', [$from, $to])
            ->with(['voucher:id,name,discount_amount', 'booking:id,booking_number,store_id,transaction_amount', 'booking.store:id,name'])
            ->orderByDesc('used_at')
            ->get();

        $promoValue = (float) $usedClaims->sum(fn (VoucherClaim $c) => (float) ($c->voucher->discount_amount ?? 0));
        $promoSalesTotal = (float) $usedClaims->sum(fn (VoucherClaim $c) => (float) ($c->booking->transaction_amount ?? 0));

        return [
            'vouchers' => $vouchers,
            'rewards' => $rewards,
            'points' => [
                'issued_customer' => $pointsIssuedCustomer,
                'spent_customer' => $pointsSpentCustomer,
                'issued_partner' => $pointsIssuedPartner,
                'spent_partner' => $pointsSpentPartner,
            ],
            'totalRedemptions' => RewardRedemption::whereBetween('created_at', [$from, $to])->count(),
            'usedClaims' => $usedClaims,
            'promoTransactionCount' => $usedClaims->count(),
            'promoValue' => $promoValue,
            'promoSalesTotal' => $promoSalesTotal,
        ];
    }
}
