<?php

namespace App\Filament\Pages;

use App\Exports\SerialNumberReportExport;
use App\Models\ScrollCode;
use App\Models\ScrollCodeUsage;
use App\Models\Store;
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
 * "Laporan Serial Number" — diminta 2026-09-09, analog Majoo. AWALNYA
 * (audit 2026-09-08) ditandai tidak bisa ("tidak dicatat sebagai kode
 * diskrit") -- dikoreksi setelah ditemukan ScrollCode: kode/nomor seri
 * ROLL film PPF & Kaca Film, LENGKAP (kode, produk, toko, status,
 * panjang total/sisa, tanggal alokasi/pakai, kode garansi terkait) --
 * memang bukan "serial number" gaya barang elektronik per unit, tapi
 * fungsinya identik: identitas unik & bisa dilacak per roll.
 *
 * Filter tanggal berdasarkan allocated_at (kapan roll ini mulai
 * dipakai/dialokasikan ke toko) -- roll yang belum pernah dialokasikan
 * (status='unallocated', allocated_at NULL) tidak match filter apa pun
 * kecuali "Semua Status" tanpa filter tanggal (lihat toggle di form).
 */
class SerialNumberReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?string $navigationGroup = 'Laporan Persediaan';

    protected static ?string $navigationLabel = 'Laporan Serial Number';

    protected static ?string $title = 'Laporan Serial Number';

    // 503 -- band grup 'Laporan Persediaan' (lihat catatan sistem band
    // di ProductSalesReport.php).
    protected static ?int $navigationSort = 503;

    protected static string $view = 'filament.pages.serial-number-report';

    public ?array $data = [];

    // #[Url] (audit 2026-09-11, temuan D) — pola sama laporan Penjualan
    // lain.
    #[Url(as: 'from')]
    public ?string $from = null;

    #[Url(as: 'to')]
    public ?string $to = null;

    #[Url(as: 'status')]
    public ?string $statusFilter = null;

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

        // "Sampai" < "Dari" via URL diutak-atik manual (audit Serial Number 2026-09-29):
        // dikoreksi diam-diam di sini, sama pola dengan laporan Penjualan lain.
        if (Carbon::parse($this->to)->lt(Carbon::parse($this->from))) {
            $this->to = $this->from;
        }

        if (! in_array($this->statusFilter, ['unallocated', 'allocated', 'used'], true)) {
            $this->statusFilter = null;
        }

        // Filter cabang (audit 2026-09-29, sejajar laporan Penjualan lain) -- staff toko TIDAK
        // PERNAH boleh pilih cabang lain, URL yang tidak sah diabaikan.
        if (! (auth()->user()?->isFullAccess() ?? false)) {
            $this->storeId = null;
        }

        $this->form->fill([
            'from' => $this->from,
            'to' => $this->to,
            'status' => $this->statusFilter,
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
            'status' => $this->statusFilter = $value ?: null,
            'store_id' => $this->storeId = $value ? (int) $value : null,
            default => null,
        };

        // "Sampai" sebelum "Dari" (audit Serial Number 2026-09-29): sebelumnya diam-diam
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
            Select::make('status')
                ->label('Status')
                ->options(['unallocated' => 'Belum Dialokasikan', 'allocated' => 'Dialokasikan', 'used' => 'Habis Dipakai'])
                ->placeholder('Semua Status')
                ->live(),

            // Filter cabang (audit 2026-09-29) -- cuma untuk full-access, sama pola dengan
            // laporan Penjualan lain.
            Select::make('store_id')
                ->label('Cabang')
                ->placeholder('Semua cabang')
                ->options(fn () => Store::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->visible($isFullAccess)
                ->live(),
        ])->columns($isFullAccess ? 5 : 4)->statePath('data');
    }

    /** Link drill-down ke halaman detail kode serial (roll). */
    public function scrollCodeUrl(int $id): string
    {
        return \App\Filament\Resources\ScrollCodeResource::getUrl('view', ['record' => $id]);
    }

    /** Log ekspor (audit Serial Number 2026-09-29), konsisten dengan laporan lain. */
    private function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => 'serial_number', 'format' => $format, 'from' => $this->data['from'] ?? null, 'to' => $this->data['to'] ?? null, 'status' => $this->data['status'] ?? null, 'store_id' => $this->data['store_id'] ?? null])
                ->log('Ekspor Laporan Serial Number (' . $format . ')');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * "Ekspor Laporan" (audit 2026-09-11, temuan B) — pola sama laporan
     * Penjualan lain.
     */
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
                        new SerialNumberReportExport($this->getResult()),
                        'laporan-serial-number-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $this->logExport('pdf');

                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.serial_number_report', ['result' => $result])->setPaper('a4', 'landscape');
                    $filename = 'laporan-serial-number-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();
        $user = auth()->user();
        $isFullAccess = $user?->isFullAccess() ?? false;
        // GAP DIPERBAIKI 2026-09-29: full-access sebelumnya tidak bisa mempersempit ke 1 cabang --
        // sekarang filter 'store_id' di form dipakai kalau full-access memilihnya.
        $storeId = $isFullAccess ? ($this->data['store_id'] ?? null) : $user?->store_id;

        $codes = ScrollCode::query()
            ->with(['filmProduct:id,sku,name', 'store:id,name'])
            ->whereBetween('allocated_at', [$from, $to])
            ->when($this->data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->orderByDesc('allocated_at')
            ->get();

        // "Jenis Transaksi"/"Tanggal" Majoo -- levelnya per PEMAKAIAN
        // (bukan per kode roll), dari ScrollCodeUsage (dicatat tiap kali
        // roll dipakai instalasi, lihat ScrollCode::recordUsage()).
        // "No Transaksi" TIDAK ditampilkan -- tidak ada field referensi
        // nomor transaksi tersimpan di sini. "Stok"/"Stok Akhir" Majoo
        // (saldo SEBELUM/SESUDAH pemakaian itu) JUGA tidak ditampilkan --
        // ScrollCodeUsage cuma simpan `meters` yang dipakai saat itu,
        // bukan snapshot saldo sebelum/sesudah, jadi diganti kolom
        // "Meter Dipakai" (apa yang genuinely tersimpan).
        $usages = ScrollCodeUsage::query()
            ->with(['scrollCode:id,code,store_id', 'scrollCode.store:id,name', 'user:id,name'])
            ->whereBetween('created_at', [$from, $to])
            ->when($storeId, fn ($q) => $q->whereHas('scrollCode', fn ($q2) => $q2->where('store_id', $storeId)))
            ->orderByDesc('created_at')
            ->get();

        return [
            'from' => $from,
            'to' => $to,
            'codes' => $codes,
            'usages' => $usages,
            'totalCount' => $codes->count(),
            'usedCount' => $codes->where('status', 'used')->count(),
            'totalRemainingMeters' => (float) $codes->sum('remaining_length_meters'),
            'totalMetersUsed' => (float) $usages->sum('meters'),
        ];
    }
}
