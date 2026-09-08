<?php

namespace App\Filament\Pages;

use App\Models\PartnerPointTransaction;
use App\Models\PointTransaction;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\Voucher;
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

    protected static ?string $navigationLabel = 'Laporan Promo & Loyalti';

    protected static ?string $title = 'Laporan Promo & Loyalti';

    protected static ?int $navigationSort = 20;

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
            DatePicker::make('from')->label('Dari')->native(false)->required(),
            DatePicker::make('to')->label('Sampai')->native(false)->required(),
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
        ];
    }
}
