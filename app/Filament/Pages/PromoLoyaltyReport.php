<?php

namespace App\Filament\Pages;

use App\Exports\PromoLoyaltyReportExport;
use App\Models\Booking;
use App\Models\PartnerPointTransaction;
use App\Models\PointTransaction;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\Store;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Facades\Excel;

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

    // #[Url] (audit 2026-09-11, temuan D) — pola sama laporan Penjualan
    // lain. Berlaku juga untuk CouponReport (extends penuh).
    #[Url(as: 'from')]
    public ?string $from = null;

    #[Url(as: 'to')]
    public ?string $to = null;

    #[Url(as: 'cabang')]
    public ?int $storeId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    public function mount(): void
    {
        $this->from = $this->queryDateOrDefault($this->from, now()->startOfMonth());
        $this->to = $this->queryDateOrDefault($this->to, now()->endOfMonth());

        // "Sampai" < "Dari" via URL diutak-atik manual (audit Laporan Promo 2026-09-29):
        // dikoreksi diam-diam di sini, sama pola dengan laporan Penjualan lain.
        if (Carbon::parse($this->to)->lt(Carbon::parse($this->from))) {
            $this->to = $this->from;
        }

        // Filter cabang (audit 2026-09-29, sejajar laporan Penjualan lain) -- staff toko TIDAK
        // PERNAH boleh pilih cabang lain, URL yang tidak sah diabaikan.
        if (! (auth()->user()?->isFullAccess() ?? false)) {
            $this->storeId = null;
        }

        $this->form->fill([
            'from' => $this->from,
            'to' => $this->to,
            'store_id' => $this->storeId,
        ]);
    }

    private function queryDateOrDefault(mixed $value, Carbon $default): string
    {
        if (! is_string($value) || $value === '') {
            return $default->toDateString();
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return $default->toDateString();
        }
    }

    public function updatedData(mixed $value, string $key): void
    {
        match ($key) {
            'from' => $this->from = $value,
            'to' => $this->to = $value,
            'store_id' => $this->storeId = $value ? (int) $value : null,
            default => null,
        };

        // "Sampai" sebelum "Dari" (audit Laporan Promo 2026-09-29): sebelumnya diam-diam
        // menghasilkan tabel kosong tanpa penjelasan -- dikoreksi + diberi tahu, sama pola dengan
        // laporan Penjualan lain. TIDAK pakai minDate() reaktif di form (pernah membuat panel
        // filter gagal render di Detail Penjualan) -- validasi murni lewat hook Livewire ini.
        if (in_array($key, ['from', 'to'], true) && $this->from && $this->to && Carbon::parse($this->to)->lt(Carbon::parse($this->from))) {
            $this->to = $this->from;
            $this->data['to'] = $this->from;

            Notification::make()
                ->title('Tanggal "Sampai" tidak boleh sebelum "Dari"')
                ->body('Diset sama dengan tanggal "Dari".')
                ->warning()
                ->send();
        }
    }

    public function form(Form $form): Form
    {
        $isFullAccess = auth()->user()?->isFullAccess() ?? false;

        return $form->schema([
            // Periode Cepat (audit 2026-09-29, sejajar laporan Penjualan lain). Berlaku juga untuk
            // CouponReport (extends penuh).
            Select::make('preset')
                ->label('Periode Cepat')
                ->options([
                    'this_month' => 'Bulan ini',
                    'last_month' => 'Bulan lalu',
                    'this_quarter' => 'Kuartal ini',
                    'ytd' => 'Tahun ini (s.d. hari ini)',
                    'last_year' => 'Tahun lalu',
                ])
                ->placeholder('Pilih untuk mengisi tanggal otomatis')
                ->live()
                ->afterStateUpdated(function (?string $state, \Filament\Forms\Set $set) {
                    $range = match ($state) {
                        'this_month' => [now()->startOfMonth(), now()->endOfMonth()],
                        'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
                        'this_quarter' => [now()->startOfQuarter(), now()->endOfQuarter()],
                        'ytd' => [now()->startOfYear(), now()],
                        'last_year' => [now()->subYear()->startOfYear(), now()->subYear()->endOfYear()],
                        default => null,
                    };

                    if ($range) {
                        $set('from', $range[0]->toDateString());
                        $set('to', $range[1]->toDateString());
                        $this->from = $range[0]->toDateString();
                        $this->to = $range[1]->toDateString();
                    }
                }),

            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),

            // Filter cabang (audit 2026-09-29) -- cuma untuk full-access, sama pola dengan
            // laporan Penjualan lain. Poin Customer/Partner & katalog Voucher/Reward SENGAJA
            // TETAP company-wide (lihat catatan getResult()), filter ini cuma mempengaruhi bagian
            // transaksi yang tertaut ke toko.
            Select::make('store_id')
                ->label('Cabang')
                ->placeholder('Semua cabang')
                ->options(fn () => Store::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->visible($isFullAccess)
                ->live(),
        ])->columns($isFullAccess ? 4 : 3)->statePath('data');
    }

    /** Link drill-down ke halaman detail booking. */
    public function bookingUrl(int $bookingId): string
    {
        return \App\Filament\Resources\BookingResource::getUrl('view', ['record' => $bookingId]);
    }

    /**
     * Toko yang BENAR-BENAR berlaku: full-access memilih (null = semua cabang), staf toko dikunci ke tokonya, dan
     * staf tanpa toko dikunci ke -1 (tidak cocok toko mana pun) -- bukan null yang berarti semua cabang.
     */
    private function effectiveStoreId(): ?int
    {
        $user = auth()->user();

        if ($user?->isFullAccess() ?? false) {
            $chosen = $this->data['store_id'] ?? null;

            return $chosen ? (int) $chosen : null;
        }

        return $user?->store_id ?? -1;
    }

    /** Log ekspor (audit Laporan Promo 2026-09-29), konsisten dengan laporan lain. */
    private function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => Str::slug(static::$navigationLabel ?? 'laporan-promo'), 'format' => $format, 'from' => $this->data['from'] ?? null, 'to' => $this->data['to'] ?? null, 'store_id' => $this->effectiveStoreId()])
                ->log('Ekspor ' . (static::$navigationLabel ?? 'Laporan Promo') . ' (' . $format . ')');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * "Ekspor Laporan" (audit 2026-09-11, temuan B) — filename & judul
     * ikut halaman aktif (static::$navigationLabel), sama pola dengan
     * LayananReport/JenisOrderReport supaya export dari "Laporan Kupon"
     * tidak keliru bertuliskan "Laporan Promo".
     */
    protected function getHeaderActions(): array
    {
        $slug = Str::slug(static::$navigationLabel ?? 'laporan-promo');

        return [
            Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function () use ($slug) {
                    $this->logExport('xlsx');

                    return Excel::download(
                        new PromoLoyaltyReportExport($this->getResult(), static::$navigationLabel ?? 'Laporan Promo'),
                        $slug . '-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () use ($slug) {
                    $this->logExport('pdf');

                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.promo_loyalty_report', [
                        'result' => $result,
                        'title' => static::$navigationLabel ?? 'Laporan Promo',
                    ])->setPaper('a4', 'portrait');
                    $filename = $slug . '-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
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
        // startOfDay(): nilai DatePicker bisa membawa jam; tanpa ini klaim/transaksi sebelum jam itu di hari pertama
        // tidak terhitung.
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth())->startOfDay();
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
        //
        // BUG DIPERBAIKI 2026-09-11 (ditemukan saat audit): bagian
        // TRANSAKSI (klaim voucher terpakai, tertaut ke booking & toko)
        // SEBELUMNYA SAMA SEKALI TIDAK ADA scoping toko — manajer toko
        // manapun lihat semua transaksi promo company-wide. Poin
        // Customer/Partner (loyalti lintas-toko) & katalog Voucher/
        // Reward (program perusahaan) SENGAJA TETAP company-wide —
        // tidak terikat 1 cabang.
        $storeId = $this->effectiveStoreId();

        $usedClaims = VoucherClaim::query()
            ->where('status', 'used')
            ->whereNotNull('booking_id')
            ->whereBetween('used_at', [$from, $to])
            ->when($storeId, fn ($q) => $q->whereHas('booking', fn ($q2) => $q2->where('store_id', $storeId)))
            ->with(['voucher:id,name,discount_amount', 'booking:id,booking_number,store_id,transaction_amount,voucher_discount', 'booking.store:id,name'])
            ->orderByDesc('used_at')
            ->get();

        $promoValue = (float) $usedClaims->sum(fn (VoucherClaim $c) => $c->appliedDiscount());
        $promoSalesTotal = (float) $usedClaims->sum(fn (VoucherClaim $c) => (float) ($c->booking->transaction_amount ?? 0));

        // "Promo Total Pembelian" (SpendPromo) — diminta 2026-09-14,
        // menyusul catatan pending audit "Promo Total Pembelian":
        // sebelumnya BELUM diintegrasikan ke laporan ini sama sekali.
        // Store-scoping sama pola dengan usedClaims di atas — transaksi
        // (booking yang benar-benar pakai promo ini) di-scope toko,
        // tapi katalog SpendPromo sendiri (aturan promo) tetap
        // company-wide.
        $spendPromoBookings = Booking::query()
            ->whereNotNull('spend_promo_id')
            ->whereBetween('created_at', [$from, $to])
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->with(['spendPromo:id,name', 'store:id,name'])
            ->orderByDesc('created_at')
            ->get(['id', 'booking_number', 'store_id', 'spend_promo_id', 'spend_promo_discount', 'transaction_amount', 'created_at']);

        $spendPromoDiscountTotal = (float) $spendPromoBookings->sum('spend_promo_discount');

        return [
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
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
            'spendPromoBookings' => $spendPromoBookings,
            'spendPromoTransactionCount' => $spendPromoBookings->count(),
            'spendPromoDiscountTotal' => $spendPromoDiscountTotal,
        ];
    }
}
