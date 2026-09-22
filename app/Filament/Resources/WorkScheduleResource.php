<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WorkScheduleResource\Pages;
use App\Models\EmployeeScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Template Jadwal Kerja Mingguan (Pola Standar 7-hari) — audit Majoo vs
 * Ginnva ("Template jadwal kerja mingguan yang direuse ke banyak
 * karyawan sekaligus"), dibangun 2026-09-22 sebagai bagian dari paket
 * modul Jadwal Kerja (bersama ShiftResource & aksi "Terapkan ke
 * Karyawan" di bawah, yang menutup temuan "Tanggal Efektif" &
 * "Riwayat Jadwal Kerja" sekaligus lewat EmployeeScheduleAssignment).
 */
class WorkScheduleResource extends Resource
{
    protected static ?string $model = WorkSchedule::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'Jadwal Kerja';

    protected static ?string $navigationLabel = 'Daftar Jadwal Kerja';

    protected static ?string $modelLabel = 'Jadwal Kerja';

    protected static ?string $pluralModelLabel = 'Jadwal Kerja';

    protected static ?int $navigationSort = 41;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    private static function accessGate(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    public static function canViewAny(): bool
    {
        return static::accessGate();
    }

    public static function canCreate(): bool
    {
        return static::accessGate();
    }

    public static function canView($record): bool
    {
        return static::accessGate();
    }

    public static function canEdit($record): bool
    {
        return static::accessGate();
    }

    public static function canDelete($record): bool
    {
        return static::accessGate();
    }

    public static function canDeleteAny(): bool
    {
        return static::accessGate();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('store_id')
                ->label('Toko')
                ->relationship('store', 'name')
                ->default(fn () => auth()->user()?->store_id)
                ->disabled(fn () => ! (auth()->user()?->isFullAccess() ?? false))
                ->dehydrated()
                ->live()
                ->required(),

            Forms\Components\TextInput::make('name')
                ->label('Nama Jadwal')
                ->placeholder('mis. Jadwal Toko A — Reguler')
                ->required()
                ->maxLength(100),

            // 7 baris TETAP (tidak bisa ditambah/dihapus staff) — 1 baris
            // per hari, "Libur" = shift_id dikosongkan. Struktur ini
            // yang disimpan mentah ke kolom `days` (JSON), pola sama
            // dengan Store::opening_hours yang sudah ada.
            Forms\Components\Repeater::make('days')
                ->label('Pola 7 Hari')
                ->default(fn () => collect(WorkSchedule::DAYS)->map(fn ($day) => ['day' => $day, 'shift_id' => null])->toArray())
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->columns(2)
                ->itemLabel(fn (array $state): ?string => WorkSchedule::DAY_LABELS[$state['day'] ?? ''] ?? null)
                ->schema([
                    Forms\Components\Hidden::make('day'),
                    Forms\Components\Placeholder::make('day_label')
                        ->label('Hari')
                        ->content(fn (Get $get) => WorkSchedule::DAY_LABELS[$get('day')] ?? '—'),
                    Forms\Components\Select::make('shift_id')
                        ->label('Shift')
                        ->placeholder('Libur')
                        ->options(fn (Get $get) => Shift::query()
                            ->where('store_id', $get('../../store_id'))
                            ->where('is_active', true)
                            ->pluck('name', 'id'))
                        ->helperText('Kosongkan untuk menandai hari ini libur.'),
                ])
                ->columnSpanFull(),

            Forms\Components\Toggle::make('is_active')
                ->label('Aktif')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Jadwal')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('days')
                    ->label('Pola')
                    ->state(function (WorkSchedule $record) {
                        $shiftNames = Shift::whereIn('id', collect($record->days)->pluck('shift_id')->filter())->pluck('name', 'id');

                        return collect($record->days)
                            ->map(fn ($row) => mb_substr(WorkSchedule::DAY_LABELS[$row['day']] ?? '?', 0, 3)
                                . ': ' . ($row['shift_id'] ? ($shiftNames[$row['shift_id']] ?? '?') : 'Libur'))
                            ->implode(' · ');
                    })
                    ->wrap()
                    ->limit(80),

                Tables\Columns\TextColumn::make('assignments_count')
                    ->label('Karyawan Ter-assign')
                    ->counts('assignments')
                    ->badge(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status Aktif'),
            ])
            ->actions([
                // "Terapkan ke Karyawan" (audit Majoo, inti temuan ini) --
                // pilih banyak karyawan sekaligus + tanggal efektif, lalu
                // EmployeeScheduleAssignment::assignBulk() menutup
                // penugasan lama & membuat penugasan baru utk semuanya
                // dalam 1 transaksi.
                Tables\Actions\Action::make('assignToEmployees')
                    ->label('Terapkan ke Karyawan')
                    ->icon('heroicon-o-user-group')
                    ->color('success')
                    ->form([
                        Forms\Components\Select::make('user_ids')
                            ->label('Pilih Karyawan')
                            ->multiple()
                            ->searchable()
                            ->options(fn (WorkSchedule $record) => User::query()
                                ->where('store_id', $record->store_id)
                                ->where('is_active', true)
                                ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', ['partner']))
                                ->pluck('name', 'id'))
                            ->required(),

                        Forms\Components\DatePicker::make('effective_from')
                            ->label('Berlaku Mulai Tanggal')
                            ->default(now()->toDateString())
                            ->native(false)
                            ->required()
                            ->helperText('Penugasan jadwal lama karyawan (kalau ada) otomatis berakhir sehari sebelum tanggal ini — riwayatnya tetap tersimpan, tidak dihapus.'),
                    ])
                    ->action(function (WorkSchedule $record, array $data) {
                        $count = EmployeeScheduleAssignment::assignBulk(
                            $record,
                            $data['user_ids'],
                            Carbon::parse($data['effective_from']),
                            auth()->id(),
                        );

                        Notification::make()
                            ->title("Jadwal diterapkan ke {$count} karyawan")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWorkSchedules::route('/'),
            'create' => Pages\CreateWorkSchedule::route('/create'),
            'edit' => Pages\EditWorkSchedule::route('/{record}/edit'),
        ];
    }
}
