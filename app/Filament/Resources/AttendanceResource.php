<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AttendanceResource\Pages;
use App\Models\Attendance;
use App\Models\Store;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AttendanceResource extends Resource
{
    protected static ?string $model = Attendance::class;

    protected static ?string $navigationIcon = 'heroicon-o-finger-print';

    protected static ?string $navigationGroup = 'Karyawan';

    protected static ?string $navigationLabel = 'Absensi Karyawan';

    protected static ?string $modelLabel = 'Absensi';

    protected static ?string $pluralModelLabel = 'Absensi Karyawan';

    protected static ?int $navigationSort = 30;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    /**
     * Form ini HANYA dipakai untuk entri MANUAL oleh admin/atasan (device
     * absen mati, atau catat dinas luar/field duty) — absen normal harian
     * ('clock') selalu datang dari endpoint mobile (Attendance::clockIn/
     * clockOut), tidak lewat form Filament ini sama sekali.
     */
    public static function form(Form $form): Form
    {
        $isSuperAdmin = auth()->user()?->isFullAccess();

        return $form->schema([
            Forms\Components\Section::make('Entri Manual Absensi')
                ->description('Dipakai kalau device/wifi absen di toko mati, atau karyawan langsung dinas luar/ambil kendaraan client (tidak perlu hadir ke toko dulu).')
                ->columns(2)
                ->schema([
                    // Karyawan dipilih DULUAN, Toko ikut otomatis dari
                    // store_id karyawan itu — BUKAN sebaliknya. Kalau Toko
                    // yang duluan & Karyawan difilter olehnya, akun
                    // super_admin/direksi (store_id NULL, mis. pemilik yang
                    // ikut turun ke lapangan) tidak akan PERNAH muncul di
                    // manapun toko dipilih, karena tidak ada toko yang
                    // store_id-nya cocok dengan NULL.
                    Forms\Components\Select::make('user_id')
                        ->label('Karyawan')
                        ->options(fn () => User::when(
                                ! $isSuperAdmin,
                                fn ($q) => $q->where('store_id', auth()->user()?->store_id)
                            )
                            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'partner'))
                            ->pluck('name', 'id')
                        )
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set, ?string $state) => $set('store_id', $state ? User::find($state)?->store_id : null)),

                    Forms\Components\Select::make('store_id')
                        ->label('Toko')
                        ->helperText('Otomatis terisi dari toko karyawan yang dipilih — bisa diubah manual kalau entrinya untuk toko lain (mis. dinas luar ke cabang lain).')
                        ->options(fn () => Store::where('is_active', true)->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->disabled(! $isSuperAdmin)
                        ->dehydrated(),

                    Forms\Components\DatePicker::make('date')
                        ->label('Tanggal')
                        ->required()
                        ->default(today())
                        ->maxDate(today()),

                    // Jenis 'clock'/'alpha'/'leave' TIDAK BISA dipilih di
                    // sini SAMA SEKALI (options-nya sengaja cuma 2) untuk
                    // baris BARU — cuma dipakai staff MELIHAT jenis asli
                    // (opsi lengkap muncul & field-nya terkunci) saat
                    // MENGEDIT baris yang sudah ada jenis sistem tersebut,
                    // supaya tidak ada yang bisa menyamarkan absen mangkir
                    // jadi seolah-olah "Manual".
                    Forms\Components\Select::make('entry_type')
                        ->label('Jenis Entri')
                        ->options(fn (?Attendance $record) => $record && in_array($record->entry_type, ['clock', 'alpha', 'leave'])
                            ? [
                                'clock' => 'Normal (App)',
                                'alpha' => 'Alpha (Tidak Ada Keterangan)',
                                'leave' => 'Izin/Cuti',
                            ]
                            : [
                                'manual'     => 'Manual (device/wifi absen mati)',
                                'field_duty' => 'Dinas Luar / Ambil Kendaraan Client',
                            ])
                        ->required()
                        ->default('manual')
                        ->disabled(fn (?Attendance $record) => $record && in_array($record->entry_type, ['clock', 'alpha', 'leave']))
                        ->dehydrated(),

                    Forms\Components\DateTimePicker::make('clock_in_at')
                        ->label('Jam Masuk')
                        ->seconds(false)
                        // Super_admin/direksi TETAP bisa koreksi jam pada
                        // baris 'clock' asli (mis. GPS meleset), tapi
                        // staff/store_manager biasa tidak boleh — cegah
                        // "koreksi" jadi celah manipulasi jam absen sendiri.
                        ->disabled(fn (?Attendance $record) => $record && in_array($record->entry_type, ['clock', 'alpha', 'leave']) && ! auth()->user()?->isFullAccess())
                        ->rule(fn (Forms\Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                            if (! $value || ! $get('date')) return;
                            if (Carbon::parse($value)->toDateString() !== $get('date')) {
                                $fail('Jam Masuk harus di tanggal yang sama dengan field Tanggal.');
                            }
                        }),

                    Forms\Components\DateTimePicker::make('clock_out_at')
                        ->label('Jam Keluar')
                        ->seconds(false)
                        ->disabled(fn (?Attendance $record) => $record && in_array($record->entry_type, ['clock', 'alpha', 'leave']) && ! auth()->user()?->isFullAccess())
                        ->rule(fn (Forms\Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                            if (! $value) return;
                            if ($get('date') && Carbon::parse($value)->toDateString() !== $get('date')) {
                                $fail('Jam Keluar harus di tanggal yang sama dengan field Tanggal.');
                            }
                            if ($get('clock_in_at') && Carbon::parse($value)->lessThanOrEqualTo(Carbon::parse($get('clock_in_at')))) {
                                $fail('Jam Keluar harus setelah Jam Masuk.');
                            }
                        }),

                    Forms\Components\Textarea::make('note')
                        ->label(fn (?Attendance $record) => $record && in_array($record->entry_type, ['clock', 'alpha', 'leave']) ? 'Alasan Koreksi (wajib)' : 'Alasan / Catatan')
                        ->required()
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Karyawan')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('entry_type')
                    ->label('Jenis')
                    ->colors([
                        'success' => 'clock',
                        'warning' => 'manual',
                        'info'    => 'field_duty',
                        'gray'    => 'leave',
                        'danger'  => 'alpha',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'clock'      => 'Normal (App)',
                        'manual'     => 'Manual',
                        'field_duty' => 'Dinas Luar',
                        'alpha'      => 'Alpha',
                        'leave'      => 'Izin/Cuti',
                        default      => $state,
                    }),

                Tables\Columns\TextColumn::make('clock_in_at')
                    ->label('Jam Masuk')
                    ->dateTime('H:i')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('clock_out_at')
                    ->label('Jam Keluar')
                    ->dateTime('H:i')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('late_minutes')
                    ->label('Telat')
                    ->formatStateUsing(fn (int $state) => $state > 0 ? "{$state} mnt" : '—')
                    ->color(fn (int $state) => $state > 0 ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('early_leave_minutes')
                    ->label('Pulang Cepat')
                    ->formatStateUsing(fn (int $state) => $state > 0 ? "{$state} mnt" : '—')
                    ->color(fn (int $state) => $state > 0 ? 'warning' : 'gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('clock_in_distance_meters')
                    ->label('Jarak Absen')
                    ->formatStateUsing(fn (?int $state) => $state !== null ? "{$state} m" : '—')
                    ->color(fn (Attendance $record) => $record->isOutsideRadius() ? 'danger' : 'gray')
                    ->weight(fn (Attendance $record) => $record->isOutsideRadius() ? 'bold' : 'normal')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('reviewed_at')
                    ->label('Ditinjau')
                    ->boolean()
                    ->getStateUsing(fn (Attendance $record) => $record->isAcknowledged())
                    ->toggleable(),

                Tables\Columns\TextColumn::make('note')
                    ->label('Catatan')
                    ->limit(30)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('entry_type')
                    ->label('Jenis Entri')
                    ->options([
                        'clock'      => 'Normal (App)',
                        'manual'     => 'Manual',
                        'field_duty' => 'Dinas Luar',
                        'alpha'      => 'Alpha',
                        'leave'      => 'Izin/Cuti',
                    ]),

                // Kondisi "perlu ditinjau" di sini HARUS persis sama dengan
                // kondisi ->visible() tombol aksi "Tandai Ditinjau" di bawah
                // (telat/pulang-cepat/alpha/di-luar-radius) — sebelumnya
                // outside_radius TIDAK ikut di filter ini (cuma ada di
                // tombol aksi), jadi baris yang CUMA di luar radius (tidak
                // telat/pulang-cepat/alpha) tombolnya tetap muncul tapi
                // tidak pernah ikut ter-filter di sini, bisa terlewat dari
                // radar admin yang menyisir pakai filter ini. Ditemukan
                // dari testing live checklist Absensi Karyawan.
                Tables\Filters\Filter::make('needs_review')
                    ->label('Perlu Ditinjau')
                    ->query(fn (Builder $query) => $query
                        ->where(fn ($q) => $q->where('late_minutes', '>', 0)
                            ->orWhere('early_leave_minutes', '>', 0)
                            ->orWhere('entry_type', 'alpha')
                            ->orWhere(fn ($q2) => $q2
                                ->whereNotNull('clock_in_distance_meters')
                                ->whereHas('store', fn (Builder $q3) => $q3->whereRaw(
                                    'attendances.clock_in_distance_meters > COALESCE(stores.attendance_radius_meters, ?)',
                                    [Attendance::DEFAULT_RADIUS_METERS]
                                ))))
                        ->where(fn ($q) => $q->whereNull('reviewed_at')->orWhereColumn('reviewed_at', '<', 'updated_at'))
                    ),

                Tables\Filters\Filter::make('is_late')
                    ->label('Telat')
                    ->query(fn (Builder $query) => $query->where('late_minutes', '>', 0)),

                // Bandingkan ke radius TOKO masing-masing (fallback
                // DEFAULT_RADIUS_METERS kalau toko belum diatur) — bukan
                // angka tetap, karena tiap toko bisa beda radiusnya (lihat
                // StoreResource "Radius Absen").
                Tables\Filters\Filter::make('outside_radius')
                    ->label('Di Luar Radius Toko')
                    ->query(fn (Builder $query) => $query
                        ->whereNotNull('clock_in_distance_meters')
                        ->whereHas('store', fn (Builder $q) => $q->whereRaw(
                            'attendances.clock_in_distance_meters > COALESCE(stores.attendance_radius_meters, ?)',
                            [Attendance::DEFAULT_RADIUS_METERS]
                        ))
                    ),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->relationship('store', 'name')
                    ->visible(fn () => auth()->user()?->isFullAccess()),
            ])
            ->actions([
                Tables\Actions\Action::make('review')
                    ->label('Tandai Ditinjau')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->visible(fn (Attendance $record) => ! $record->isAcknowledged()
                        && ($record->late_minutes > 0 || $record->early_leave_minutes > 0 || $record->entry_type === 'alpha' || $record->isOutsideRadius()))
                    ->action(function (Attendance $record) {
                        $record->acknowledge(auth()->id());
                        Notification::make()->title('Ditandai sudah ditinjau')->success()->send();
                    }),

                // Edit SELALU boleh dibuka (termasuk baris 'clock'/'alpha'/
                // 'leave') — form-nya sendiri yang mengunci field-field
                // sensitif untuk non-full-access (lihat disabled() di
                // masing-masing field form()), bukan menyembunyikan tombol
                // Edit total seperti sebelumnya.
                Tables\Actions\EditAction::make(),

                // Baris 'clock' (absen asli via GPS) TIDAK BISA dihapus
                // sama sekali, oleh siapa pun — kesalahan pada baris ini
                // harus dikoreksi lewat Edit (meninggalkan jejak Activity
                // Log), bukan dihapus tanpa bekas. Baris lain (manual/
                // dinas luar/alpha/izin) tetap bisa dihapus super_admin.
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Attendance $record) => auth()->user()?->isFullAccess()
                        && $record->entry_type !== 'clock'),
            ])
            ->defaultSort('date', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAttendances::route('/'),
            'create' => Pages\CreateAttendance::route('/create'),
            'edit'   => Pages\EditAttendance::route('/{record}/edit'),
        ];
    }
}
