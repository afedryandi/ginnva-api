<?php

namespace App\Filament\Resources\ActivityResource\Pages;

use App\Exports\ActivityLogExport;
use App\Filament\Resources\ActivityResource;
use App\Support\ActivityPresenter;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;

class ListActivities extends ListRecords
{
    protected static string $resource = ActivityResource::class;

    /**
     * Ringkasan ukuran log (Histori Aktivitas Fase 4): retensi sengaja tanpa batas, jadi pemilik perlu melihat seberapa besar log
     * sudah tumbuh. Dihitung paling sering tiap 5 menit supaya membuka halaman tidak menghitung seluruh tabel setiap kali.
     * Rinciannya (per modul, per bulan, ukuran MB) lewat perintah `php artisan activity:size-report`.
     */
    public function getSubheading(): string|Htmlable|null
    {
        $stats = Cache::remember('activity_log_summary', 300, fn () => [
            'total' => Activity::query()->count(),
            'oldest' => Activity::query()->min('created_at'),
        ]);

        if ($stats['total'] === 0) {
            return null;
        }

        $since = Carbon::parse($stats['oldest'], config('app.timezone'))->setTimezone(ActivityPresenter::TIMEZONE)->format('d M Y');

        return number_format($stats['total'], 0, ',', '.') . " catatan sejak {$since} (disimpan tanpa batas waktu).";
    }

    /** Pintasan (Histori Aktivitas Fase 5): semua, perubahan berisiko, dan kejadian keamanan akun. */
    public function getTabs(): array
    {
        return [
            'semua' => Tab::make('Semua'),
            'berisiko' => Tab::make('Perubahan Berisiko')
                ->icon('heroicon-o-exclamation-triangle')
                ->modifyQueryUsing(fn (Builder $query) => ActivityResource::applyRiskyScope($query)),
            'keamanan' => Tab::make('Keamanan Akun')
                ->icon('heroicon-o-shield-check')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('log_name', 'auth')),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            // Isi ekspor sama dengan layar (filter, pencarian, dan tab aktif). Ekspor itu sendiri dicatat sebagai report_export.
            Action::make('exportExcel')
                ->label('Ekspor ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn () => ActivityResource::canViewAny())
                ->action(function () {
                    $query = $this->getFilteredTableQuery();
                    $rows = (clone $query)->reorder()->count();

                    if ($rows > ActivityLogExport::$maxRows) {
                        Notification::make()
                            ->title('Terlalu banyak baris untuk diekspor')
                            ->body(number_format($rows, 0, ',', '.') . ' baris cocok dengan filter; batasnya ' . number_format(ActivityLogExport::$maxRows, 0, ',', '.') . '. Persempit rentang tanggal atau modul.')
                            ->danger()
                            ->send();

                        return null;
                    }

                    try {
                        activity('report_export')
                            ->causedBy(auth()->user())
                            ->withProperties(['report' => 'activity_log', 'format' => 'xlsx', 'rows' => $rows])
                            ->log('Ekspor Histori Aktivitas (xlsx)');
                    } catch (\Throwable $e) {
                        report($e);
                    }

                    return Excel::download(new ActivityLogExport($query), 'histori-aktivitas-' . now()->format('Ymd-His') . '.xlsx');
                }),
        ];
    }
}
