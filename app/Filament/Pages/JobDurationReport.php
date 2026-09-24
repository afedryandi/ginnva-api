<?php

namespace App\Filament\Pages;

use App\Exports\JobDurationExport;
use App\Models\Store;
use App\Services\JobDurationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Facades\Excel;

/**
 * "Laporan Proses Order" — audit Majoo f12 ("laporan durasi pengerjaan
 * per job/teknisi/jenis layanan"), dibangun 2026-09-22. Level baris =
 * 1 job (1 SPK) — lihat App\Services\JobDurationService untuk sumber
 * data & bedanya dengan TechnicianServiceDurationReport (itu akumulasi
 * per teknisi utk persiapan komisi, ini per-job utk analitik
 * operasional).
 *
 * Agregat per jenis layanan (rata-rata/tercepat/terlama) SENGAJA belum
 * dibangun di sini -- itu temuan f13 terpisah ("Laporan Proses Produk").
 */
class JobDurationReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?string $navigationGroup = 'Laporan Jasa';

    protected static ?string $navigationLabel = 'Proses Order';

    protected static ?string $title = 'Laporan Proses Order';

    // 104 -- band grup 'Laporan Jasa' (100-an), setelah
    // ReservationUtilizationReport (103).
    protected static ?int $navigationSort = 104;

    protected static string $view = 'filament.pages.job-duration-report';

    public ?array $data = [];

    #[Url(as: 'from')]
    public ?string $from = null;

    #[Url(as: 'to')]
    public ?string $to = null;

    #[Url(as: 'cabang')]
    public ?int $storeId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    public function mount(): void
    {
        $this->from = $this->queryDateOrDefault($this->from, now()->startOfMonth());
        $this->to = $this->queryDateOrDefault($this->to, now()->endOfMonth());

        if (! (auth()->user()?->isFullAccess() ?? false)) {
            $this->storeId = auth()->user()?->store_id;
        }

        $this->form->fill([
            'from' => $this->from,
            'to' => $this->to,
            'store_id' => $this->storeId,
        ]);
    }

    private function queryDateOrDefault(mixed $value, Carbon $default): string
    {
        if (! is_string($value) || $value === '') {
            return $default->toDateString();
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return $default->toDateString();
        }
    }

    public function updatedData(mixed $value, string $key): void
    {
        match ($key) {
            'from' => $this->from = $value,
            'to' => $this->to = $value,
            'store_id' => $this->storeId = $value ? (int) $value : null,
            default => null,
        };
    }

    public function form(Form $form): Form
    {
        $isFullAccess = auth()->user()?->isFullAccess() ?? false;

        return $form->schema([
            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),
            Select::make('store_id')
                ->label('Cabang')
                ->options(fn () => Store::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->visible($isFullAccess)
                ->required($isFullAccess)
                ->live(),
        ])->columns($isFullAccess ? 3 : 2)->statePath('data');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getJobs(): Collection
    {
        $user = auth()->user();
        $storeId = $user?->isFullAccess() ? $this->storeId : $user?->store_id;

        return app(JobDurationService::class)->jobs(
            Carbon::parse($this->from)->startOfDay(),
            Carbon::parse($this->to)->endOfDay(),
            $storeId,
        );
    }

    /**
     * Bungkus getJobs() + rentang tanggal jadi 1 array untuk export --
     * getJobs() sendiri sudah dipakai blade (return Collection polos),
     * jadi TIDAK diubah supaya tidak menyentuh view yang sudah ada.
     */
    private function getResult(): array
    {
        return [
            'from' => Carbon::parse($this->from)->startOfDay(),
            'to' => Carbon::parse($this->to)->endOfDay(),
            'jobs' => $this->getJobs(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new JobDurationExport($this->getResult()),
                    'laporan-proses-order-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.job_duration_report', ['result' => $result])->setPaper('a4', 'landscape');
                    $filename = 'laporan-proses-order-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }
}
