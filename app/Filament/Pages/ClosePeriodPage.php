<?php

namespace App\Filament\Pages;

use App\Models\AccountingPeriod;
use App\Services\AccountingPeriodService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
class ClosePeriodPage extends Page implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    protected static ?string $navigationLabel = 'Tutup Periode';

    protected static ?string $title = 'Tutup Periode';

    // Direnumber 13 (dari 10) -- audit navigasi 2026-09-15, dampak
    // renumber beruntun akibat tabrakan sort lain di cluster ini.
    protected static ?int $navigationSort = 13;

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

        // Pratinjau per bulan (audit Jurnal Umum 2026-09-29): jumlah jurnal posted,
        // draft yang menghalangi, dan apakah total debit = kredit.
        $stats = \Illuminate\Support\Facades\DB::table('journal_entries')
            ->whereYear('entry_date', $year)
            ->selectRaw("MONTH(entry_date) as m, SUM(status = 'posted') as posted, SUM(status = 'draft') as draft")
            ->groupBy('m')
            ->get()
            ->keyBy('m');
        $balances = \Illuminate\Support\Facades\DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->whereYear('e.entry_date', $year)
            ->where('e.status', 'posted')
            ->selectRaw('MONTH(e.entry_date) as m, ROUND(SUM(l.debit) * 100) as d, ROUND(SUM(l.credit) * 100) as c')
            ->groupBy('m')
            ->get()
            ->keyBy('m');

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
                'posted_count' => (int) ($stats[$m]->posted ?? 0),
                'draft_count' => (int) ($stats[$m]->draft ?? 0),
                'balanced' => (int) ($balances[$m]->d ?? 0) === (int) ($balances[$m]->c ?? 0),
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

    /**
     * Buka kembali periode WAJIB dengan alasan (audit Jurnal Umum 2026-09-29):
     * sebelumnya cuma wire:confirm tanpa alasan, dan periode dihapus tanpa jejak.
     * Alasan tercatat di activity log.
     */
    public function reopenPeriodAction(): Action
    {
        return Action::make('reopenPeriod')
            ->modalHeading(fn (array $arguments) => 'Buka kembali periode ' . Carbon::create((int) $arguments['year'], (int) $arguments['month'], 1)->translatedFormat('F Y') . '?')
            ->modalDescription('Jurnal dengan tanggal di bulan ini akan bisa dibuat/diubah/diposting lagi. Keputusan ini tercatat beserta alasannya.')
            ->modalSubmitActionLabel('Buka Kembali')
            ->color('danger')
            ->form([
                Textarea::make('reason')->label('Alasan membuka kembali')->required()->rows(2)->maxLength(500),
            ])
            ->action(function (array $arguments, array $data) {
                $period = AccountingPeriod::where('period_month', Carbon::create((int) $arguments['year'], (int) $arguments['month'], 1)->toDateString())->first();

                if (! $period) {
                    return;
                }

                try {
                    app(AccountingPeriodService::class)->reopen($period, auth()->id(), $data['reason']);
                } catch (RuntimeException $e) {
                    Notification::make()->title('Gagal membuka periode')->body($e->getMessage())->danger()->send();

                    return;
                }

                // Dual-control ringan: direksi LAIN diberi tahu (tidak ada yang bisa
                // membuka periode tanpa terlihat). Kegagalan kirim tak menggagalkan aksi.
                try {
                    $label = Carbon::create((int) $arguments['year'], (int) $arguments['month'], 1)->translatedFormat('F Y');
                    foreach (\App\Models\User::where('is_active', true)->where('id', '!=', auth()->id())->get()->filter(fn ($u) => $u->isFullAccess()) as $user) {
                        Notification::make()
                            ->title("Periode {$label} dibuka kembali")
                            ->body(auth()->user()->name . ': ' . $data['reason'])
                            ->warning()
                            ->sendToDatabase($user);
                    }
                } catch (\Throwable $e) {
                    report($e);
                }

                Notification::make()->title('Periode dibuka kembali')->success()->send();
            });
    }
}
