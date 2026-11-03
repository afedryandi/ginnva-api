<?php

namespace App\Filament\Pages;

use App\Exports\StockCardReportExport;
use App\Services\StockCardService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Facades\Excel;

/**
 * "Daftar Stok" / Kartu Stok — diminta 2026-09-10, analog menu Majoo
 * "Kelola Stok > Daftar Stok". Satu tabel: Awal · Masuk · Keluar ·
 * Akhir per bahan baku & barang habis pakai untuk 1 rentang tanggal.
 *
 * "Akhir" direkonstruksi dari current_stock dikurangi net movement
 * SETELAH $to; "Awal" = Akhir dikurangi net movement DI DALAM rentang.
 * (Sistem tidak menyimpan snapshot stok harian — pola sama dengan
 * StockTurnoverReport.) "Masuk" = movement type 'in' + penyesuaian
 * positif; "Keluar" = type 'out' + |penyesuaian negatif|.
 *
 * Perhitungan ada di StockCardService (agregasi SQL, dipakai juga export, PDF, dan mobile). Audit 2026-11-03: akses eksplisit, baris
 * total, pencarian/filter kategori/hanya yang bergerak/urutan, penanda stok menipis/nonaktif/tidak sinkron, rincian mutasi per barang.
 *
 * Stok masih NASIONAL (belum per-cabang) — kolom per-cabang menyusul
 * kalau keputusan model stok sudah turun (lihat dokumen keputusan).
 */
class StockCardReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $cluster = \App\Filament\Clusters\InventarisCluster::class;

    protected static ?string $navigationGroup = 'Kelola Stok';

    protected static ?int $navigationSort = 110;

    protected static ?string $navigationLabel = 'Daftar Stok';

    protected static ?string $title = 'Daftar Stok (Kartu Stok)';

    protected static string $view = 'filament.pages.stock-card-report';

    public ?array $data = [];

    // #[Url] (audit 2026-09-14, temuan D) — pola sama laporan Penjualan
    // lain.
    #[Url(as: 'from')]
    public ?string $from = null;

    #[Url(as: 'to')]
    public ?string $to = null;

    #[Url(as: 'jenis')]
    public ?string $jenisFilter = null;

    /** Jumlah baris yang ditampilkan di layar (export selalu memuat semuanya). */
    public int $limit = 50;

    /** Rincian mutasi yang sedang dibuka: ['source' => ..., 'id' => ...]. */
    public ?array $detailKey = null;

    private ?array $resultCache = null;

    /** Eksplisit (bukan akses bawaan): akun dengan menu kosong tidak ikut (audit Daftar Stok 2026-11-03). */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasExplicitMenuAccess(static::class);
    }

    public function mount(): void
    {
        $this->from = $this->queryDateOrDefault($this->from, now()->startOfMonth());
        $this->to = $this->queryDateOrDefault($this->to, now()->endOfMonth());

        // "Sampai" < "Dari" via URL diutak-atik manual (audit Daftar Stok 2026-09-30): dikoreksi
        // diam-diam di sini, sama pola dengan laporan Penjualan lain.
        if (Carbon::parse($this->to)->lt(Carbon::parse($this->from))) {
            $this->to = $this->from;
        }

        if (! in_array($this->jenisFilter, StockCardService::JENIS, true)) {
            $this->jenisFilter = 'all';
        }

        $this->form->fill([
            'from' => $this->from,
            'to' => $this->to,
            'jenis' => $this->jenisFilter,
            'search' => null,
            'category' => null,
            'only_moved' => false,
            'sort' => 'name',
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
            'jenis' => $this->jenisFilter = $value,
            default => null,
        };

        // Filter berubah: mulai lagi dari halaman pertama dan tutup rincian.
        if (in_array($key, ['from', 'to', 'jenis', 'search', 'category', 'only_moved', 'sort', 'preset'], true)) {
            $this->limit = 50;
            $this->detailKey = null;
            $this->resultCache = null;
        }

        // "Sampai" sebelum "Dari" (audit Daftar Stok 2026-09-30): sebelumnya diam-diam
        // menghasilkan hasil kosong tanpa penjelasan -- dikoreksi + diberi tahu, sama pola dengan
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

    /** Link ke halaman Lihat bahan/barang, hanya bila akun ini boleh membukanya (tanpa itu berujung 403). */
    public function itemUrl(array $row): ?string
    {
        if ($row['source'] === 'raw_material') {
            return \App\Filament\Resources\RawMaterialResource::canViewAny()
                ? \App\Filament\Resources\RawMaterialResource::getUrl('view', ['record' => $row['id']])
                : null;
        }

        return \App\Filament\Resources\ConsumableItemResource::canViewAny()
            ? \App\Filament\Resources\ConsumableItemResource::getUrl('view', ['record' => $row['id']])
            : null;
    }

    public function showMore(): void
    {
        $this->limit += 50;
    }

    public function openDetail(string $source, int $id): void
    {
        $this->detailKey = in_array($source, ['raw_material', 'consumable_item'], true) ? ['source' => $source, 'id' => $id] : null;
    }

    public function closeDetail(): void
    {
        $this->detailKey = null;
    }

    public function getDetail(): ?array
    {
        if ($this->detailKey === null) {
            return null;
        }

        return app(StockCardService::class)->detail(
            $this->detailKey['source'],
            (int) $this->detailKey['id'],
            Carbon::parse($this->data['from'] ?? now()->startOfMonth())->startOfDay(),
            Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay()
        );
    }

    /** Log ekspor (audit Daftar Stok 2026-09-30), konsisten dengan laporan lain. */
    private function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => 'stock_card', 'format' => $format, 'from' => $this->data['from'] ?? null, 'to' => $this->data['to'] ?? null, 'jenis' => $this->data['jenis'] ?? null])
                ->log('Export Daftar Stok (' . $format . ')');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * "Ekspor Laporan" (audit 2026-09-14, temuan B) — pola sama laporan
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
                        new StockCardReportExport($this->getResult()),
                        'daftar-stok-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $this->logExport('pdf');

                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.stock_card_report', ['result' => $result])->setPaper('a4', 'landscape');
                    $filename = 'daftar-stok-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            // Periode Cepat (audit 2026-09-30, sejajar laporan Penjualan lain).
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
                        $this->resultCache = null;
                    }
                }),

            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),
            Select::make('jenis')
                ->label('Jenis')
                ->options([
                    'all' => 'Semua',
                    'raw_material' => 'Bahan Baku',
                    'consumable_item' => 'Barang Habis Pakai',
                ])
                ->default('all')
                ->selectablePlaceholder(false)
                ->live(),

            TextInput::make('search')
                ->label('Cari')
                ->placeholder('Nama atau kode barang')
                ->live(debounce: 500),

            Select::make('category')
                ->label('Kategori')
                ->options(fn () => app(StockCardService::class)->categories())
                ->searchable()
                ->placeholder('Semua kategori')
                ->live(),

            Select::make('sort')
                ->label('Urutan')
                ->options(StockCardService::SORTS)
                ->default('name')
                ->selectablePlaceholder(false)
                ->live(),

            Toggle::make('only_moved')
                ->label('Hanya yang bergerak di periode')
                ->live()
                ->inline(false),
        ])->columns(4)->statePath('data');
    }

    public function getResult(): array
    {
        if ($this->resultCache !== null) {
            return $this->resultCache;
        }

        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();
        $jenis = in_array($this->data['jenis'] ?? 'all', StockCardService::JENIS, true) ? ($this->data['jenis'] ?? 'all') : 'all';
        $sort = array_key_exists($this->data['sort'] ?? 'name', StockCardService::SORTS) ? ($this->data['sort'] ?? 'name') : 'name';

        return $this->resultCache = app(StockCardService::class)->build(
            $from,
            $to,
            $jenis,
            $this->data['search'] ?? null,
            $this->data['category'] ?? null,
            (bool) ($this->data['only_moved'] ?? false),
            $sort
        );
    }
}
