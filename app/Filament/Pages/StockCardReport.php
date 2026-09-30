<?php

namespace App\Filament\Pages;

use App\Exports\StockCardReportExport;
use App\Models\ConsumableItem;
use App\Models\RawMaterial;
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

        // "Sampai" < "Dari" via URL diutak-atik manual (audit Daftar Stok 2026-09-30): dikoreksi
        // diam-diam di sini, sama pola dengan laporan Penjualan lain.
        if (Carbon::parse($this->to)->lt(Carbon::parse($this->from))) {
            $this->to = $this->from;
        }

        if (! in_array($this->jenisFilter, ['all', 'raw_material', 'consumable_item'], true)) {
            $this->jenisFilter = 'all';
        }

        $this->form->fill([
            'from' => $this->from,
            'to' => $this->to,
            'jenis' => $this->jenisFilter,
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

    /** Link drill-down ke halaman edit Bahan Baku/Barang Habis Pakai terkait. */
    public function materialUrl(int $id): string
    {
        return \App\Filament\Resources\RawMaterialResource::getUrl('edit', ['record' => $id]);
    }

    public function consumableUrl(int $id): string
    {
        return \App\Filament\Resources\ConsumableItemResource::getUrl('edit', ['record' => $id]);
    }

    /** Log ekspor (audit Daftar Stok 2026-09-30), konsisten dengan laporan lain. */
    private function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => 'stock_card', 'format' => $format, 'from' => $this->data['from'] ?? null, 'to' => $this->data['to'] ?? null, 'jenis' => $this->data['jenis'] ?? null])
                ->log('Ekspor Daftar Stok (' . $format . ')');
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
        ])->columns(4)->statePath('data');
    }

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();
        $jenis = $this->data['jenis'] ?? 'all';

        $rows = collect();

        if ($jenis === 'all' || $jenis === 'raw_material') {
            RawMaterial::query()
                ->with(['movements' => fn ($q) => $q->where('created_at', '>=', $from)])
                ->orderBy('name')
                ->get()
                ->each(fn (RawMaterial $item) => $rows->push($this->computeCard($item, 'Bahan Baku', 'raw_material', $from, $to)));
        }

        if ($jenis === 'all' || $jenis === 'consumable_item') {
            ConsumableItem::query()
                ->with(['movements' => fn ($q) => $q->where('created_at', '>=', $from)])
                ->orderBy('name')
                ->get()
                ->each(fn (ConsumableItem $item) => $rows->push($this->computeCard($item, 'Barang Habis Pakai', 'consumable_item', $from, $to)));
        }

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows->values(),
            'totals' => [
                'masuk' => $rows->sum('masuk'),
                'keluar' => $rows->sum('keluar'),
            ],
        ];
    }

    /**
     * @param  RawMaterial|ConsumableItem  $item
     */
    private function computeCard($item, string $jenis, string $source, Carbon $from, Carbon $to): array
    {
        // net movement 1 baris (signed): in / adjustment(+delta) = +qty ; out = -qty
        $net = fn ($m) => $m->type === 'out' ? -(float) $m->quantity : (float) $m->quantity;

        $netAfterTo = $item->movements->where('created_at', '>', $to)->sum($net);
        $akhir = (float) $item->current_stock - $netAfterTo;

        $inPeriod = $item->movements->whereBetween('created_at', [$from, $to]);
        $netDuring = $inPeriod->sum($net);
        $awal = $akhir - $netDuring;

        $masuk = (float) $inPeriod->where('type', 'in')->sum('quantity')
            + (float) $inPeriod->where('type', 'adjustment')->where('quantity', '>', 0)->sum('quantity');
        $keluar = (float) $inPeriod->where('type', 'out')->sum('quantity')
            + (float) $inPeriod->where('type', 'adjustment')->where('quantity', '<', 0)->sum(fn ($m) => abs((float) $m->quantity));

        return [
            'id' => $item->id,
            'source' => $source,
            'name' => $item->name,
            'code' => $item->code,
            'jenis' => $jenis,
            'unit' => $item->unit,
            'awal' => round(max(0, $awal), 2),
            'masuk' => round($masuk, 2),
            'keluar' => round($keluar, 2),
            'akhir' => round(max(0, $akhir), 2),
            'hasMovement' => $inPeriod->isNotEmpty(),
        ];
    }
}
