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

        if (! in_array($this->statusFilter, ['unallocated', 'allocated', 'used'], true)) {
            $this->statusFilter = null;
        }

        $this->form->fill([
            'from' => $this->from,
            'to' => $this->to,
            'status' => $this->statusFilter,
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
            default => null,
        };
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),
            Select::make('status')
                ->label('Status')
                ->options(['unallocated' => 'Belum Dialokasikan', 'allocated' => 'Dialokasikan', 'used' => 'Habis Dipakai'])
                ->placeholder('Semua Status')
                ->live(),
        ])->columns(3)->statePath('data');
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
                ->action(fn () => Excel::download(
                    new SerialNumberReportExport($this->getResult()),
                    'laporan-serial-number-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
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

        $codes = ScrollCode::query()
            ->with(['filmProduct:id,sku,name', 'store:id,name'])
            ->whereBetween('allocated_at', [$from, $to])
            ->when($this->data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(! $isFullAccess, fn ($q) => $q->where('store_id', $user?->store_id))
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
            ->when(! $isFullAccess, fn ($q) => $q->whereHas('scrollCode', fn ($q2) => $q2->where('store_id', $user?->store_id)))
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
