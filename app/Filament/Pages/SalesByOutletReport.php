<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use App\Models\Store;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * "Penjualan Outlet" — diminta 2026-09-09, analog "Penjualan Outlet"
 * Majoo: rekap pendapatan per cabang/toko dalam 1 rentang tanggal.
 * Data langsung dari Booking.store_id (sudah ada, tidak ada kolom baru).
 *
 * Sumber & logika pendapatan SAMA PERSIS dengan laporan Penjualan lain
 * (whereHas('journalEntry'), transaction_amount > 0, amount_received
 * NULL = lunas penuh) — satu sumber kebenaran.
 *
 * Staff store-scoped (bukan isFullAccess) TETAP bisa akses halaman ini
 * tapi cuma lihat toko sendiri (1 baris) — sama pola scoping yang
 * dipakai SalesResource/LayananReport, BUKAN dibatasi ke isFullAccess
 * saja, supaya store manager tetap bisa lihat performa tokonya sendiri.
 */
class SalesByOutletReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?string $navigationGroup = 'Laporan Penjualan';

    protected static ?string $navigationLabel = 'Penjualan Outlet';

    protected static ?string $title = 'Penjualan Outlet';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.sales-by-outlet-report';

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

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();
        $user = auth()->user();
        $isFullAccess = $user?->isFullAccess() ?? false;

        $stores = Store::query()
            ->when(! $isFullAccess, fn ($q) => $q->where('id', $user?->store_id))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function (Store $store) use ($from, $to) {
                $bookings = Booking::query()
                    ->where('store_id', $store->id)
                    ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]))
                    ->where('transaction_amount', '>', 0)
                    ->get(['transaction_amount', 'amount_received']);

                $revenue = (float) $bookings->sum('transaction_amount');
                $received = (float) $bookings->sum(fn (Booking $b) => $b->amount_received !== null ? (float) $b->amount_received : (float) $b->transaction_amount);

                return [
                    'store' => $store,
                    'count' => $bookings->count(),
                    'revenue' => $revenue,
                    'received' => $received,
                    'outstanding' => max(0, $revenue - $received),
                    'avg' => $bookings->count() > 0 ? $revenue / $bookings->count() : 0,
                ];
            })
            ->sortByDesc('revenue')
            ->values();

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $stores,
            'totalRevenue' => $stores->sum('revenue'),
            'totalCount' => $stores->sum('count'),
        ];
    }
}
