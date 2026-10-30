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

    /**
     * Kategori menentukan jenis, nama, dan akun setiap komponen di slip gaji semua orang, jadi tidak terbuka lewat akses bawaan akun baru:
     * hanya akses penuh atau staf yang menunya dicentang eksplisit. Membuat, mengubah, dan menghapus butuh izin aksi eksplisit.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasExplicitMenuAccess(static::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny() && (auth()->user()?->hasModuleAction(static::class, 'create', false) ?? false);
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny() && (auth()->user()?->hasModuleAction(static::class, 'update', false) ?? false);
    }

    public static function canDelete($record): bool
    {
        return ! $record->items()->exists()
            && static::canViewAny()
            && (auth()->user()?->hasModuleAction(static::class, 'delete', false) ?? false);
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
                        ->visible(fn () => (bool) config('features.finance'))
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
                    ->visible(fn () => (bool) config('features.finance'))
                    ->formatStateUsing(fn ($state, PayrollItemCategory $record) => $record->account ? "{$record->account->code} - {$record->account->name}" : '— belum diatur')
                    ->color(fn (PayrollItemCategory $record) => $record->account ? null : 'danger'),
                Tables\Columns\IconColumn::make('is_recurring_default')->label('Disalin bulan depan')->boolean(),
                Tables\Columns\TextColumn::make('items_count')
                    ->label('Dipakai')
                    ->suffix(' komponen')
                    ->description(fn (PayrollItemCategory $record) => $record->items_count > 0 ? 'Tidak bisa dihapus, cukup nonaktifkan' : null),
                // Saklar langsung di daftar: kategori nonaktif tidak bisa dipilih untuk komponen baru, slip lama tidak berubah.
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Aktif')
                    ->disabled(fn (PayrollItemCategory $record) => ! static::canEdit($record)),
            ])
            ->groups([
                Tables\Grouping\Group::make('type')
                    ->label('Jenis')
                    ->getTitleFromRecordUsing(fn (PayrollItemCategory $record) => PayrollItemCategory::TYPES[$record->type] ?? $record->type)
                    // 'deduction' mengurut sebelum 'earning' secara abjad; dibalik agar Tambahan tampil lebih dulu.
                    ->orderQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query, string $direction) => $query->orderBy('type', $direction === 'asc' ? 'desc' : 'asc')),
            ])
            ->defaultGroup('type')
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label('Jenis')->options(PayrollItemCategory::TYPES),
                Tables\Filters\TernaryFilter::make('is_active')->label('Status')->trueLabel('Aktif')->falseLabel('Nonaktif'),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with('account')->withCount('items');
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
