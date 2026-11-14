<?php

namespace App\Filament\Pages;

use App\Models\FinanceSetting;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
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
            'company_npwp' => FinanceSetting::formatNpwp(FinanceSetting::get(FinanceSetting::COMPANY_NPWP)),
            'company_klu' => FinanceSetting::get(FinanceSetting::COMPANY_KLU),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Placeholder::make('last_change')
                ->hiddenLabel()
                ->content(fn () => $this->lastChangeText()),

            Section::make('Profil perusahaan')
                ->description('Identitas badan usaha yang tercetak di invoice. Kosongkan kalau belum ingin ditampilkan; nama bawaan tetap dipakai.')
                ->columns(2)
                ->schema([
                    TextInput::make('company_name')
                        ->label('Nama perusahaan')
                        ->placeholder(FinanceSetting::DEFAULT_COMPANY_NAME)
                        ->maxLength(150),
                    TextInput::make('company_npwp')
                        ->label('NPWP')
                        ->maxLength(30)
                        ->helperText('15 atau 16 angka; titik dan strip boleh. Disimpan dan dicetak dalam format baku.')
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
                        ->helperText('Pengeluaran sampai nominal ini langsung dicatat setelah Store Manager menyetujui, tanpa menunggu direksi. Kosongkan supaya semua nominal ke direksi. Kas Kecil punya batas sendiri.'),
                ]),
        ])->statePath('data');
    }

    /** Siapa dan kapan terakhir mengubah (dari Riwayat Aktivitas). */
    private function lastChangeText(): string
    {
        $last = \Spatie\Activitylog\Models\Activity::query()->where('log_name', 'finance_setting')->with('causer')->latest('id')->first();

        if (! $last) {
            return 'Belum pernah diubah. Dokumen memakai nilai bawaan.';
        }

        return 'Terakhir diubah ' . $last->created_at->timezone(\App\Support\ActivityPresenter::TIMEZONE)->format('d M Y H:i') . ' WIB oleh ' . ($last->causer?->name ?? 'sistem') . '.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Pratinjau Invoice')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->modalHeading('Pratinjau kaki invoice')
                ->modalDescription('Seperti yang tercetak di invoice, memakai isian saat ini (belum perlu disimpan).')
                ->modalContent(fn () => view('filament.pages.company-profile-preview', [
                    'profile' => [
                        'name' => trim((string) ($this->data['company_name'] ?? '')) ?: FinanceSetting::DEFAULT_COMPANY_NAME,
                        'npwp' => FinanceSetting::formatNpwp($this->data['company_npwp'] ?? null),
                        'klu' => trim((string) ($this->data['company_klu'] ?? '')) ?: null,
                        'address' => trim((string) ($this->data['company_address'] ?? '')) ?: null,
                    ],
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Tutup'),

            Action::make('history')
                ->label('Riwayat Perubahan')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->visible(fn () => \App\Filament\Resources\ActivityResource::canViewAny())
                ->url(fn () => \App\Filament\Resources\ActivityResource::getUrl('index', ['tableFilters' => ['log_name' => ['value' => 'finance_setting']]])),
        ];
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

            // NPWP disimpan dalam bentuk baku supaya tampil seragam di semua invoice.
            if ($field === 'company_npwp') {
                $text = (string) FinanceSetting::formatNpwp($text);
                $this->data[$field] = $text === '' ? null : $text;
            }

            FinanceSetting::set($key, $text === '' ? null : $text);
        }

        Notification::make()->title('Pengaturan disimpan')->success()->send();
    }
}
