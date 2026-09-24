<?php

namespace App\Filament\Pages;

use App\Exports\JobDurationByServiceExport;
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
 * "Laporan Proses Produk" — audit Majoo f13 ("Agregat durasi pengerjaan
 * per layanan: rata-rata, tercepat, terlama"), dibangun 2026-09-22.
 * "Produk" versi Majoo (barang retail) diterjemahkan jadi "jenis
 * layanan" untuk Ginnva (PPF/Kaca Film/Detailing/Premium Wash) — lihat
 * feedback_majoo_relevance_filter, ini BUKAN sekadar tiru istilah Majoo
 * mentah-mentah.
 *
 * Dibangun di atas App\Services\JobDurationService::aggregateByService()
 * (satu sumber kebenaran durasi dengan "Proses Order" f12) supaya
 * angkanya konsisten job-per-job vs rollup per layanan.
 */
class JobDurationByServiceReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?string $navigationGroup = 'Laporan Jasa';

    protected static ?string $navigationLabel = 'Proses Produk';

    protected static ?string $title = 'Laporan Proses Produk (Durasi per Layanan)';

    // 105 -- band grup 'Laporan Jasa' (100-an), setelah JobDurationReport (104).
    protected static ?int $navigationSort = 105;

    protected static string $view = 'filament.pages.job-duration-by-service-report';

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
    public function getRows(): Collection
    {
        $user = auth()->user();
        $storeId = $user?->isFullAccess() ? $this->storeId : $user?->store_id;

        return app(JobDurationService::class)->aggregateByService(
            Carbon::parse($this->from)->startOfDay(),
            Carbon::parse($this->to)->endOfDay(),
            $storeId,
        );
    }

    /**
     * Bungkus getRows() + rentang tanggal jadi 1 array untuk export --
     * getRows() sendiri TIDAK diubah supaya tidak menyentuh view yang
     * sudah ada (sama pola dengan JobDurationReport::getResult()).
     */
    private function getResult(): array
    {
        return [
            'from' => Carbon::parse($this->from)->startOfDay(),
            'to' => Carbon::parse($this->to)->endOfDay(),
            'rows' => $this->getRows(),
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
                    new JobDurationByServiceExport($this->getResult()),
                    'laporan-proses-produk-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.job_duration_by_service_report', ['result' => $result])->setPaper('a4', 'landscape');
                    $filename = 'laporan-proses-produk-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }
}
