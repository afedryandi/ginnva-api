<?php

namespace App\Filament\Pages;

use App\Models\FinanceSetting;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * "Pengaturan Keuangan" (Keuangan Fase 4, 2026-11-07): aturan yang diisi pemilik tanpa developer. Batas nominal pengeluaran yang cukup disetujui
 * Store Manager (kosong = semua nominal naik ke direksi), dan Profil Perusahaan (nama PT, alamat, NPWP, KLU) untuk kop dan kaki invoice. Hanya akses penuh.
 *
 * Saklar FEATURE_FINANCE mati (pembukuan di Accurate 5): halaman ini tetap ada sebagai "Profil Perusahaan" di klaster Sistem, karena invoice
 * memakai profil itu; bagian persetujuan pengeluaran disembunyikan.
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

    public static function getCluster(): ?string
    {
        return config('features.finance') ? \App\Filament\Clusters\KeuanganCluster::class : \App\Filament\Clusters\SistemCluster::class;
    }

    public static function getNavigationGroup(): ?string
    {
        return config('features.finance') ? 'Jurnal & Akun' : null;
    }

    public static function getNavigationLabel(): string
    {
        return config('features.finance') ? 'Pengaturan Keuangan' : 'Profil Perusahaan';
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public static function getNavigationSort(): ?int
    {
        return config('features.finance') ? 209 : 30;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'expense_manager_limit' => FinanceSetting::get(FinanceSetting::EXPENSE_MANAGER_LIMIT),
            'company_name' => FinanceSetting::get(FinanceSetting::COMPANY_NAME),
            'company_address' => FinanceSetting::get(FinanceSetting::COMPANY_ADDRESS),
            'company_npwp' => FinanceSetting::get(FinanceSetting::COMPANY_NPWP),
            'company_klu' => FinanceSetting::get(FinanceSetting::COMPANY_KLU),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Profil perusahaan')
                ->description('Identitas badan usaha yang tercetak di invoice. Kosongkan bila belum ingin ditampilkan; nama bawaan tetap dipakai.')
                ->columns(2)
                ->schema([
                    TextInput::make('company_name')
                        ->label('Nama perusahaan')
                        ->placeholder(FinanceSetting::DEFAULT_COMPANY_NAME)
                        ->maxLength(150),
                    TextInput::make('company_npwp')
                        ->label('NPWP')
                        ->maxLength(30)
                        ->helperText('15 atau 16 angka; titik dan strip boleh.')
                        ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                            if (filled($value) && ! FinanceSetting::isValidNpwp($value)) {
                                $fail('NPWP harus 15 atau 16 angka.');
                            }
                        }),
                    Textarea::make('company_address')
                        ->label('Alamat (sesuai NPWP)')
                        ->rows(2)
                        ->maxLength(300),
                    TextInput::make('company_klu')
                        ->label('KLU (Klasifikasi Lapangan Usaha)')
                        ->maxLength(100)
                        ->helperText('Contoh: 45209, perawatan dan perbaikan mobil.'),
                ]),

            Section::make('Persetujuan pengeluaran')
                ->visible(fn () => (bool) config('features.finance'))
                ->description('Alur sekarang: staff mengajukan, Store Manager menyetujui, lalu direksi menyetujui dan pengeluaran dicatat.')
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

        $this->form->validate();

        if (config('features.finance')) {
            FinanceSetting::set(FinanceSetting::EXPENSE_MANAGER_LIMIT, $value === '' || $value === null ? null : (float) $value);
        }

        foreach ([
            FinanceSetting::COMPANY_NAME => 'company_name',
            FinanceSetting::COMPANY_ADDRESS => 'company_address',
            FinanceSetting::COMPANY_NPWP => 'company_npwp',
            FinanceSetting::COMPANY_KLU => 'company_klu',
        ] as $key => $field) {
            $text = trim((string) ($this->data[$field] ?? ''));
            FinanceSetting::set($key, $text === '' ? null : $text);
        }

        Notification::make()->title('Pengaturan disimpan')->success()->send();
    }
}
