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
 * Neraca Saldo — saldo KUMULATIF tiap akun per tanggal cutoff (bukan 1
 * periode seperti Laporan Laba Rugi), dihitung dari Jurnal Umum yang
 * 'posted'. TERBATAS full-access, sama filosofi dengan JournalEntryResource
 * — sumber datanya (Jurnal Umum) memang full-access-only.
 */
class TrialBalanceReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'Neraca Saldo';

    protected static ?string $title = 'Neraca Saldo';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.trial-balance-report';

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

        return app(FinancialStatementService::class)->trialBalance($asOf, $storeId);
    }
}
