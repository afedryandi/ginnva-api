<?php

namespace App\Filament\Pages;

use App\Models\Store;
use App\Services\LedgerReconciliationService;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * "Rekonsiliasi Sub-Buku" (Keuangan Fase 0, 2026-11-04): membandingkan saldo akun kontrol Buku Besar dengan total sub-buku (Piutang,
 * Hutang, DP, Aset Tetap, Persediaan). Hanya laporan; selisih perlu dijelaskan akuntan. Terbatas full-access; yang lain lewat Hak Akses
 * Detail 'view' seperti laporan keuangan lain.
 */
class LedgerReconciliationReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    protected static ?string $navigationGroup = 'Jurnal & Akun';

    protected static ?string $navigationLabel = 'Rekonsiliasi Sub-Buku';

    protected static ?string $title = 'Rekonsiliasi Sub-Buku';

    protected static ?int $navigationSort = 204;

    protected static string $view = 'filament.pages.ledger-reconciliation-report';

    public ?array $data = [];

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
        $this->form->fill(['store_id' => null]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('store_id')
                ->label('Toko')
                ->placeholder('Seluruh perusahaan (termasuk persediaan)')
                ->options(fn () => Store::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->live(),
        ])->statePath('data');
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getChecks(): Collection
    {
        $storeId = $this->data['store_id'] ?? null;

        return app(LedgerReconciliationService::class)->run(now(), $storeId ? (int) $storeId : null);
    }
}
