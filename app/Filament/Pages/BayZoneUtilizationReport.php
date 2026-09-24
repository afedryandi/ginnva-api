<?php

namespace App\Filament\Pages;

use App\Exports\BayZoneUtilizationExport;
use App\Models\Store;
use App\Services\BayZoneUtilizationService;
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
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Facades\Excel;

/**
 * "Laporan Utilisasi Zona/Bay" — f18 audit Majoo vs Ginnva, disetujui
 * pemilik bisnis 2026-09-24, dibangun setelah diskusi desain penuh
 * (lihat App\Services\BayZoneUtilizationService untuk penjelasan
 * lengkap metrik & pendekatan yang dipakai).
 *
 * BEDA dengan App\Filament\Pages\ReservationUtilizationReport (JANGAN
 * disatukan/ditukar — ini 2 laporan yang sengaja terpisah):
 * - ReservationUtilizationReport: 1 angka kapasitas AGREGAT per toko
 *   (Store::install_capacity_per_day), utilisasi = JUMLAH BOOKING yang
 *   overlap per hari / kapasitas. Tidak butuh histori transisi tahap
 *   sama sekali, cukup Booking::confirmedOverlapCount().
 * - Laporan INI: 2 ZONA FISIK per toko (Zona Detailing & Persiapan,
 *   Zona Instalasi & QC), masing-masing kapasitas slot SENDIRI
 *   (Store::detailing_slot_count / instalasi_qc_slot_count, beda-beda
 *   per toko). Utilisasi dihitung dari DURASI NYATA tiap booking
 *   menempati tahap-tahap zona itu (direkonstruksi dari activity_log
 *   perubahan current_stage/secondary_stage), karena durasi tiap tahap
 *   tidak bisa diestimasi (ukuran/kondisi mobil sangat bervariasi).
 *
 * Karena slot count beda per toko, laporan ini TIDAK punya mode "semua
 * cabang" — full-access WAJIB pilih 1 toko (tidak ada pilihan "Semua
 * cabang" seperti laporan lain), store_manager terkunci ke tokonya
 * sendiri dan field dropdown-nya disembunyikan.
 */
class BayZoneUtilizationReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?string $navigationGroup = 'Laporan Fasilitas';

    protected static ?string $navigationLabel = 'Laporan Utilisasi Zona/Bay';

    protected static ?string $title = 'Laporan Utilisasi Zona/Bay';

    // 700 -- band grup BARU 'Laporan Fasilitas' (lihat catatan sistem
    // band di CustomerSatisfactionReport.php / ProductSalesReport.php).
    // Band sebelumnya sudah dipakai sampai 600-an (Analisa Laporan),
    // jadi 700 dipilih supaya tidak tabrakan dengan grup manapun yang
    // sudah ada, sekaligus tetap tersedia banyak ruang (700-799) untuk
    // laporan fasilitas lain di masa depan.
    protected static ?int $navigationSort = 700;

    protected static string $view = 'filament.pages.bay-zone-utilization-report';

    public ?array $data = [];

    #[Url(as: 'from')]
    public ?string $from = null;

    #[Url(as: 'to')]
    public ?string $to = null;

    #[Url(as: 'toko')]
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

        $user = auth()->user();

        if ($user?->isFullAccess() ?? false) {
            // Tidak ada opsi "semua cabang" (slot berbeda per toko) —
            // default ke toko pertama yang aktif supaya halaman tidak
            // kosong saat pertama dibuka.
            $this->storeId ??= Store::query()->where('is_active', true)->orderBy('name')->value('id');
        } else {
            $this->storeId = $user?->store_id;
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
                ->required()
                ->options(fn () => Store::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->visible($isFullAccess)
                ->live(),
        ])->columns($isFullAccess ? 3 : 2)->statePath('data');
    }

    private function selectedStore(): ?Store
    {
        $user = auth()->user();
        $storeId = $user?->isFullAccess() ? $this->storeId : $user?->store_id;

        return $storeId ? Store::find($storeId) : null;
    }

    /**
     * Bungkus semua bahan tampilan/export jadi satu array supaya layar +
     * Excel + PDF selalu bersumber dari query yang SAMA (pola sama
     * laporan Penjualan lain).
     */
    public function getResult(): array
    {
        $from = Carbon::parse($this->from)->startOfDay();
        $to = Carbon::parse($this->to)->endOfDay();
        $store = $this->selectedStore();

        $zones = $store
            ? app(BayZoneUtilizationService::class)->summarize($store, $from, $to)
            : [];

        $configured = $store && collect($zones)->contains('configured', true);

        return [
            'from' => $from,
            'to' => $to,
            'store' => $store,
            'zones' => $zones,
            // Toko dianggap "belum siap" kalau belum dipilih SAMA SEKALI,
            // atau kedua zona-nya null (belum diisi slot manapun) — beda
            // dari kasus salah satu zona saja yang null (zona itu sendiri
            // yang tampil "belum dikonfigurasi", zona lain tetap dihitung
            // normal, lihat BayZoneUtilizationService::unconfiguredResult()).
            'configured' => $configured,
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
                    $result = $this->getResult();

                    if (! $result['configured']) {
                        $this->notifyNotConfigured();

                        return;
                    }

                    return Excel::download(
                        new BayZoneUtilizationExport($result),
                        'utilisasi-zona-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $result = $this->getResult();

                    if (! $result['configured']) {
                        $this->notifyNotConfigured();

                        return;
                    }

                    $pdf = Pdf::loadView('pdf.bay_zone_utilization_report', ['result' => $result])->setPaper('a4', 'portrait');
                    $filename = 'utilisasi-zona-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    private function notifyNotConfigured(): void
    {
        Notification::make()
            ->title('Toko belum bisa diekspor')
            ->body('Toko ini belum diisi kapasitas slot — isi dulu di halaman Toko/Dealer.')
            ->danger()
            ->send();
    }
}
