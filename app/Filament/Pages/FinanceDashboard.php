<?php

namespace App\Filament\Pages;

use App\Models\Store;
use App\Services\FinanceDashboardService;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/**
 * "Dasbor Keuangan" (Keuangan Fase 6, 2026-11-09): kas, laba bulan ini, piutang/hutang, dan hal yang perlu ditindak dalam satu layar.
 */
class FinanceDashboard extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    protected static ?string $navigationGroup = 'Laporan Keuangan';

    protected static ?string $navigationLabel = 'Dasbor Keuangan';

    protected static ?string $title = 'Dasbor Keuangan';

    protected static ?int $navigationSort = 299;

    protected static string $view = 'filament.pages.finance-dashboard';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        if (! config('features.finance')) {
            return false;
        }

        $user = auth()->user();

        return ($user?->isFullAccess() ?? false)
            || ($user?->canAccessStaffArea()
                && $user->hasMenuAccess(static::class)
                && $user->hasModuleAction(static::class, 'view', false));
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
                ->placeholder('Seluruh perusahaan')
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

        return app(FinanceDashboardService::class)->summary($storeId ? (int) $storeId : null);
    }
}
