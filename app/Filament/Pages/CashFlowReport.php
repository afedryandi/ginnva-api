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
 * Laporan Arus Kas — metode LANGSUNG (direct method), dikelompokkan
 * Operasional/Investasi/Pendanaan berdasarkan ChartOfAccount::
 * cash_flow_category. Lihat komentar lengkap di
 * FinancialStatementService::cashFlowStatement() soal cara klasifikasi
 * & keterbatasannya. TERBATAS full-access, sama filosofi dengan
 * laporan Keuangan lain yang bersumber dari Jurnal Umum.
 */
class CashFlowReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'Laporan Arus Kas';

    protected static ?string $title = 'Laporan Arus Kas';

    protected static ?int $navigationSort = 9;

    protected static string $view = 'filament.pages.cash-flow-report';

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

        return app(FinancialStatementService::class)->cashFlowStatement($from, $to, $storeId);
    }
}
