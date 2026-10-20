<?php

namespace App\Filament\Pages;

use App\Models\FinanceSetting;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * "Pengaturan Keuangan" (Keuangan Fase 4, 2026-11-07): aturan yang diisi pemilik tanpa developer. Saat ini satu: batas nominal pengeluaran yang
 * cukup disetujui Store Manager. Kosong = keadaan sebelumnya (semua nominal naik ke direksi). Hanya akses penuh.
 */
class FinanceSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    protected static ?string $navigationGroup = 'Jurnal & Akun';

    protected static ?string $navigationLabel = 'Pengaturan Keuangan';

    protected static ?string $title = 'Pengaturan Keuangan';

    protected static ?int $navigationSort = 209;

    protected static string $view = 'filament.pages.finance-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'expense_manager_limit' => FinanceSetting::get(FinanceSetting::EXPENSE_MANAGER_LIMIT),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Persetujuan pengeluaran')
                ->description('Alur sekarang: staf mengajukan, Store Manager menyetujui, lalu direksi menyetujui dan pengeluaran dicatat.')
                ->schema([
                    TextInput::make('expense_manager_limit')
                        ->label('Batas yang cukup disetujui Store Manager')
                        ->numeric()->minValue(0)->prefix('Rp')
                        ->helperText('Pengeluaran sampai nominal ini langsung dicatat setelah Store Manager menyetujui, tanpa menunggu direksi. Kosongkan agar semua nominal tetap ke direksi. Pengeluaran dari Kas Kecil punya batas sendiri di menu Rekening Kas & Bank.'),
                ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        $value = $this->data['expense_manager_limit'] ?? null;

        FinanceSetting::set(FinanceSetting::EXPENSE_MANAGER_LIMIT, $value === '' || $value === null ? null : (float) $value);

        Notification::make()->title('Pengaturan disimpan')->success()->send();
    }
}
