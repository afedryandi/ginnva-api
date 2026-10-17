<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PayrollItemCategoryResource\Pages;
use App\Models\ChartOfAccount;
use App\Models\PayrollItemCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Kategori Komponen Gaji (Karyawan Fase 1, 2026-10-29): daftar jenis tambahan dan potongan di slip gaji beserta akun pembukuannya. Akuntan
 * dapat mengganti akun, menambah kategori, atau menonaktifkannya tanpa developer. Kategori yang sudah dipakai komponen tidak bisa dihapus
 * (cukup dinonaktifkan).
 */
class PayrollItemCategoryResource extends Resource
{
    protected static ?string $model = PayrollItemCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'Penggajian';

    protected static ?string $navigationLabel = 'Kategori Komponen Gaji';

    protected static ?string $modelLabel = 'Kategori Komponen';

    protected static ?string $pluralModelLabel = 'Kategori Komponen Gaji';

    protected static ?int $navigationSort = 420;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function canCreate(): bool
    {
        return static::canViewAny() && (auth()->user()?->hasModuleAction(static::class, 'create', true) ?? false);
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny() && (auth()->user()?->hasModuleAction(static::class, 'update', true) ?? false);
    }

    public static function canDelete($record): bool
    {
        return ! $record->items()->exists()
            && static::canViewAny()
            && (auth()->user()?->hasModuleAction(static::class, 'delete', true) ?? false);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Kategori')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Nama')->required()->maxLength(120),

                    Forms\Components\TextInput::make('code')
                        ->label('Kode')
                        ->required()
                        ->maxLength(40)
                        ->alphaDash()
                        ->unique(ignoreRecord: true)
                        ->disabled(fn (?PayrollItemCategory $record) => $record !== null)
                        ->helperText('Huruf kecil dan garis bawah, tidak bisa diubah setelah dibuat. Mis. tunjangan_makan.'),

                    Forms\Components\Select::make('type')
                        ->label('Jenis')
                        ->options(PayrollItemCategory::TYPES)
                        ->required()
                        ->native(false)
                        ->disabled(fn (?PayrollItemCategory $record) => $record?->items()->exists() ?? false)
                        ->helperText('Tambahan menambah gaji bersih, potongan menguranginya. Tidak bisa diubah setelah dipakai.'),

                    Forms\Components\Select::make('chart_of_account_id')
                        ->label('Akun Pembukuan')
                        ->options(fn () => ChartOfAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->id => "{$a->code} - {$a->name}"]))
                        ->searchable()
                        ->required()
                        ->helperText('Tambahan: akun beban yang didebit. Potongan: akun yang dikredit (mis. Hutang BPJS, Hutang Pajak, Piutang Karyawan). Diatur akuntan.'),

                    Forms\Components\Toggle::make('is_recurring_default')
                        ->label('Biasanya ikut disalin ke bulan berikutnya')
                        ->helperText('Bawaan untuk komponen baru, mis. tunjangan tetap dan BPJS. Tetap bisa diubah di tiap komponen.'),

                    Forms\Components\TextInput::make('sort_order')->label('Urutan')->numeric()->integer()->default(100)->minValue(0),

                    Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => PayrollItemCategory::TYPES[$state] ?? $state)
                    ->color(fn (string $state) => $state === PayrollItemCategory::EARNING ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('account.code')
                    ->label('Akun')
                    ->formatStateUsing(fn ($state, PayrollItemCategory $record) => $record->account ? "{$record->account->code} - {$record->account->name}" : '— belum diatur')
                    ->color(fn (PayrollItemCategory $record) => $record->account ? null : 'danger'),
                Tables\Columns\IconColumn::make('is_recurring_default')->label('Disalin bulan depan')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with('account');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayrollItemCategories::route('/'),
            'create' => Pages\CreatePayrollItemCategory::route('/create'),
            'edit' => Pages\EditPayrollItemCategory::route('/{record}/edit'),
        ];
    }
}
