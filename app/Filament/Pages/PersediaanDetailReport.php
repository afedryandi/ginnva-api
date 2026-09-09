<?php

namespace App\Filament\Pages;

use App\Models\ConsumableItemMovement;
use App\Models\RawMaterialMovement;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

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
