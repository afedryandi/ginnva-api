<?php

namespace App\Filament\Pages;

use App\Models\RawMaterialBatch;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * "Laporan Stok Kedaluwarsa" — diminta 2026-09-09, analog Majoo. Data
 * yang sama dipakai MaterialsNeedingAttentionWidget (RawMaterialBatch.
 * expiry_date), TAPI widget itu cuma tampilkan yang mendekati/sudah
 * kedaluwarsa dalam 30 hari ke depan (untuk operasional harian) --
 * halaman ini laporan BERDIRI SENDIRI dengan rentang tanggal BEBAS
 * (bisa lihat histori kedaluwarsa bulan lalu, atau proyeksi jauh ke
 * depan), cuma batch yang MASIH ADA STOKNYA (quantity > 0) yang
 * dihitung -- batch yang sudah habis dipakai tidak relevan lagi.
 */
class ExpiringStockReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?string $navigationGroup = 'Laporan Persediaan';

    protected static ?string $navigationLabel = 'Laporan Stok Kedaluwarsa';

    protected static ?string $title = 'Laporan Stok Kedaluwarsa';

    // 502 -- band grup 'Laporan Persediaan' (lihat catatan sistem band
    // di ProductSalesReport.php).
    protected static ?int $navigationSort = 502;

    protected static string $view = 'filament.pages.expiring-stock-report';

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
            'from' => now()->toDateString(),
            'to' => now()->addDays(30)->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari')->native(false)->required()->live()
                ->helperText('Filter berdasarkan tanggal kedaluwarsa batch, bukan tanggal hari ini.'),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),
        ])->columns(2)->statePath('data');
    }

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now())->startOfDay();
        $to = Carbon::parse($this->data['to'] ?? now()->addDays(30))->endOfDay();
        $today = now()->startOfDay();

        $batches = RawMaterialBatch::query()
            ->with('rawMaterial:id,name,code,unit,category')
            ->where('quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('expiry_date')
            ->get();

        $expired = $batches->filter(fn (RawMaterialBatch $b) => $b->expiry_date->lt($today));
        $nearExpiry = $batches->filter(fn (RawMaterialBatch $b) => $b->expiry_date->gte($today));

        return [
            'from' => $from,
            'to' => $to,
            'batches' => $batches,
            'expiredCount' => $expired->count(),
            'nearExpiryCount' => $nearExpiry->count(),
            'totalValue' => (float) $batches->sum(fn (RawMaterialBatch $b) => (float) $b->quantity * (float) ($b->unit_cost ?? 0)),
        ];
    }
}
