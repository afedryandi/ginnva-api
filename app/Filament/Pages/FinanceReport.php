<?php

namespace App\Filament\Pages;

use App\Models\FinanceTransaction;
use App\Models\Store;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * "Laporan Keuangan" — ringkasan bulanan (total Pemasukan/Pengeluaran/
 * Saldo Bersih + rincian per kategori), BUKAN resource biasa karena
 * tidak ada 1 model yang dilist — murni agregasi FinanceTransaction
 * lewat 1 bulan berjalan. SAMA POLA dengan InventoryDashboard/
 * SendNotification (Page biasa, view sendiri) — form month/store
 * SEMUANYA ->live(), jadi cukup ganti pilihan tanpa tombol "Terapkan"
 * terpisah (Livewire re-render otomatis).
 */
class FinanceReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'Laporan Keuangan';

    protected static ?string $title = 'Laporan Keuangan';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.finance-report';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public function mount(): void
    {
        $isFullAccess = auth()->user()?->isFullAccess() ?? false;

        $this->form->fill([
            'month' => now()->startOfMonth()->toDateString(),
            'store_id' => $isFullAccess ? null : auth()->user()?->store_id,
        ]);
    }

    public function form(Form $form): Form
    {
        $isFullAccess = auth()->user()?->isFullAccess() ?? false;

        return $form
            ->schema([
                DatePicker::make('month')
                    ->label('Bulan')
                    ->native(false)
                    ->displayFormat('F Y')
                    ->closeOnDateSelection()
                    ->required()
                    ->live(),

                Select::make('store_id')
                    ->label('Toko')
                    ->options(fn () => Store::pluck('name', 'id'))
                    ->placeholder('Semua Toko')
                    ->searchable()
                    ->visible($isFullAccess)
                    ->live(),
            ])
            ->statePath('data')
            ->columns(2);
    }

    private function selectedMonth(): Carbon
    {
        $value = $this->data['month'] ?? now()->toDateString();

        return Carbon::parse($value)->startOfMonth();
    }

    /**
     * non-full-access SELALU dipaksa ke tokonya sendiri di sini — field
     * 'store_id' disembunyikan total dari form untuk mereka (bukan cuma
     * disabled), jadi $this->data['store_id'] tidak pernah terisi kalau
     * tidak dipaksa balik di sini.
     */
    private function selectedStoreId(): ?int
    {
        $user = auth()->user();
        if (! ($user?->isFullAccess() ?? false)) {
            return $user?->store_id;
        }

        return $this->data['store_id'] ?? null;
    }

    public function getTotals(): array
    {
        return FinanceTransaction::totalsForMonth($this->selectedMonth(), $this->selectedStoreId());
    }

    public function getBreakdown(): \Illuminate\Support\Collection
    {
        return FinanceTransaction::byCategoryForMonth($this->selectedMonth(), $this->selectedStoreId());
    }
}
