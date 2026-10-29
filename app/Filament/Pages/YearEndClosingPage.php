<?php

namespace App\Filament\Pages;

use App\Models\YearEndClosing;
use App\Services\YearEndClosingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;
use RuntimeException;

/**
 * "Tutup Tahun Buku" (Keuangan Fase 6, 2026-11-09): memindahkan laba (rugi) satu tahun ke Laba Ditahan lewat jurnal penutup. Hanya full-access
 * (keputusan akuntansi besar, dan membatalkannya perlu periode Desember terbuka).
 */
class YearEndClosingPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    protected static ?string $navigationGroup = 'Jurnal & Akun';

    protected static ?string $navigationLabel = 'Tutup Tahun Buku';

    protected static ?string $title = 'Tutup Tahun Buku';

    protected static ?int $navigationSort = 205;

    protected static string $view = 'filament.pages.year-end-closing';

    public static function canAccess(): bool
    {
        if (! config('features.finance')) {
            return false;
        }

        return auth()->user()?->isFullAccess() ?? false;
    }

    /** Tahun yang sudah lewat dan punya jurnal, belum ditutup. */
    public static function candidateYears(): array
    {
        $closed = YearEndClosing::closedYears();
        $years = \App\Models\JournalEntry::withoutGlobalScopes()->where('status', 'posted')->selectRaw('DISTINCT YEAR(entry_date) as y')->pluck('y')->map(fn ($y) => (int) $y)->all();

        return collect($years)->filter(fn ($y) => $y < now()->year && ! in_array($y, $closed, true))->sort()->mapWithKeys(fn ($y) => [$y => (string) $y])->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('closeYear')
                ->label('Tutup Tahun Buku')
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->modalHeading('Tutup Tahun Buku')
                ->modalSubmitActionLabel('Tutup Tahun')
                ->form([
                    Select::make('year')->label('Tahun')->options(fn () => static::candidateYears())->required()->live(),
                    Placeholder::make('preview')->label('Yang akan terjadi')->content(function ($get) {
                        $year = (int) $get('year');

                        if (! $year) {
                            return 'Pilih tahun untuk melihat pratinjau.';
                        }

                        $service = app(YearEndClosingService::class);
                        $issues = $service->blockers($year);

                        if ($issues) {
                            return new HtmlString('<span class="text-danger-600">' . e(implode(' ', $issues)) . '</span>');
                        }

                        $p = $service->preview($year);
                        $label = $p['net_income'] >= 0 ? 'Laba bersih' : 'Rugi bersih';

                        return "{$p['accounts']} akun Laba Rugi dinolkan lewat {$p['groups']->count()} jurnal penutup bertanggal 31 Desember {$year}. {$label} Rp "
                            . number_format(abs($p['net_income']), 0, ',', '.') . ' dipindahkan ke 3200 Laba Ditahan.';
                    }),
                    Textarea::make('notes')->label('Catatan')->rows(2)->maxLength(300),
                ])
                ->action(function (array $data) {
                    try {
                        $closing = app(YearEndClosingService::class)->close((int) $data['year'], auth()->id(), $data['notes'] ?? null);
                        Notification::make()->title("Tahun {$closing->year} ditutup")->body("{$closing->entries_count} jurnal penutup dibuat.")->success()->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->title('Tidak bisa ditutup')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('voidYear')
                ->label('Batalkan Penutupan')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn () => YearEndClosing::whereNull('voided_at')->exists())
                ->modalHeading('Batalkan Penutupan Tahun Buku')
                ->form([
                    Select::make('year')->label('Tahun')->options(fn () => collect(YearEndClosing::closedYears())->sort()->mapWithKeys(fn ($y) => [$y => (string) $y])->all())->required(),
                    Textarea::make('reason')->label('Alasan')->required()->rows(2)->maxLength(300),
                ])
                ->action(function (array $data) {
                    try {
                        $closing = YearEndClosing::whereNull('voided_at')->where('year', (int) $data['year'])->firstOrFail();
                        app(YearEndClosingService::class)->void($closing, auth()->id(), $data['reason']);
                        Notification::make()->title("Penutupan tahun {$closing->year} dibatalkan")->success()->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->title('Tidak bisa dibatalkan')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    public function getClosings()
    {
        return YearEndClosing::with('closer')->orderByDesc('year')->orderByDesc('id')->get();
    }
}
