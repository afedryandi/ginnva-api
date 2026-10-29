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
    use \App\Filament\Pages\Concerns\ExportsReportTable;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'Pengaturan Karyawan';

    protected static ?string $navigationLabel = 'Kelengkapan Data Karyawan';

    protected static ?string $title = 'Kelengkapan Data Karyawan';

    protected static ?int $navigationSort = 603;

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

    protected function getHeaderActions(): array
    {
        return $this->exportActions();
    }

    protected function reportExportData(): array
    {
        $storeId = $this->data['store_id'] ?? null;
        $storeName = $storeId ? Store::whereKey($storeId)->value('name') : null;

        return [
            'key' => 'kelengkapan-data-karyawan',
            'title' => 'Kelengkapan Data Karyawan',
            'subtitle' => 'Per ' . now()->translatedFormat('d F Y') . ' · ' . ($storeName ? "Toko {$storeName}" : 'Semua toko'),
            'headings' => ['Karyawan', 'Toko', 'Wajib dilengkapi', 'Disarankan'],
            'rows' => $this->getFindings()->map(fn (array $f) => [
                $f['user']->name,
                $f['user']->store?->name ?? '—',
                implode('; ', $f['required']) ?: '—',
                implode('; ', $f['recommended']) ?: '—',
            ])->all(),
            'note' => 'Hanya karyawan aktif yang punya temuan. "Wajib" mempengaruhi gaji dan absensi; "Disarankan" adalah data kepegawaian formal.',
        ];
    }

    public function getFindings(): \Illuminate\Support\Collection
    {
        $storeId = $this->data['store_id'] ?? null;

        return app(EmployeeDataCompletenessService::class)->findings($storeId ? (int) $storeId : null);
    }
}
