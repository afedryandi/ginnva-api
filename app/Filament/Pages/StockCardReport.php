<?php

namespace App\Filament\Pages;

use App\Models\ConsumableItem;
use App\Models\RawMaterial;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

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
            'jenis' => 'all',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
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
        ])->columns(3)->statePath('data');
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
                ->each(fn (RawMaterial $item) => $rows->push($this->computeCard($item, 'Bahan Baku', $from, $to)));
        }

        if ($jenis === 'all' || $jenis === 'consumable_item') {
            ConsumableItem::query()
                ->with(['movements' => fn ($q) => $q->where('created_at', '>=', $from)])
                ->orderBy('name')
                ->get()
                ->each(fn (ConsumableItem $item) => $rows->push($this->computeCard($item, 'Barang Habis Pakai', $from, $to)));
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
    private function computeCard($item, string $jenis, Carbon $from, Carbon $to): array
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
