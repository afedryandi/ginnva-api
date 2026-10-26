<?php

namespace App\Filament\Pages;

use App\Models\Store;
use App\Services\BudgetService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Anggaran vs Realisasi" (Keuangan Fase 4, 2026-11-07): anggaran tiap akun beban dibandingkan dengan realisasinya pada bulan yang dipilih.
 * Hanya laporan dan peringatan; tidak memblokir transaksi.
 */
class BudgetVsActualReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    protected static ?string $navigationGroup = 'Laporan Keuangan';

    protected static ?string $navigationLabel = 'Anggaran vs Realisasi';

    protected static ?string $title = 'Anggaran vs Realisasi';

    protected static ?int $navigationSort = 303;

    protected static string $view = 'filament.pages.budget-vs-actual-report';

    use \App\Filament\Pages\Concerns\ExportsReportTable;

    public ?array $data = [];

    protected function getHeaderActions(): array
    {
        return $this->exportActions();
    }

    protected function reportExportData(): array
    {
        $rows = $this->getRows();
        $month = ! empty($this->data['month']) ? Carbon::parse($this->data['month']) : now();
        $storeName = ! empty($this->data['store_id']) ? Store::whereKey($this->data['store_id'])->value('name') : null;
        $labels = ['lewat' => 'Melebihi anggaran', 'hampir' => 'Hampir habis', 'aman' => 'Aman', 'tanpa_anggaran' => 'Tanpa anggaran'];

        return [
            'key' => 'anggaran-vs-realisasi',
            'title' => 'Anggaran vs Realisasi',
            'subtitle' => 'Bulan ' . $month->translatedFormat('F Y') . ' · ' . ($storeName ? "Toko {$storeName}" : 'Seluruh perusahaan'),
            'headings' => ['Kode', 'Akun', 'Anggaran', 'Realisasi', 'Sisa', 'Terpakai', 'Status'],
            'rows' => $rows->map(fn (array $row) => [
                $row['account']->code,
                $row['account']->name,
                $row['budget'] > 0 ? (float) $row['budget'] : null,
                (float) $row['actual'],
                $row['budget'] > 0 ? (float) $row['remaining'] : null,
                $row['percent'] !== null ? number_format($row['percent'], 0) . '%' : null,
                $labels[$row['status']] ?? $row['status'],
            ])->all(),
            'totals' => ['', 'Total', (float) $rows->sum('budget'), (float) $rows->sum('actual'), null, null, ''],
            'right' => [2, 3, 4, 5],
            'note' => 'Realisasi dari jurnal yang sudah diposting. Hanya peringatan; transaksi tidak diblokir.',
        ];
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->isFullAccess() ?? false)
            || ($user?->canAccessStaffArea()
                && $user->hasMenuAccess(static::class)
                && $user->hasModuleAction(static::class, 'view', false));
    }

    public function mount(): void
    {
        $this->form->fill(['month' => now()->startOfMonth()->toDateString(), 'store_id' => null]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('month')->label('Bulan')->native(false)->displayFormat('F Y')->live()->maxDate(now()->endOfMonth()),
            Select::make('store_id')->label('Toko')->placeholder('Seluruh perusahaan')
                ->options(fn () => Store::where('is_active', true)->orderBy('name')->pluck('name', 'id'))->live(),
        ])->columns(2)->statePath('data');
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getRows(): Collection
    {
        $month = ! empty($this->data['month']) ? Carbon::parse($this->data['month']) : now();
        $storeId = $this->data['store_id'] ?? null;

        return app(BudgetService::class)->compare($month, $storeId ? (int) $storeId : null);
    }
}
