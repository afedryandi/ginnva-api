<?php

namespace App\Filament\Pages;

use App\Models\ConsumableItem;
use App\Models\RawMaterial;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * "Perputaran Stok" — diminta 2026-09-09, analog Majoo. Formula standar
 * Inventory Turnover Ratio = Qty Keluar (pemakaian) / Rata-rata Stok,
 * dihitung per Bahan Baku & Barang Habis Pakai (digabung, 2 jenis
 * inventori Ginnva -- Majoo bedakan "Bahan"/"Produk", di Ginnva
 * analognya "Bahan Baku"/"Barang Habis Pakai" karena tidak ada konsep
 * stok "Produk" jadi terpisah) dalam 1 rentang tanggal, dari
 * RawMaterialMovement/ConsumableItemMovement (type='out').
 *
 * "Rata-rata Stok" DISEDERHANAKAN jadi (stok AWAL + stok AKHIR) / 2 --
 * stok awal dihitung mundur dari stok SAAT INI dikurangi/ditambah
 * semua movement SETELAH tanggal awal rentang (rekonstruksi, karena
 * sistem tidak menyimpan snapshot stok harian). Rasio TINGGI = bahan
 * itu cepat berputar (sering dipakai relatif terhadap stoknya) — bukan
 * "bagus"/"buruk" mutlak, tergantung karakteristik bahan.
 *
 * "Hari Terjual" = jumlah HARI BERBEDA yang punya minimal 1 pergerakan
 * keluar dalam rentang ini.
 */
class StockTurnoverReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $cluster = \App\Filament\Clusters\AnalisaLaporanCluster::class;

    protected static ?string $navigationLabel = 'Perputaran Stok';

    protected static ?string $title = 'Perputaran Stok';

    // 602 -- band grup 'Analisa Laporan' (lihat catatan sistem band di
    // ProductSalesReport.php).
    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.stock-turnover-report';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),
        ])->columns(2)->statePath('data');
    }

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();

        $materials = RawMaterial::query()
            ->with(['movements' => fn ($q) => $q->where('created_at', '>=', $from)])
            ->orderBy('name')
            ->get()
            ->map(fn (RawMaterial $item) => $this->computeTurnover($item, 'Bahan Baku', $from, $to));

        $consumables = ConsumableItem::query()
            ->with(['movements' => fn ($q) => $q->where('created_at', '>=', $from)])
            ->orderBy('name')
            ->get()
            ->map(fn (ConsumableItem $item) => $this->computeTurnover($item, 'Barang Habis Pakai', $from, $to));

        $rows = $materials->concat($consumables)
            ->filter(fn ($row) => $row['qtyOut'] > 0) // sembunyikan yang sama sekali tidak bergerak di rentang ini
            ->sortByDesc(fn ($row) => $row['turnoverRatio'] ?? -1)
            ->values();

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
        ];
    }

    /**
     * @param RawMaterial|ConsumableItem $item
     */
    private function computeTurnover($item, string $type, Carbon $from, Carbon $to): array
    {
        $qtyOutInPeriod = (float) $item->movements
            ->where('type', 'out')
            ->where('created_at', '<=', $to)
            ->sum('quantity');

        // Rekonstruksi stok di AKHIR rentang: stok SEKARANG dikurangi
        // net movement SETELAH $to.
        $netAfterTo = $item->movements->where('created_at', '>', $to)
            ->sum(fn ($m) => $m->type === 'out' ? -(float) $m->quantity : (float) $m->quantity);
        $stockAtTo = (float) $item->current_stock - $netAfterTo;

        // Stok di AWAL rentang: stok akhir rentang DITAMBAH net movement
        // DI DALAM rentang (membalik in/out selama rentang itu).
        $netDuringPeriod = $item->movements
            ->whereBetween('created_at', [$from, $to])
            ->sum(fn ($m) => $m->type === 'out' ? -(float) $m->quantity : (float) $m->quantity);
        $stockAtFrom = $stockAtTo - $netDuringPeriod;

        $avgStock = ($stockAtFrom + $stockAtTo) / 2;
        $turnoverRatio = $avgStock > 0 ? $qtyOutInPeriod / $avgStock : null;

        $daysSold = $item->movements
            ->where('type', 'out')
            ->whereBetween('created_at', [$from, $to])
            ->map(fn ($m) => $m->created_at->toDateString())
            ->unique()
            ->count();

        return [
            'item' => $item,
            'type' => $type,
            'qtyOut' => $qtyOutInPeriod,
            'stockAtFrom' => max(0, $stockAtFrom),
            'stockAtTo' => max(0, $stockAtTo),
            'avgStock' => max(0, $avgStock),
            'turnoverRatio' => $turnoverRatio,
            'daysSold' => $daysSold,
        ];
    }
}
