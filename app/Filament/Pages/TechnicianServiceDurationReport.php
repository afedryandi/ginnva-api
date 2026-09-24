<?php

namespace App\Filament\Pages;

use App\Exports\TechnicianServiceDurationExport;
use App\Models\Store;
use App\Services\TechnicianServiceDurationService;
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
 * "Akumulasi Durasi Servis Teknisi" — temuan PRIORITAS TINGGI dari
 * audit Majoo vs Ginnva ("Komisi Bertingkat berbasis Durasi Layanan
 * Selesai"), dibangun 2026-09-22.
 *
 * Lihat App\Services\TechnicianServiceDurationService untuk definisi
 * metrik & keterbatasannya. INI CUMA LAPORAN AKUMULASI — belum ada
 * tier/nominal komisi progresif, itu keputusan bisnis yang masih
 * menunggu user.
 *
 * Pola form/filter disalin dari TechnicianUtilizationReport supaya
 * konsisten dengan laporan Karyawan lain.
 */
class TechnicianServiceDurationReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationLabel = 'Durasi Servis Teknisi';

    protected static ?string $title = 'Akumulasi Durasi Servis Teknisi';

    protected static ?int $navigationSort = 36;

    protected static string $view = 'filament.pages.technician-service-duration-report';

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
            $this->storeId = null;
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
                ->placeholder('Semua cabang')
                ->options(fn () => Store::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->visible($isFullAccess)
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

        return app(TechnicianServiceDurationService::class)->summarize(
            Carbon::parse($this->from)->startOfDay(),
            Carbon::parse($this->to)->endOfDay(),
            $storeId,
        );
    }

    /**
     * Bungkus getRows() + rentang tanggal jadi satu array supaya Export/PDF
     * bisa dibangun dari SATU sumber yang sama dengan yang tampil di layar
     * (pola sama dengan laporan Penjualan — lihat SalesSummaryReport).
     */
    protected function getResult(): array
    {
        return [
            'from' => Carbon::parse($this->from),
            'to' => Carbon::parse($this->to),
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
                    new TechnicianServiceDurationExport($this->getResult()),
                    'durasi-servis-teknisi-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.technician_service_duration_report', ['result' => $result])->setPaper('a4', 'portrait');
                    $filename = 'durasi-servis-teknisi-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }
}
