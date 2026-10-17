<?php

namespace App\Filament\Pages;

use App\Models\Store;
use App\Services\EmployeeDataCompletenessService;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/**
 * "Kelengkapan Data Karyawan" (Karyawan Fase 0, 2026-10-28): daftar karyawan aktif yang datanya belum lengkap, dipisah menjadi yang
 * WAJIB (mempengaruhi gaji dan absensi) dan yang DISARANKAN. Dipakai sebelum menggenerate payroll.
 */
class EmployeeDataCompletenessReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'Data Karyawan';

    protected static ?string $navigationLabel = 'Kelengkapan Data';

    protected static ?string $title = 'Kelengkapan Data Karyawan';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.employee-data-completeness';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
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
                ->placeholder('Semua toko')
                ->options(fn () => Store::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->live(),
        ])->statePath('data');
    }

    public function getFindings(): \Illuminate\Support\Collection
    {
        $storeId = $this->data['store_id'] ?? null;

        return app(EmployeeDataCompletenessService::class)->findings($storeId ? (int) $storeId : null);
    }
}
