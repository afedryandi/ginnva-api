<?php

namespace App\Filament\Pages;

use App\Exports\PersediaanDetailReportExport;
use App\Models\ConsumableItemMovement;
use App\Models\RawMaterialMovement;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Facades\Excel;

/**
 * "Lap. Detail Persediaan" — diminta 2026-09-09, halaman SENDIRI (bukan
 * shortcut/redirect). Rincian pergerakan stok (masuk/keluar/adjustment)
 * Bahan Baku & Barang Habis Pakai dalam 1 rentang tanggal, langsung dari
 * RawMaterialMovement/ConsumableItemMovement (sumber yang sama dipakai
 * RelationManager "Riwayat" di masing-masing resource) -- tidak ada
 * tabel/kolom baru.
 */
class PersediaanDetailReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?string $navigationGroup = 'Laporan Persediaan';

    protected static ?string $navigationLabel = 'Lap. Detail Persediaan';

    protected static ?string $title = 'Detail Persediaan';

    // 501 -- band grup 'Laporan Persediaan' (lihat catatan sistem band
    // di ProductSalesReport.php).
    protected static ?int $navigationSort = 501;

    protected static string $view = 'filament.pages.persediaan-detail-report';

    public ?array $data = [];

    // #[Url] (audit 2026-09-11, temuan D) — pola sama laporan Penjualan
    // lain.
    #[Url(as: 'from')]
    public ?string $from = null;

    #[Url(as: 'to')]
    public ?string $to = null;

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

        $this->form->fill([
            'from' => $this->from,
            'to' => $this->to,
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
            default => null,
        };
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),
        ])->columns(2)->statePath('data');
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
                    new PersediaanDetailReportExport($this->getResult()),
                    'detail-persediaan-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.persediaan_detail_report', ['result' => $result])->setPaper('a4', 'landscape');
                    $filename = 'detail-persediaan-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();

        $materialMovements = RawMaterialMovement::query()
            ->with(['rawMaterial:id,name,unit', 'user:id,name'])
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->get();

        $consumableMovements = ConsumableItemMovement::query()
            ->with(['consumableItem:id,name,unit', 'user:id,name'])
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->get();

        return [
            'from' => $from,
            'to' => $to,
            'materialMovements' => $materialMovements,
            'consumableMovements' => $consumableMovements,
            'materialInCount' => $materialMovements->where('type', 'in')->count(),
            'materialOutCount' => $materialMovements->where('type', 'out')->count(),
            'consumableInCount' => $consumableMovements->where('type', 'in')->count(),
            'consumableOutCount' => $consumableMovements->where('type', 'out')->count(),
        ];
    }
}
