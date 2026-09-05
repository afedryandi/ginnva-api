<?php

namespace App\Filament\Pages;

use App\Models\AccountingPeriod;
use App\Services\AccountingPeriodService;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Tutup Periode — kunci 1 bulan supaya jurnal dengan tanggal di bulan
 * itu tidak bisa lagi dibuat/diubah/diposting (lihat JournalEntryService::
 * assertPeriodOpen()). Fitur INTEGRITAS, bukan laporan — beda dari
 * halaman Keuangan lain yang cuma menampilkan data.
 *
 * TERBATAS full-access, sama filosofi dengan resource/halaman Keuangan
 * lain yang menyentuh Jurnal Umum.
 */
class ClosePeriodPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'Tutup Periode';

    protected static ?string $title = 'Tutup Periode';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.close-period';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(['year' => now()->year]);
    }

    public function form(Form $form): Form
    {
        $currentYear = now()->year;

        return $form
            ->schema([
                Select::make('year')
                    ->label('Tahun')
                    ->options(collect(range($currentYear, $currentYear - 3))->mapWithKeys(fn ($y) => [$y => $y]))
                    ->required()
                    ->live(),
            ])
            ->statePath('data')
            ->columns(1);
    }

    /**
     * @return array<int, array{month: int, date: Carbon, is_closed: bool, period: ?AccountingPeriod, is_future: bool}>
     */
    public function getMonths(): array
    {
        $year = (int) ($this->data['year'] ?? now()->year);
        $periods = AccountingPeriod::whereYear('period_month', $year)->get()->keyBy(fn ($p) => $p->period_month->month);

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $date = Carbon::create($year, $m, 1);
            $period = $periods->get($m);

            $months[] = [
                'month' => $m,
                'date' => $date,
                'is_closed' => $period !== null,
                'period' => $period,
                'is_future' => $date->greaterThan(now()->startOfMonth()),
            ];
        }

        return $months;
    }

    public function closeMonth(int $year, int $month): void
    {
        try {
            app(AccountingPeriodService::class)->close(Carbon::create($year, $month, 1), auth()->id());
            Notification::make()->title('Periode ditutup')->success()->send();
        } catch (RuntimeException $e) {
            Notification::make()->title('Gagal menutup periode')->body($e->getMessage())->danger()->send();
        }
    }

    public function reopenMonth(int $year, int $month): void
    {
        $period = AccountingPeriod::where('period_month', Carbon::create($year, $month, 1)->toDateString())->first();

        if (! $period) {
            return;
        }

        app(AccountingPeriodService::class)->reopen($period);
        Notification::make()->title('Periode dibuka kembali')->success()->send();
    }
}
