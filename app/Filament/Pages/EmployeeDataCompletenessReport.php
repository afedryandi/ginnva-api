<?php

namespace App\Filament\Pages;

use App\Models\Store;
use App\Services\EmployeeDataCompletenessService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
        // Data personalia seluruh karyawan: hanya akses penuh atau staf yang menunya dicentang eksplisit (bukan akun dengan akses bawaan).
        return auth()->user()?->hasExplicitMenuAccess(static::class) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(['store_id' => null, 'search' => null]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('store_id')
                ->label('Toko')
                ->placeholder('Semua toko')
                ->options(fn () => Store::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->live(),

            TextInput::make('search')
                ->label('Cari karyawan')
                ->placeholder('Nama karyawan')
                ->live(debounce: 400),
        ])->columns(2)->statePath('data');
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

        $search = trim((string) ($this->data['search'] ?? ''));

        return app(EmployeeDataCompletenessService::class)->findings($storeId ? (int) $storeId : null)
            ->when($search !== '', fn ($rows) => $rows->filter(fn (array $row) => mb_stripos($row['user']->name, $search) !== false))
            ->values();
    }

    /**
     * Prasyarat payroll yang bukan data karyawan: tanpa Aturan Potongan Absensi yang berlaku, Generate Payroll berhenti untuk semua orang.
     * Diperiksa untuk bulan lalu (bulan yang biasanya sedang digenerate). Null bila aman.
     *
     * @return array{message: string, url: ?string}|null
     */
    public function getPayrollBlocker(): ?array
    {
        $month = now()->subMonthNoOverflow()->startOfMonth();

        if (\App\Models\AttendanceDeductionRule::effectiveFor($month)) {
            return null;
        }

        $resource = \App\Filament\Resources\AttendanceDeductionRuleResource::class;

        return [
            'message' => 'Belum ada Aturan Potongan Absensi yang berlaku untuk ' . $month->translatedFormat('F Y') . ': Generate Payroll akan berhenti untuk semua karyawan, apa pun kelengkapan datanya.',
            'url' => $resource::canViewAny() ? $resource::getUrl('index') : null,
        ];
    }

    /** Hitungan karyawan per jenis masalah untuk baris ringkasan di atas daftar. */
    public function getSummary(): array
    {
        return app(EmployeeDataCompletenessService::class)->summarize($this->getFindings());
    }

    /** Tautan perbaikan ke halaman User, hanya bila akun ini boleh membukanya (halaman User khusus akses penuh). */
    public function userUrl(\App\Models\User $user): ?string
    {
        return \App\Filament\Resources\UserResource::canEdit($user) ? \App\Filament\Resources\UserResource::getUrl('edit', ['record' => $user]) : null;
    }

    /** Tautan ke Toko karyawan untuk melengkapi jam operasional, hanya bila akun ini boleh membukanya. */
    public function storeUrl(\App\Models\User $user): ?string
    {
        $store = $user->store;

        return $store && \App\Filament\Resources\StoreResource::canViewAny() && \App\Filament\Resources\StoreResource::canEdit($store) ? \App\Filament\Resources\StoreResource::getUrl('edit', ['record' => $store]) : null;
    }
}
