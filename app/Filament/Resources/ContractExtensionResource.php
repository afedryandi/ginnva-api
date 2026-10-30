<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ContractExtensionResource\Pages;
use App\Models\ContractExtension;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContractExtensionResource extends Resource
{
    protected static ?string $model = ContractExtension::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- band 300-399 "Cuti & Kontrak".
    protected static ?string $navigationGroup = 'Pengaturan Karyawan';

    protected static ?string $navigationLabel = 'Riwayat Kontrak';

    protected static ?string $modelLabel = 'Perpanjangan Kontrak';

    protected static ?string $pluralModelLabel = 'Riwayat Kontrak';

    protected static ?int $navigationSort = 604;

    /**
     * Riwayat dan pencatatan perpanjangan kontrak mengubah tanggal akhir kontrak karyawan dan mengirim notifikasi ke mereka, jadi tidak
     * terbuka lewat akses bawaan akun baru (menu_access kosong): hanya akses penuh atau staf yang menunya dicentang eksplisit.
     * Mencatat dan menghapus butuh izin aksi eksplisit (audit Riwayat Kontrak, 2026-10-31).
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasExplicitMenuAccess(static::class) ?? false;
    }

    public static function canEdit($record): bool
    {
        // Riwayat perpanjangan itu jejak historis — sekali dicatat tidak
        // pernah diedit ulang (beda dari "koreksi kesalahan input", yang
        // seharusnya lewat baris perpanjangan BARU, bukan mengubah yang
        // lama), supaya previous_end_date tetap akurat sebagai snapshot.
        return false;
    }

    /**
     * SEBELUMNYA tidak ada canCreate()/canDelete() sama sekali, dan tidak
     * ada ContractExtensionPolicy terdaftar — Gate default-deny bikin
     * CreateAction (halaman "Catat Perpanjangan") dan DeleteAction
     * (->visible() sudah ada, isFullAccess saja) TIDAK PERNAH muncul
     * untuk siapa pun, termasuk super_admin. canCreate() dibiarkan seluas
     * canViewAny(), canDelete() disamakan persis dengan guard ->visible()
     * yang sudah ada di DeleteAction.
     */
    public static function canCreate(): bool
    {
        return static::canViewAny() && (auth()->user()?->hasModuleAction(static::class, 'create', false) ?? false);
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny() && (auth()->user()?->hasModuleAction(static::class, 'delete', false) ?? false);
    }

    public static function canDeleteAny(): bool
    {
        return static::canViewAny() && (auth()->user()?->hasModuleAction(static::class, 'delete', false) ?? false);
    }

    /**
     * Eager-load user/extender — kolom "user.name"/"extender.name" di
     * table() sebelumnya N+1 per baris (audit fitur Perpanjang Kontrak
     * 2026-09-27, pola sama dengan resource lain yang sudah disapu
     * sebelumnya, resource ini terlewat).
     */
    /** Baris riwayat yang boleh dilihat akun ini: akses penuh semuanya, selain itu hanya karyawan tokonya. */
    private static function scopedQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->whereHas('user', fn ($q) => $q->where('store_id', $user->store_id));
        }

        return $query;
    }

    public static function getEloquentQuery(): Builder
    {
        // "Perpanjangan ke-N": urutan baris itu di antara perpanjangan (bukan kontrak pertama) karyawan yang sama.
        return static::scopedQuery()
            ->with(['user.store', 'extender'])
            ->select('contract_extensions.*')
            ->addSelect(['extension_number' => ContractExtension::query()->from('contract_extensions as earlier')
                ->selectRaw('count(*)')
                ->whereColumn('earlier.user_id', 'contract_extensions.user_id')
                ->whereColumn('earlier.id', '<=', 'contract_extensions.id')
                ->whereNotNull('earlier.previous_end_date')]);
    }

    /** Pilihan karyawan untuk perpanjangan dan pengangkatan: aktif dan bertipe kontrak (plus yang mati otomatis karena kontrak lewat). */
    public static function employeeOptions(): array
    {
        return ContractExtension::eligibleEmployees(auth()->user())->orderBy('name')->get(['id', 'name', 'is_active'])
            ->mapWithKeys(fn (User $u) => [$u->id => $u->name . ($u->is_active ? '' : ' (akun nonaktif, kontrak lewat)')])
            ->all();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Perpanjangan Kontrak')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('user_id')
                        ->label('Karyawan')
                        ->options(fn () => static::employeeOptions())
                        ->searchable()
                        ->required()
                        ->live()
                        ->hint('Hanya karyawan aktif bertipe kontrak')
                        ->helperText(function (Forms\Get $get) {
                            $user = $get('user_id') ? User::find($get('user_id')) : null;
                            if (! $user) return null;
                            return $user->contract_end_date
                                ? 'Kontrak saat ini berakhir: ' . $user->contract_end_date->format('d M Y')
                                : 'Karyawan ini belum punya tanggal akhir kontrak tercatat.';
                        }),

                    /**
                     * Bug diperbaiki 2026-09-27 (audit Perpanjang
                     * Kontrak) -- SEBELUMNYA minDate() cuma today(),
                     * admin bisa input tanggal baru yang LEBIH AWAL
                     * dari contract_end_date karyawan yang sekarang
                     * (mis. kontrak sampai Des 2026, salah pilih Okt
                     * 2026) dan tetap tercatat sebagai "perpanjangan"
                     * tanpa peringatan apa pun -- padahal itu
                     * memperpendek kontrak. minDate() sekarang
                     * mengambil yang LEBIH BESAR antara hari ini dan
                     * contract_end_date karyawan yang dipilih.
                     */
                    Forms\Components\DatePicker::make('new_end_date')
                        ->label('Tanggal Berakhir Kontrak Baru')
                        ->required()
                        ->minDate(function (Forms\Get $get) {
                            $user = $get('user_id') ? User::find($get('user_id')) : null;
                            $currentEnd = $user?->contract_end_date;

                            return $currentEnd && $currentEnd->gt(today()) ? $currentEnd : today();
                        })
                        ->helperText('Harus lebih baru dari tanggal akhir kontrak yang sekarang berlaku.'),

                    Forms\Components\Textarea::make('notes')
                        ->label('Catatan (opsional)')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Karyawan')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.store.name')
                    ->label('Toko')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('previous_end_date')
                    ->label('Sebelumnya')
                    ->date('d M Y')
                    ->placeholder('Kontrak pertama')
                    ->description(fn (ContractExtension $record) => $record->previous_end_date ? 'Perpanjangan ke-' . ((int) $record->extension_number) : null),

                Tables\Columns\TextColumn::make('new_end_date')
                    ->label('Diperpanjang Sampai')
                    ->date('d M Y')
                    ->weight('bold')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (ContractExtension $record) => static::statusLabel($record))
                    ->color(fn (ContractExtension $record) => static::statusColor($record)),

                Tables\Columns\TextColumn::make('tenure')
                    ->label('Masa Kontrak')
                    ->state(fn (ContractExtension $record) => static::tenureLabel($record))
                    ->description(fn (ContractExtension $record) => static::tenureLimitNote($record))
                    ->color(fn (ContractExtension $record) => static::tenureColor($record))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('notes')
                    ->label('Catatan')
                    ->searchable()
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('extender.name')
                    ->label('Dicatat Oleh')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Tanggal Dicatat')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Karyawan')
                    ->searchable()
                    ->options(fn () => User::whereIn('id', static::scopedQuery()->reorder()->select('contract_extensions.user_id'))->orderBy('name')->pluck('name', 'id')),

                Tables\Filters\SelectFilter::make('store')
                    ->label('Toko')
                    ->options(fn () => \App\Models\Store::whereIn('id', User::whereIn('id', static::scopedQuery()->reorder()->select('contract_extensions.user_id'))->select('store_id'))->orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('user', fn ($q) => $q->where('store_id', $data['value']))
                        : $query),

                Tables\Filters\Filter::make('new_end_date')
                    ->label('Berakhir antara')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Berakhir dari'),
                        Forms\Components\DatePicker::make('until')->label('Berakhir sampai'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('new_end_date', '>=', $date))
                        ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('new_end_date', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) {
                            $indicators[] = 'Berakhir dari ' . \Illuminate\Support\Carbon::parse($data['from'])->translatedFormat('d M Y');
                        }
                        if ($data['until'] ?? null) {
                            $indicators[] = 'Berakhir sampai ' . \Illuminate\Support\Carbon::parse($data['until'])->translatedFormat('d M Y');
                        }

                        return $indicators;
                    }),

                Tables\Filters\TernaryFilter::make('first_contract')
                    ->label('Jenis catatan')
                    ->trueLabel('Kontrak pertama')
                    ->falseLabel('Perpanjangan')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNull('previous_end_date'),
                        false: fn (Builder $query) => $query->whereNotNull('previous_end_date'),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (ContractExtension $record) => static::canDelete($record))
                    ->requiresConfirmation()
                    ->modalDescription('Menghapus riwayat ini TIDAK mengembalikan users.contract_end_date ke nilai sebelumnya secara otomatis — sesuaikan manual di menu User kalau perlu.'),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /** Apakah baris ini kontrak yang sekarang berlaku bagi karyawannya (tanggalnya sama dengan akhir kontrak di data karyawan). */
    private static function isCurrent(ContractExtension $record): bool
    {
        return $record->user?->contract_end_date !== null && $record->new_end_date->isSameDay($record->user->contract_end_date);
    }

    private static function statusLabel(ContractExtension $record): string
    {
        if (! static::isCurrent($record)) {
            return 'Diganti kontrak berikutnya';
        }

        $days = today()->diffInDays($record->new_end_date, false);

        return match (true) {
            $days < 0 => 'Berakhir ' . abs($days) . ' hari lalu',
            $days === 0 => 'Berakhir hari ini',
            default => "Berlaku, {$days} hari lagi",
        };
    }

    private static function statusColor(ContractExtension $record): string
    {
        if (! static::isCurrent($record)) {
            return 'gray';
        }

        $days = today()->diffInDays($record->new_end_date, false);

        return match (true) {
            $days < 0 => 'danger',
            $days <= 30 => 'warning',
            default => 'success',
        };
    }

    /** Lama kerja sejak tanggal bergabung sampai akhir kontrak baris ini ("1 th 6 bln"); batas PKWT dihitung dari tanggal bergabung. */
    private static function tenureLabel(ContractExtension $record): string
    {
        $join = $record->user?->join_date;

        if (! $join) {
            return '—';
        }

        $diff = $join->diff($record->new_end_date);

        return trim(($diff->y ? "{$diff->y} th " : '') . ($diff->m ? "{$diff->m} bln" : '')) ?: 'kurang dari 1 bln';
    }

    private static function tenureLimitNote(ContractExtension $record): ?string
    {
        $join = $record->user?->join_date;

        if (! $join) {
            return null;
        }

        $maxYears = (int) config('hr.pkwt_max_years', 5);

        return "batas {$maxYears} th: " . $join->copy()->addYears($maxYears)->format('d M Y');
    }

    private static function tenureColor(ContractExtension $record): ?string
    {
        $join = $record->user?->join_date;

        if (! $join) {
            return null;
        }

        $limit = $join->copy()->addYears((int) config('hr.pkwt_max_years', 5));

        return match (true) {
            $record->new_end_date->gt($limit) => 'danger',
            $record->new_end_date->gte($limit->copy()->subMonths(6)) => 'warning',
            default => null,
        };
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListContractExtensions::route('/'),
            'create' => Pages\CreateContractExtension::route('/create'),
        ];
    }
}
