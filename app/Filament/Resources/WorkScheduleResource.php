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

    // Direnumber ke band 200-299 (audit navigasi 2026-09-29).
    protected static ?int $navigationSort = 201;

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

    // Tidak bisa dihapus kalau punya riwayat penugasan (FK cascade
    // menghapus semua riwayatnya) -- audit Daftar Jadwal Kerja 2026-09-28.
    public static function canDelete($record): bool
    {
        return static::accessGate()
            && (auth()->user()?->hasModuleAction(static::class, 'delete', true) ?? false)
            && ! $record->assignments()->exists();
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
                // Terkunci saat EDIT: memindah template ke toko lain membuat
                // days menunjuk shift toko lama & penugasan lama tetap di
                // toko asal (audit Daftar Jadwal Kerja 2026-09-28).
                ->disabled(fn (?WorkSchedule $record) => $record !== null || ! (auth()->user()?->isFullAccess() ?? false))
                ->dehydrated(fn (?WorkSchedule $record) => $record === null)
                ->live()
                ->required(),

            Forms\Components\TextInput::make('name')
                ->label('Nama Jadwal')
                ->placeholder('mis. Jadwal Toko A — Reguler')
                ->required()
                ->maxLength(100)
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (\Illuminate\Validation\Rules\Unique $rule, Get $get) => $rule->where('store_id', $get('store_id'))
                ),

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
                ->rules([
                    fn (Get $get, ?WorkSchedule $record) => function (string $attribute, $value, Closure $fail) use ($get, $record) {
                        // Saat edit, toko = toko RECORD (bukan state form yang bisa dimanipulasi).
                        $storeId = $record?->store_id ?? ($get('store_id') ? (int) $get('store_id') : null);

                        if ($error = WorkSchedule::validateDays($value, $storeId)) {
                            $fail($error);
                        }
                    },
                ])
                ->schema([
                    Forms\Components\Hidden::make('day'),
                    Forms\Components\Placeholder::make('day_label')
                        ->label('Hari')
                        ->content(fn (Get $get) => WorkSchedule::DAY_LABELS[$get('day')] ?? '—'),
                    Forms\Components\Select::make('shift_id')
                        ->label('Shift')
                        ->placeholder('Libur')
                        // Shift NONAKTIF yang sedang terpilih tetap jadi opsi
                        // (diberi label) -- sebelumnya hilang dari dropdown
                        // lalu tertimpa null (Libur) diam-diam saat menyimpan
                        // edit lain (audit 2026-09-28).
                        ->options(fn (Get $get) => Shift::query()
                            ->where('store_id', $get('../../store_id'))
                            ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $get('shift_id')))
                            ->get()
                            ->mapWithKeys(fn (Shift $s) => [$s->id => $s->name . ($s->is_active ? '' : ' (nonaktif)')]))
                        ->helperText('Kosongkan untuk menandai hari ini libur.'),
                ])
                ->columnSpanFull(),

            Forms\Components\Toggle::make('is_active')
                ->label('Aktif')
                ->helperText('Nonaktif = template tidak bisa diterapkan ke karyawan BARU; penugasan yang sudah berjalan tetap memakai jadwal ini.')
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
                        // Nama shift dimuat SEKALI per request (bukan per
                        // baris -- sebelumnya N+1) dan mencakup shift
                        // nonaktif; id yang benar-benar hilang tampil
                        // "(shift dihapus)" bukan "?".
                        static $shiftNames = null;
                        $shiftNames ??= Shift::withoutGlobalScopes()->pluck('name', 'id');

                        return collect($record->days)
                            ->map(fn ($row) => mb_substr(WorkSchedule::DAY_LABELS[$row['day']] ?? '?', 0, 3)
                                . ': ' . ($row['shift_id'] ? ($shiftNames[$row['shift_id']] ?? '(shift dihapus)') : 'Libur'))
                            ->implode(' · ');
                    })
                    ->wrap()
                    ->limit(80),

                // Karyawan yang penugasannya MASIH berlaku, bukan semua baris
                // riwayat (sebelumnya satu karyawan bisa terhitung berkali-kali).
                Tables\Columns\TextColumn::make('active_assignments_count')
                    ->label('Karyawan Aktif')
                    ->state(fn (WorkSchedule $record) => count($record->activeAssigneeIds()))
                    ->badge(),

                Tables\Columns\TextColumn::make('work_days')
                    ->label('Hari Kerja')
                    ->state(fn (WorkSchedule $record) => collect($record->days)->filter(fn ($row) => ! empty($row['shift_id']))->count() . ' / 7 hari')
                    ->toggleable(),

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

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->relationship('store', 'name')
                    ->visible(fn () => auth()->user()?->isFullAccess()),
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
                            ->required()
                            ->live(),

                        // Pratinjau dampak sebelum diterapkan (gap audit
                        // Daftar Jadwal Kerja 2026-09-28): SEBELUMNYA admin
                        // tidak melihat penugasan lama siapa yang akan
                        // ditutup. Info saja, keputusan tetap di admin.
                        Forms\Components\Placeholder::make('preview')
                            ->label('Dampak')
                            ->visible(fn (Get $get) => filled($get('user_ids')))
                            ->content(function (Get $get) {
                                $date = filled($get('effective_from')) ? Carbon::parse($get('effective_from')) : today();
                                $users = User::whereIn('id', $get('user_ids') ?? [])->pluck('name', 'id');

                                $lines = $users->map(function ($name, $id) use ($date) {
                                    $current = EmployeeScheduleAssignment::activeFor((int) $id, $date);

                                    return e($name) . ': ' . ($current?->workSchedule
                                        ? 'jadwal lama "' . e($current->workSchedule->name) . '" berakhir ' . $date->copy()->subDay()->translatedFormat('d M Y')
                                        : 'belum punya jadwal');
                                });

                                return new \Illuminate\Support\HtmlString($lines->implode('<br>'));
                            }),

                        Forms\Components\DatePicker::make('effective_from')
                            ->label('Berlaku Mulai Tanggal')
                            ->default(now()->toDateString())
                            ->native(false)
                            ->required()
                            ->minDate(today()->subDays(EmployeeScheduleAssignment::MAX_BACKDATE_DAYS))
                            ->helperText('Penugasan jadwal lama karyawan (kalau ada) otomatis berakhir sehari sebelum tanggal ini — riwayatnya tetap tersimpan, tidak dihapus.'),
                    ])
                    // Gate sendiri (audit Daftar Jadwal Kerja 2026-09-28):
                    // sebelumnya siapa pun yang bisa melihat menu ini bisa
                    // menugaskan jadwal, walau tidak punya hak edit.
                    ->visible(fn (WorkSchedule $record) => static::canEdit($record) && $record->is_active)
                    ->action(function (WorkSchedule $record, array $data) {
                        try {
                            $count = EmployeeScheduleAssignment::assignBulk(
                                $record,
                                $data['user_ids'],
                                Carbon::parse($data['effective_from']),
                                auth()->id(),
                            );
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()
                                ->title('Jadwal tidak bisa diterapkan')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        app(\App\Services\PushNotificationService::class)->sendToUsers(
                            $data['user_ids'],
                            'Jadwal Kerja Baru',
                            "Anda ditugaskan ke jadwal \"{$record->name}\" mulai " . Carbon::parse($data['effective_from'])->translatedFormat('d M Y') . '.'
                        );

                        Notification::make()
                            ->title("Jadwal diterapkan ke {$count} karyawan")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->modalDescription('Jadwal yang punya riwayat penugasan karyawan tidak bisa dihapus (tombol ini tidak muncul) -- nonaktifkan saja.'),
            ])
            ->bulkActions([
                // Bulk delete Filament cuma cek canDeleteAny(), tidak
                // re-cek canDelete() per record -- BulkAction kustom yang
                // melewati jadwal yang punya riwayat penugasan.
                Tables\Actions\BulkAction::make('deleteUnused')
                    ->label('Hapus yang dipilih')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Jadwal yang punya riwayat penugasan karyawan akan DILEWATI, bukan dihapus.')
                    ->visible(fn () => static::canDeleteAny())
                    ->deselectRecordsAfterCompletion()
                    ->action(function (\Illuminate\Support\Collection $records) {
                        $deleted = 0;
                        $skipped = 0;
                        foreach ($records as $schedule) {
                            if ($schedule->assignments()->exists()) {
                                $skipped++;
                                continue;
                            }
                            $schedule->delete();
                            $deleted++;
                        }

                        $notification = Notification::make()
                            ->title("{$deleted} jadwal dihapus" . ($skipped ? ", {$skipped} dilewati (punya riwayat penugasan)" : ''));
                        $skipped ? $notification->warning() : $notification->success();
                        $notification->send();
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            WorkScheduleResource\RelationManagers\AssignmentsRelationManager::class,
        ];
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
