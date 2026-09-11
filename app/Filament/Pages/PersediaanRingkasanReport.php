<?php

namespace App\Filament\Pages;

use App\Exports\PersediaanRingkasanReportExport;
use App\Models\ConsumableItem;
use App\Models\RawMaterial;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Maatwebsite\Excel\Facades\Excel;

/**
 * "Lap. Ringkasan Persediaan" — diminta 2026-09-09. SEMPAT extends
 * InventoryDashboard (warisan widget "Perlu Perhatian"), TAPI setelah
 * user tunjukkan screenshot Majoo yang SEBENARNYA -- valuasi PER ITEM
 * (Nama, SKU, Jenis, Kategori, Kuantitas, Satuan, Harga Modal, Total
 * Nilai) -- ternyata beda konsep dari "Perlu Perhatian" (yang cuma
 * tampilkan item bermasalah, bukan valuasi keseluruhan). Dibuat ULANG
 * jadi standalone, gabungan Bahan Baku + Barang Habis Pakai (2 jenis
 * inventori Ginnva), kuantitas & harga modal SAAT INI (bukan snapshot
 * historis per tanggal -- sistem tidak menyimpan riwayat harga modal,
 * jadi "Total Nilai Persediaan" selalu mencerminkan kondisi TERKINI,
 * berapa pun tanggal yang dipilih Majoo -- makanya halaman ini TIDAK
 * ada filter tanggal sama sekali).
 */
class PersediaanRingkasanReport extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?string $navigationGroup = 'Laporan Persediaan';

    protected static ?string $navigationLabel = 'Lap. Ringkasan Persediaan';

    protected static ?string $title = 'Ringkasan Persediaan';

    // 500 -- band grup 'Laporan Persediaan' (lihat catatan sistem band
    // di ProductSalesReport.php).
    protected static ?int $navigationSort = 500;

    protected static string $view = 'filament.pages.persediaan-ringkasan-report';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    /**
     * "Ekspor Laporan" (audit 2026-09-11, temuan B) — snapshot kondisi
     * terkini (tidak ada filter di halaman ini, jadi tidak ada parameter
     * untuk dibawa ke export).
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new PersediaanRingkasanReportExport($this->getResult()),
                    'ringkasan-persediaan-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.persediaan_ringkasan_report', ['result' => $result])->setPaper('a4', 'landscape');
                    $filename = 'ringkasan-persediaan-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    public function getResult(): array
    {
        $materials = RawMaterial::query()->orderBy('name')->get()
            ->map(fn (RawMaterial $m) => [
                'name' => $m->name,
                'sku' => $m->code ?? '—',
                'type' => 'Bahan Baku',
                'category' => $m->category ?? '—',
                'quantity' => (float) $m->current_stock,
                'unit' => $m->unit,
                'unitCost' => (float) ($m->unit_cost ?? 0),
                'totalValue' => (float) $m->current_stock * (float) ($m->unit_cost ?? 0),
            ]);

        $consumables = ConsumableItem::query()->orderBy('name')->get()
            ->map(fn (ConsumableItem $c) => [
                'name' => $c->name,
                'sku' => $c->code ?? '—',
                'type' => 'Barang Habis Pakai',
                'category' => $c->category ?? '—',
                'quantity' => (float) $c->current_stock,
                'unit' => $c->unit,
                'unitCost' => (float) ($c->unit_cost ?? 0),
                'totalValue' => (float) $c->current_stock * (float) ($c->unit_cost ?? 0),
            ]);

        $rows = $materials->concat($consumables)->sortByDesc('totalValue')->values();

        return [
            'rows' => $rows,
            'totalValue' => $rows->sum('totalValue'),
        ];
    }
}
