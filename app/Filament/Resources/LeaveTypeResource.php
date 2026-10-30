<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LeaveTypeResource\Pages;
use App\Models\LeaveType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Jenis Izin & Cuti (Karyawan Fase 2, 2026-10-30): daftar jenis yang bisa dipilih karyawan beserta batas hari, syarat lampiran (mis. surat
 * dokter), dan apakah memotong jatah cuti tahunan. Angka bawaan hanya titik awal dan harus disesuaikan dengan Peraturan Perusahaan.
 * Jenis yang sudah dipakai pengajuan tidak bisa dihapus (cukup dinonaktifkan).
 */
class LeaveTypeResource extends Resource
{
    protected static ?string $model = LeaveType::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'Pengaturan Karyawan';

    protected static ?string $navigationLabel = 'Jenis Izin & Cuti';

    protected static ?string $modelLabel = 'Jenis Izin/Cuti';

    protected static ?string $pluralModelLabel = 'Jenis Izin & Cuti';

    protected static ?int $navigationSort = 602;

    /**
     * Jenis menentukan batas hari, syarat lampiran, dan pemotongan jatah cuti SEMUA karyawan, jadi tidak terbuka lewat akses bawaan akun
     * baru: hanya akses penuh atau staf yang menunya dicentang eksplisit. Membuat, mengubah, dan menghapus butuh izin aksi eksplisit.
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
        return ! $record->requests()->exists()
            && ! $record->isBuiltIn()
            && static::canViewAny()
            && (auth()->user()?->hasModuleAction(static::class, 'delete', false) ?? false);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Jenis')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Nama')->required()->maxLength(120),

                    Forms\Components\TextInput::make('code')
                        ->label('Kode')
                        ->required()
                        ->maxLength(40)
                        ->alphaDash()
                        ->unique(ignoreRecord: true)
                        ->disabled(fn (?LeaveType $record) => $record !== null)
                        ->helperText('Huruf kecil dan garis bawah, tidak bisa diubah setelah dibuat. Mis. cuti_umroh.'),

                    Forms\Components\Select::make('category')
                        ->label('Kategori')
                        ->options(LeaveType::CATEGORIES)
                        ->required()
                        ->native(false)
                        ->disabled(fn (?LeaveType $record) => $record?->requests()->exists() ?? false)
                        ->helperText('Dipakai laporan dan absensi. Tidak bisa diubah setelah dipakai pengajuan.'),

                    Forms\Components\TextInput::make('sort_order')->label('Urutan')->numeric()->integer()->default(100)->minValue(0),
                ]),

            Forms\Components\Section::make('Aturan')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('deducts_annual_quota')
                        ->label('Memotong jatah cuti tahunan')
                        ->disabled(fn (?LeaveType $record) => $record?->requests()->exists() ?? false)
                        ->helperText(fn (?LeaveType $record) => ($record?->requests()->exists() ?? false)
                            ? 'Terkunci karena jenis ini sudah dipakai pengajuan: mengubahnya akan menulis ulang pemakaian jatah semua karyawan. Buat jenis baru bila perlu.'
                            : 'Aktifkan hanya untuk Cuti Tahunan. Cuti khusus (menikah, melahirkan, dst.) tidak memotong jatah.')
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('max_days')
                        ->label('Maksimal hari per pengajuan')
                        ->numeric()->integer()->minValue(1)->maxValue(365)
                        ->helperText('Kosongkan bila tidak ada batas khusus (batas umum 30 hari tetap berlaku).'),

                    Forms\Components\TextInput::make('document_required_from_days')
                        ->label('Wajib lampiran mulai (hari)')
                        ->numeric()->integer()->minValue(1)->maxValue(365)
                        ->helperText('Mis. 2 = pengajuan 2 hari atau lebih wajib melampirkan surat dokter. 1 = selalu wajib. Kosongkan bila lampiran tidak wajib.'),

                    Forms\Components\Textarea::make('notes')->label('Keterangan untuk karyawan')->rows(2)->columnSpanFull(),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktif')
                        ->default(true)
                        ->disabled(fn (?LeaveType $record) => $record?->isBaseType() ?? false)
                        ->helperText(fn (?LeaveType $record) => ($record?->isBaseType() ?? false)
                            ? 'Jenis dasar (Izin, Sakit, Cuti Tahunan) tidak bisa dinonaktifkan.'
                            : 'Jenis nonaktif tidak bisa dipilih untuk pengajuan baru; pengajuan yang sudah ada tidak berubah.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable(),
                Tables\Columns\TextColumn::make('category')
                    ->label('Kategori')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => LeaveType::CATEGORIES[$state] ?? $state),
                Tables\Columns\IconColumn::make('deducts_annual_quota')->label('Potong jatah tahunan')->boolean(),
                Tables\Columns\TextColumn::make('max_days')->label('Maks. hari')->placeholder('—'),
                Tables\Columns\TextColumn::make('document_required_from_days')->label('Lampiran wajib mulai')->placeholder('—')->suffix(' hari'),
                Tables\Columns\TextColumn::make('requests_count')
                    ->label('Dipakai')
                    ->suffix(' pengajuan')
                    ->tooltip(fn (LeaveType $record) => static::deleteBlockedReason($record)),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Aktif')
                    ->disabled(fn (LeaveType $record) => $record->isBaseType() || ! static::canEdit($record)),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')->label('Kategori')->options(LeaveType::CATEGORIES),
                Tables\Filters\TernaryFilter::make('is_active')->label('Status Aktif'),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->withCount('requests');
    }

    /** Alasan jenis tidak bisa dihapus, untuk tooltip di daftar; null bila boleh dihapus. */
    public static function deleteBlockedReason(LeaveType $record): ?string
    {
        if ($record->isBaseType()) {
            return 'Jenis dasar: tidak bisa dihapus atau dinonaktifkan.';
        }
        if ($record->isBuiltIn()) {
            return 'Jenis bawaan: tidak bisa dihapus, nonaktifkan bila tidak dipakai.';
        }
        if (($record->requests_count ?? $record->requests()->count()) > 0) {
            return 'Sudah dipakai pengajuan: tidak bisa dihapus, nonaktifkan saja.';
        }

        return null;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLeaveTypes::route('/'),
            'create' => Pages\CreateLeaveType::route('/create'),
            'edit' => Pages\EditLeaveType::route('/{record}/edit'),
        ];
    }
}
