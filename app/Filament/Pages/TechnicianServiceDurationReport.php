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

    // Grup sidebar (audit navigasi 2026-09-29) -- band 500-599 "Kinerja Teknisi".
    protected static ?string $navigationGroup = 'Kinerja Installer';

    protected static ?string $navigationLabel = 'Durasi Servis Installer';

    protected static ?string $title = 'Durasi Servis Installer';

    protected static ?int $navigationSort = 501;

    protected static string $view = 'filament.pages.technician-service-duration-report';

    public ?array $data = [];

    #[Url(as: 'from')]
    public ?string $from = null;

    #[Url(as: 'to')]
    public ?string $to = null;

    #[Url(as: 'cabang')]
    public ?int $storeId = null;

    /** Installer yang rincian jobnya sedang dibuka di tabel (null = tertutup). */
    public ?int $expandedTechnicianId = null;

    /**
     * Laporan kinerja per installer (bahan penilaian): akses penuh, store manager (untuk installer tokonya), atau staf yang menunya
     * dicentang eksplisit. Akun bawaan (menu_access kosong) tidak melihatnya; daftar menu yang dibatasi tetap dihormati.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class)
            && ($user->isFullAccess() || $user->isStoreManager() || is_array($user->menu_access));
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
                ->label('Toko')
                ->placeholder('Semua toko')
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

    /** Buka atau tutup rincian job seorang installer. Hanya installer yang memang tampil di laporan ini yang bisa dibuka. */
    public function toggleJobs(int $technicianId): void
    {
        if ($this->expandedTechnicianId === $technicianId) {
            $this->expandedTechnicianId = null;

            return;
        }

        $this->expandedTechnicianId = $this->getRows()->contains('technician_id', $technicianId) ? $technicianId : null;
    }

    /**
     * Rincian job installer yang sedang dibuka; kosong bila tidak ada yang dibuka atau installernya tidak punya akun.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getExpandedJobs(): Collection
    {
        $row = $this->expandedTechnicianId
            ? $this->getRows()->firstWhere('technician_id', $this->expandedTechnicianId)
            : null;

        if (! $row || ! $row['has_account']) {
            return collect();
        }

        $user = auth()->user();
        $storeId = $user?->isFullAccess() ? $this->storeId : $user?->store_id;
        $userId = \App\Models\Technician::query()->whereKey($row['technician_id'])->value('user_id');

        return app(TechnicianServiceDurationService::class)->jobsFor(
            (int) $userId,
            Carbon::parse($this->from)->startOfDay(),
            Carbon::parse($this->to)->endOfDay(),
            $storeId,
        );
    }

    public function bookingUrl(?int $bookingId): ?string
    {
        return $bookingId && \App\Filament\Resources\BookingResource::canViewAny()
            ? \App\Filament\Resources\BookingResource::getUrl('view', ['record' => $bookingId])
            : null;
    }

    /** Tautan ke laporan durasi per job (Proses Order), bila boleh dibuka. */
    public function jobReportUrl(): ?string
    {
        return JobDurationReport::canAccess() ? JobDurationReport::getUrl() : null;
    }

    /** Jumlah SPK di periode ini yang kendaraannya masuk lebih dari sehari lalu tetapi belum di-check-out (tidak ikut terhitung). */
    public function getUnfinishedSpkCount(): int
    {
        $user = auth()->user();
        $storeId = $user?->isFullAccess() ? $this->storeId : $user?->store_id;

        return app(TechnicianServiceDurationService::class)->unfinishedSpkCount(
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
