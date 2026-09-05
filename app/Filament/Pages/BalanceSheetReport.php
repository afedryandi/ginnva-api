<?php

namespace App\Filament\Pages;

use App\Models\Store;
use App\Services\FinancialStatementService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * Neraca (Balance Sheet) — Aset = Kewajiban + Modal per tanggal cutoff.
 * TERBATAS full-access, sama filosofi dengan laporan Keuangan lain yang
 * bersumber dari Jurnal Umum (Neraca Saldo, Laba Rugi, Buku Besar).
 */
class BalanceSheetReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'Neraca';

    protected static ?string $title = 'Neraca (Balance Sheet)';

    protected static ?int $navigationSort = 8;

    protected static string $view = 'filament.pages.balance-sheet-report';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'as_of' => now()->toDateString(),
            'store_id' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                DatePicker::make('as_of')
                    ->label('Per Tanggal')
                    ->native(false)
                    ->required()
                    ->live(),

                Select::make('store_id')
                    ->label('Toko')
                    ->options(fn () => Store::pluck('name', 'id'))
                    ->placeholder('Semua Toko')
                    ->searchable()
                    ->live(),
            ])
            ->statePath('data')
            ->columns(2);
    }

    public function getResult(): array
    {
        $asOf = Carbon::parse($this->data['as_of'] ?? now()->toDateString());
        $storeId = $this->data['store_id'] ?? null;

        return app(FinancialStatementService::class)->balanceSheet($asOf, $storeId);
    }
}
