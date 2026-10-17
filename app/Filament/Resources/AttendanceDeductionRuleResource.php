<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AttendanceDeductionRuleResource\Pages;
use App\Models\AttendanceDeductionRule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Aturan Potongan Absensi (Karyawan Fase 0, 2026-10-28): nominal potongan telat dan Alpha diisi staf yang bertanggung jawab lewat panel,
 * bukan di kode. Satu baris per tanggal berlaku (riwayat aturan terjaga); aturan yang sudah dipakai payroll tidak bisa diubah atau
 * dihapus, perubahan = baris baru dengan tanggal berlaku baru.
 */
class AttendanceDeductionRuleResource extends Resource
{
    protected static ?string $model = AttendanceDeductionRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'Penggajian';

    protected static ?string $navigationLabel = 'Aturan Potongan Absensi';

    protected static ?string $modelLabel = 'Aturan Potongan';

    protected static ?string $pluralModelLabel = 'Aturan Potongan Absensi';

    protected static ?int $navigationSort = 410;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function canCreate(): bool
    {
        return static::canViewAny()
            && (auth()->user()?->hasModuleAction(static::class, 'create', true) ?? false);
    }

    public static function canEdit($record): bool
    {
        return ! $record->isInUse()
            && static::canViewAny()
            && (auth()->user()?->hasModuleAction(static::class, 'update', true) ?? false);
    }

    public static function canDelete($record): bool
    {
        return ! $record->isInUse()
            && static::canViewAny()
            && (auth()->user()?->hasModuleAction(static::class, 'delete', true) ?? false);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Aturan')
                ->columns(2)
                ->schema([
                    Forms\Components\DatePicker::make('effective_from')
                        ->label('Berlaku Mulai')
                        ->native(false)
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->helperText('Dipakai untuk payroll bulan yang dimulai pada atau setelah tanggal ini. Isi awal bulan, misalnya 1 November.')
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('late_block_minutes')
                        ->label('Ukuran Blok Telat (menit)')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(240)
                        ->default(AttendanceDeductionRule::DEFAULT_BLOCK_MINUTES)
                        ->required()
                        ->helperText('Total menit telat sebulan dibagi ukuran ini, hanya blok PENUH yang dipotong. Contoh 15 menit: total 14 menit = tidak dipotong, 40 menit = 2 blok.'),

                    Forms\Components\TextInput::make('late_deduction_per_block')
                        ->label('Potongan per Blok Telat (Rp)')
                        ->numeric()
                        ->prefix('Rp')
                        ->minValue(0)
                        ->maxValue(999999999)
                        ->default(0)
                        ->required()
                        ->helperText('Nominal untuk setiap blok penuh. Isi 0 bila telat tidak dipotong.'),

                    Forms\Components\TextInput::make('alpha_fine_per_day')
                        ->label('Denda Tetap per Hari Alpha (Rp)')
                        ->numeric()
                        ->prefix('Rp')
                        ->minValue(0)
                        ->maxValue(999999999)
                        ->default(0)
                        ->required()
                        ->helperText('Ditambahkan di atas potongan Alpha per jam kerja. Isi 0 bila tidak ada denda tambahan.'),

                    Forms\Components\Textarea::make('notes')
                        ->label('Catatan (opsional)')
                        ->rows(2)
                        ->maxLength(255)
                        ->helperText('Mis. dasar aturan: nomor pasal Peraturan Perusahaan.')
                        ->columnSpanFull(),
                ]),

            Forms\Components\Placeholder::make('how')
                ->label('Cara hitung')
                ->content('Telat: total menit telat sebulan dibagi ukuran blok (hanya blok penuh) x potongan per blok. Alpha: (gaji pokok dibagi total jam kerja terjadwal sebulan, jam istirahat dikurangi) x jam kerja hari itu, ditambah denda tetap per hari Alpha. Pulang cepat tidak dipotong. Aturan harus tertulis di Peraturan Perusahaan.')
                ->columnSpanFull(),

            Forms\Components\Hidden::make('created_by')->default(fn () => auth()->id()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('effective_from')->label('Berlaku Mulai')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(function (AttendanceDeductionRule $record) {
                        $current = AttendanceDeductionRule::effectiveFor(now()->startOfDay());

                        return match (true) {
                            $record->effective_from->isFuture() => 'terjadwal',
                            $current && $current->is($record) => 'berlaku',
                            default => 'diganti',
                        };
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'berlaku' => 'Berlaku sekarang',
                        'terjadwal' => 'Terjadwal',
                        default => 'Sudah diganti',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'berlaku' => 'success',
                        'terjadwal' => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('late_block_minutes')->label('Blok Telat')->suffix(' menit'),
                Tables\Columns\TextColumn::make('late_deduction_per_block')->label('Potongan per Blok')->money('IDR', locale: 'id'),
                Tables\Columns\TextColumn::make('alpha_fine_per_day')->label('Denda Alpha / Hari')->money('IDR', locale: 'id'),
                Tables\Columns\TextColumn::make('notes')->label('Catatan')->placeholder('—')->limit(40)->toggleable(),
            ])
            ->defaultSort('effective_from', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAttendanceDeductionRules::route('/'),
            'create' => Pages\CreateAttendanceDeductionRule::route('/create'),
            'edit' => Pages\EditAttendanceDeductionRule::route('/{record}/edit'),
        ];
    }
}
