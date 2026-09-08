<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use App\Models\Store;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * "Laporan Jasa" — diminta 2026-09-08, analog "Laporan Jasa" Majoo
 * (rekap servis terjual per jenis/periode). Ginnva bisnis JASA instalasi
 * (bukan jual barang eceran), jadi "jasa" di sini = booking yang sudah
 * tercatat sebagai pendapatan (sama filter dengan SalesResource/
 * BookingRevenueStatsWidget — whereHas('journalEntry'), supaya SELALU
 * konsisten dengan Jurnal Umum). Beda dari SalesResource (daftar
 * transaksi satu-satu): ini AGREGAT per jenis servis & per toko, sama
 * pola report Keuangan (custom Page + form rentang tanggal + Blade
 * view). Split 50/50 untuk booking 2 produk sekaligus SAMA PERSIS
 * logika BookingPostingService/BookingRevenueByCategoryChart.
 */
class LayananReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    // Dikelompokkan di bawah heading sidebar 'Laporan' (diminta
    // 2026-09-08) -- Dashboard Penjualan (SalesDashboard) SENGAJA tidak
    // ikut, tetap berdiri sendiri di atas grup ini.
    protected static ?string $navigationGroup = 'Laporan';

    protected static ?string $navigationLabel = 'Laporan Jasa';

    protected static ?string $title = 'Laporan Jasa';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.layanan-report';

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
            'store_id' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari')->native(false)->required(),
            DatePicker::make('to')->label('Sampai')->native(false)->required(),
            Select::make('store_id')
                ->label('Toko')
                ->options(fn () => Store::where('is_active', true)->pluck('name', 'id'))
                ->searchable()
                ->placeholder('Semua Toko')
                ->visible(fn () => auth()->user()?->isFullAccess() ?? false),
        ])->columns(3)->statePath('data');
    }

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();
        $user = auth()->user();
        $isSuperAdmin = $user?->isFullAccess() ?? false;

        $query = Booking::query()
            ->with('store')
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]))
            ->where('transaction_amount', '>', 0);

        if (! $isSuperAdmin) {
            $query->where(function ($q) use ($user) {
                $q->where('store_id', $user->store_id)
                    ->orWhereNull('store_id');
            });
        } elseif (! empty($this->data['store_id'])) {
            $query->where('store_id', $this->data['store_id']);
        }

        $bookings = $query->get(['id', 'store_id', 'transaction_amount', 'product_kaca_film', 'product_ppf']);

        $byType = [
            'kaca_film' => ['count' => 0, 'revenue' => 0.0],
            'ppf' => ['count' => 0, 'revenue' => 0.0],
        ];
        $byStore = [];
        $totalRevenue = 0.0;

        foreach ($bookings as $booking) {
            $amount = (float) $booking->transaction_amount;
            $totalRevenue += $amount;
            $bothProducts = $booking->product_ppf && $booking->product_kaca_film;

            if ($bothProducts) {
                $byType['kaca_film']['count']++;
                $byType['kaca_film']['revenue'] += $amount / 2;
                $byType['ppf']['count']++;
                $byType['ppf']['revenue'] += $amount / 2;
            } elseif ($booking->product_ppf) {
                $byType['ppf']['count']++;
                $byType['ppf']['revenue'] += $amount;
            } elseif ($booking->product_kaca_film) {
                $byType['kaca_film']['count']++;
                $byType['kaca_film']['revenue'] += $amount;
            }

            $storeName = $booking->store?->name ?? 'Tanpa Toko';
            $byStore[$storeName] ??= ['count' => 0, 'revenue' => 0.0];
            $byStore[$storeName]['count']++;
            $byStore[$storeName]['revenue'] += $amount;
        }

        uasort($byStore, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        return [
            'totalCount' => $bookings->count(),
            'totalRevenue' => $totalRevenue,
            'avgRevenue' => $bookings->count() > 0 ? $totalRevenue / $bookings->count() : 0,
            'byType' => $byType,
            'byStore' => $byStore,
        ];
    }
}
