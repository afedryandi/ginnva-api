<?php

namespace App\Filament\Pages;

use App\Models\ChartOfAccount;
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
 * Buku Besar — rincian TIAP baris jurnal yang menyentuh 1 akun terpilih
 * dalam 1 rentang tanggal, dengan saldo berjalan per baris. Pelengkap
 * Neraca Saldo yang cuma kasih 1 angka saldo akhir per akun — ini yang
 * dipakai untuk "ngecek kenapa saldo akun ini segini". TERBATAS
 * full-access, sama filosofi dengan TrialBalanceReport/IncomeStatementReport.
 */
class GeneralLedgerReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'Buku Besar';

    protected static ?string $title = 'Buku Besar';

    protected static ?int $navigationSort = 7;

    protected static string $view = 'filament.pages.general-ledger-report';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'chart_of_account_id' => ChartOfAccount::where('is_postable', true)->orderBy('code')->value('id'),
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'store_id' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('chart_of_account_id')
                    ->label('Akun')
                    ->options(fn () => ChartOfAccount::where('is_postable', true)
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name]))
                    ->searchable()
                    ->required()
                    ->live()
                    ->columnSpanFull(),

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

    public function getResult(): ?array
    {
        $accountId = $this->data['chart_of_account_id'] ?? null;
        if (! $accountId) {
            return null;
        }

        $account = ChartOfAccount::find($accountId);
        if (! $account) {
            return null;
        }

        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth()->toDateString());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth()->toDateString());
        $storeId = $this->data['store_id'] ?? null;

        return app(FinancialStatementService::class)->generalLedger($account, $from, $to, $storeId);
    }
}
