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
 * Laporan Laba Rugi — untuk 1 RENTANG periode (beda dari Neraca Saldo
 * yang kumulatif per tanggal cutoff), dihitung dari Jurnal Umum yang
 * 'posted'. TERBATAS full-access, sama filosofi dengan
 * JournalEntryResource/TrialBalanceReport.
 */
class IncomeStatementReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'Laporan Laba Rugi';

    protected static ?string $title = 'Laporan Laba Rugi';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.income-statement-report';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
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
        return $form
            ->schema([
                DatePicker::make('from')
                    ->label('Dari Tanggal')
                    ->native(false)
                    ->required()
                    ->live(),

                DatePicker::make('to')
                    ->label('Sampai Tanggal')
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
            ->columns(3);
    }

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth()->toDateString());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth()->toDateString());
        $storeId = $this->data['store_id'] ?? null;

        return app(FinancialStatementService::class)->incomeStatement($from, $to, $storeId);
    }
}
