<?php

namespace App\Filament\Pages;

use App\Models\Store;
use App\Services\HrDashboardService;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/**
 * "Dasbor HR" (Karyawan Fase 5, 2026-11-02): satu layar angka yang butuh tindakan HR (kontrak, masa percobaan, data belum lengkap,
 * peraturan belum diterima, APD jatuh tempo, sertifikat kedaluwarsa, kejadian K3 terbuka, belum dinilai).
 */
class HrDashboard extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'Data Karyawan';

    protected static ?string $navigationLabel = 'Dasbor HR';

    protected static ?string $title = 'Dasbor HR';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.hr-dashboard';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    public function mount(): void
    {
        $this->form->fill(['store_id' => auth()->user()?->isFullAccess() ? null : auth()->user()?->store_id]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('store_id')
                ->label('Toko')
                ->placeholder('Semua toko')
                ->options(fn () => Store::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->disabled(fn () => ! (auth()->user()?->isFullAccess() ?? false))
                ->live(),
        ])->statePath('data');
    }

    public function getSummary(): array
    {
        $user = auth()->user();
        $storeId = ! ($user?->isFullAccess() ?? false)
            ? $user?->store_id
            : ($this->data['store_id'] ?? null);

        return app(HrDashboardService::class)->summary($storeId ? (int) $storeId : null);
    }
}
