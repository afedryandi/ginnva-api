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
use Filament\Notifications\Notification;
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

        // "Sampai" < "Dari" via URL diutak-atik manual (audit Proses Produk 2026-09-29): dikoreksi
        // diam-diam di sini, sama pola dengan laporan Penjualan lain.
        if (Carbon::parse($this->to)->lt(Carbon::parse($this->from))) {
            $this->to = $this->from;
        }

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

        // "Sampai" sebelum "Dari" (audit Proses Produk 2026-09-29): sebelumnya diam-diam
        // menghasilkan tabel kosong tanpa penjelasan -- dikoreksi + diberi tahu, sama pola dengan
        // laporan Penjualan lain. TIDAK pakai minDate() reaktif di form (pernah membuat panel
        // filter gagal render di Detail Penjualan) -- validasi murni lewat hook Livewire ini.
        if (in_array($key, ['from', 'to'], true) && $this->from && $this->to && Carbon::parse($this->to)->lt(Carbon::parse($this->from))) {
            $this->to = $this->from;
            $this->data['to'] = $this->from;

            Notification::make()
                ->title('Tanggal "Sampai" tidak boleh sebelum "Dari"')
                ->body('Diset sama dengan tanggal "Dari".')
                ->warning()
                ->send();
        }
    }

    public function form(Form $form): Form
    {
        $isFullAccess = auth()->user()?->isFullAccess() ?? false;

        return $form->schema([
            // Periode Cepat (audit 2026-09-29, sejajar laporan Penjualan lain).
            Select::make('preset')
                ->label('Periode Cepat')
                ->options([
                    'this_month' => 'Bulan ini',
                    'last_month' => 'Bulan lalu',
                    'this_quarter' => 'Kuartal ini',
                    'ytd' => 'Tahun ini (s.d. hari ini)',
                    'last_year' => 'Tahun lalu',
                ])
                ->placeholder('Pilih untuk mengisi tanggal otomatis')
                ->live()
                ->afterStateUpdated(function (?string $state, \Filament\Forms\Set $set) {
                    $range = match ($state) {
                        'this_month' => [now()->startOfMonth(), now()->endOfMonth()],
                        'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
                        'this_quarter' => [now()->startOfQuarter(), now()->endOfQuarter()],
                        'ytd' => [now()->startOfYear(), now()],
                        'last_year' => [now()->subYear()->startOfYear(), now()->subYear()->endOfYear()],
                        default => null,
                    };

                    if ($range) {
                        $set('from', $range[0]->toDateString());
                        $set('to', $range[1]->toDateString());
                        $this->from = $range[0]->toDateString();
                        $this->to = $range[1]->toDateString();
                    }
                }),

            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),

            // GAP DIPERBAIKI 2026-09-29: full-access SEBELUMNYA wajib pilih 1 cabang (tidak ada
            // opsi lihat company-wide) -- sekarang opsional, sama pola dengan laporan lain.
            Select::make('store_id')
                ->label('Cabang')
                ->placeholder('Semua cabang')
                ->options(fn () => Store::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->visible($isFullAccess)
                ->live(),
        ])->columns($isFullAccess ? 4 : 3)->statePath('data');
    }

    /**
     * Toko yang BENAR-BENAR berlaku: full-access memilih (null = semua cabang), staf toko dikunci ke tokonya, dan
     * staf tanpa toko dikunci ke -1 (tidak cocok toko mana pun) -- bukan null yang berarti semua cabang.
     */
    private function effectiveStoreId(): ?int
    {
        $user = auth()->user();

        if ($user?->isFullAccess() ?? false) {
            return $this->storeId ?: null;
        }

        return $user?->store_id ?? -1;
    }

    /** Log ekspor (audit Proses Produk 2026-09-29), konsisten dengan laporan lain. */
    private function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => 'job_duration_by_service', 'format' => $format, 'from' => $this->from, 'to' => $this->to, 'store_id' => $this->effectiveStoreId()])
                ->log('Ekspor Laporan Proses Produk (' . $format . ')');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getRows(): Collection
    {
        $storeId = $this->effectiveStoreId();

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
                ->action(function () {
                    $this->logExport('xlsx');

                    return Excel::download(
                        new JobDurationByServiceExport($this->getResult()),
                        'laporan-proses-produk-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $this->logExport('pdf');

                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.job_duration_by_service_report', ['result' => $result])->setPaper('a4', 'landscape');
                    $filename = 'laporan-proses-produk-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }
}
