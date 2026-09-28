<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShiftResource\Pages;
use App\Models\Shift;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Master Shift — audit Majoo vs Ginnva ("Master data Shift terpisah
 * dari Jadwal Kerja"), dibangun 2026-09-22. Gate 5-method eksplisit di
 * setiap resource baru (bukan cuma canViewAny) -- pola wajib sejak audit
 * "gate default-deny" 2026-09-14 (lihat feedback_gate_authorization_sweep),
 * supaya tidak lolos jadi 1 lagi resource yang tidak sengaja tak bisa
 * diakses siapa pun.
 */
class ShiftResource extends Resource
{
    protected static ?string $model = Shift::class;

    protected static ?string $navigationIcon = 'heroicon-o-sun';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'Jadwal Kerja';

    protected static ?string $navigationLabel = 'Daftar Shift';

    protected static ?string $modelLabel = 'Shift';

    protected static ?string $pluralModelLabel = 'Shift';

    protected static ?int $navigationSort = 40;

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
        return static::accessGate()
            && (auth()->user()?->hasModuleAction(static::class, 'create', true) ?? false);
    }

    public static function canView($record): bool
    {
        return static::accessGate()
            && (auth()->user()?->hasModuleAction(static::class, 'view', true) ?? false);
    }

    public static function canEdit($record): bool
    {
        return static::accessGate()
            && (auth()->user()?->hasModuleAction(static::class, 'update', true) ?? false);
    }

    public static function canDelete($record): bool
    {
        return static::accessGate()
            && (auth()->user()?->hasModuleAction(static::class, 'delete', true) ?? false)
            && ! $record->isInUse();
    }

    public static function canDeleteAny(): bool
    {
        return static::accessGate()
            && (auth()->user()?->hasModuleAction(static::class, 'delete', true) ?? false);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('store_id')
                ->label('Toko')
                ->relationship('store', 'name')
                ->default(fn () => auth()->user()?->store_id)
                // Terkunci saat EDIT (audit Daftar Shift 2026-09-28) --
                // memindah shift ke toko lain membuat Jadwal Kerja toko
                // lama yang memakainya menunjuk shift toko asing.
                ->disabled(fn (?Shift $record) => $record !== null || ! (auth()->user()?->isFullAccess() ?? false))
                ->dehydrated()
                ->required(),

            // Unique per toko (store_id, name) -- audit Daftar Shift
            // 2026-09-28: sebelumnya dua "Pagi" di toko yang sama bisa
            // dibuat. Constraint DB ada di migrasi
            // add_unique_store_name_to_shifts_table.
            Forms\Components\TextInput::make('name')
                ->label('Nama Shift')
                ->placeholder('mis. Pagi, Sore, Malam')
                ->required()
                ->maxLength(50)
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (\Illuminate\Validation\Rules\Unique $rule, Forms\Get $get) => $rule->where('store_id', $get('store_id'))
                ),

            Forms\Components\TimePicker::make('start_time')
                ->label('Jam Mulai')
                ->seconds(false)
                ->required()
                ->live(onBlur: true),

            Forms\Components\TimePicker::make('end_time')
                ->label('Jam Selesai')
                ->seconds(false)
                ->required()
                ->live(onBlur: true)
                ->rules([
                    fn (Forms\Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                        if ($value && $get('start_time') && \Illuminate\Support\Carbon::parse($value)->format('H:i') === \Illuminate\Support\Carbon::parse($get('start_time'))->format('H:i')) {
                            $fail('Jam selesai tidak boleh sama dengan jam mulai.');
                        }
                    },
                ])
                ->helperText('Kalau shift lintas tengah malam (mis. shift malam 22:00-06:00), isi jam selesai lebih kecil dari jam mulai apa adanya -- sistem otomatis mendeteksi ini sebagai lintas hari.'),

            // Istirahat: harus diisi berpasangan & jatuh di dalam rentang
            // shift (dihitung sebagai offset menit dari jam mulai, jadi
            // shift lintas tengah malam ikut benar). Sebelumnya cuma
            // required-less tanpa validasi sama sekali.
            Forms\Components\TimePicker::make('break_start_time')
                ->label('Mulai Istirahat (opsional)')
                ->seconds(false)
                ->live(onBlur: true)
                ->requiredWith('break_end_time'),

            Forms\Components\TimePicker::make('break_end_time')
                ->label('Selesai Istirahat (opsional)')
                ->seconds(false)
                ->live(onBlur: true)
                ->requiredWith('break_start_time')
                ->rules([
                    fn (Forms\Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                        $bs = $get('break_start_time');
                        $start = $get('start_time');
                        $end = $get('end_time');
                        if (! $value || ! $bs || ! $start || ! $end) {
                            return;
                        }

                        $toMin = fn ($t) => (int) \Illuminate\Support\Carbon::parse($t)->format('H') * 60 + (int) \Illuminate\Support\Carbon::parse($t)->format('i');
                        $offset = fn ($t) => (($toMin($t) - $toMin($start)) % 1440 + 1440) % 1440;

                        $shiftLength = $offset($end) ?: 1440;
                        $breakStartOffset = $offset($bs);
                        $breakEndOffset = $offset($value);

                        if ($breakEndOffset <= $breakStartOffset || $breakEndOffset > $shiftLength) {
                            $fail('Jam istirahat harus berada di dalam rentang jam kerja shift, dan selesai setelah mulai.');
                        }
                    },
                ]),

            Forms\Components\ColorPicker::make('color')
                ->label('Warna (utk kalender)')
                ->helperText('Kosongkan untuk pakai warna default sistem.'),

            Forms\Components\Toggle::make('is_active')
                ->label('Aktif')
                ->helperText('Nonaktif hanya menyembunyikan shift dari pilihan BARU di Jadwal Kerja; jadwal/override yang sudah memakainya tetap berjalan seperti biasa.')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ColorColumn::make('color')
                    ->label('')
                    ->default('#6b7280'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Shift')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('start_time')
                    ->label('Jam Kerja')
                    ->formatStateUsing(fn (Shift $record) => \Illuminate\Support\Carbon::parse($record->start_time)->format('H:i')
                        . ' – ' . \Illuminate\Support\Carbon::parse($record->end_time)->format('H:i')),

                Tables\Columns\TextColumn::make('break_start_time')
                    ->label('Istirahat')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state, Shift $record) => $state
                        ? \Illuminate\Support\Carbon::parse($record->break_start_time)->format('H:i') . ' – ' . \Illuminate\Support\Carbon::parse($record->break_end_time)->format('H:i')
                        : null),

                Tables\Columns\TextColumn::make('net_minutes')
                    ->label('Jam Kerja Bersih')
                    ->state(fn (Shift $record) => $record->netMinutes())
                    ->formatStateUsing(fn (int $state) => number_format($state / 60, 1, ',', '.') . ' jam'),

                Tables\Columns\TextColumn::make('usage')
                    ->label('Dipakai')
                    ->state(function (Shift $record) {
                        $u = $record->usageSummary();

                        return ($u['schedules'] + $u['overrides']) === 0
                            ? 'Belum dipakai'
                            : "{$u['schedules']} jadwal, {$u['overrides']} override";
                    })
                    ->color(fn (string $state) => $state === 'Belum dipakai' ? 'gray' : 'success'),

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
                Tables\Actions\EditAction::make(),

                // Alur "ganti shift" sebelum hapus (audit Daftar Shift
                // 2026-09-28) -- memindahkan semua pemakaian ke shift
                // lain di toko yang sama, setelah itu shift ini bebas
                // dihapus.
                Tables\Actions\Action::make('replaceUsage')
                    ->label('Pindahkan Pemakaian')
                    ->icon('heroicon-o-arrows-right-left')
                    ->color('warning')
                    ->visible(fn (Shift $record) => static::canEdit($record) && $record->isInUse())
                    ->form(fn (Shift $record) => [
                        Forms\Components\Select::make('target_id')
                            ->label('Ganti dengan shift')
                            ->options(fn () => Shift::where('store_id', $record->store_id)
                                ->where('id', '!=', $record->id)
                                ->where('is_active', true)
                                ->pluck('name', 'id'))
                            ->required(),
                    ])
                    ->modalDescription(fn (Shift $record) => 'Semua Jadwal Kerja dan override harian yang memakai "' . $record->name . '" akan diganti ke shift pilihan. Setelah itu shift ini bisa dihapus.')
                    ->action(function (Shift $record, array $data) {
                        $record->replaceUsageWith(Shift::findOrFail($data['target_id']));

                        \Filament\Notifications\Notification::make()
                            ->title('Pemakaian dipindahkan')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\DeleteAction::make()
                    ->modalDescription('Shift yang masih dipakai Jadwal Kerja atau override harian tidak bisa dihapus (tombol ini tidak muncul) -- ganti dulu shift di jadwal tersebut, atau nonaktifkan saja.'),
            ])
            ->bulkActions([
                // Bulk delete Filament cuma cek canDeleteAny(), tidak
                // re-cek canDelete() per record -- pakai BulkAction kustom
                // yang melewati shift yang masih dipakai (pola sama
                // FilmProduct/CustomerGroup).
                Tables\Actions\BulkAction::make('deleteUnused')
                    ->label('Hapus yang dipilih')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Shift yang masih dipakai Jadwal Kerja/override harian akan DILEWATI, bukan dihapus.')
                    ->visible(fn () => static::canDeleteAny())
                    ->deselectRecordsAfterCompletion()
                    ->action(function (\Illuminate\Support\Collection $records) {
                        $deleted = 0;
                        $skipped = 0;
                        foreach ($records as $shift) {
                            if ($shift->isInUse()) {
                                $skipped++;
                                continue;
                            }
                            $shift->delete();
                            $deleted++;
                        }

                        \Filament\Notifications\Notification::make()
                            ->title("{$deleted} shift dihapus" . ($skipped ? ", {$skipped} dilewati (masih dipakai)" : ''))
                            ->{$skipped ? 'warning' : 'success'}()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShifts::route('/'),
            'create' => Pages\CreateShift::route('/create'),
            'edit' => Pages\EditShift::route('/{record}/edit'),
        ];
    }
}
