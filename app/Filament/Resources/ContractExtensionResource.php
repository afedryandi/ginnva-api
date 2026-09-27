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

    protected static ?string $navigationLabel = 'Perpanjang Kontrak';

    protected static ?string $modelLabel = 'Perpanjangan Kontrak';

    protected static ?string $pluralModelLabel = 'Perpanjang Kontrak';

    protected static ?int $navigationSort = 70;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
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
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class)
            && $user->hasModuleAction(static::class, 'create', true);
    }

    public static function canDelete($record): bool
    {
        $user = auth()->user();

        return $user?->isFullAccess()
            || ($user?->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'delete', false));
    }

    public static function canDeleteAny(): bool
    {
        $user = auth()->user();

        return $user?->isFullAccess()
            || ($user?->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'delete', false));
    }

    /**
     * Eager-load user/extender — kolom "user.name"/"extender.name" di
     * table() sebelumnya N+1 per baris (audit fitur Perpanjang Kontrak
     * 2026-09-27, pola sama dengan resource lain yang sudah disapu
     * sebelumnya, resource ini terlewat).
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['user', 'extender']);
        $user  = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->whereHas('user', fn ($q) => $q->where('store_id', $user->store_id));
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Perpanjangan Kontrak')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('user_id')
                        ->label('Karyawan')
                        ->options(fn () => User::when(
                                ! auth()->user()?->isFullAccess(),
                                fn ($q) => $q->where('store_id', auth()->user()?->store_id)
                            )
                            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'partner'))
                            ->pluck('name', 'id')
                        )
                        ->searchable()
                        ->required()
                        ->live()
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

                Tables\Columns\TextColumn::make('previous_end_date')
                    ->label('Sebelumnya')
                    ->date('d M Y')
                    ->placeholder('Kontrak pertama'),

                Tables\Columns\TextColumn::make('new_end_date')
                    ->label('Diperpanjang Sampai')
                    ->date('d M Y')
                    ->weight('bold')
                    ->sortable(),

                Tables\Columns\TextColumn::make('notes')
                    ->label('Catatan')
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
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->visible(fn () => auth()->user()?->isFullAccess())
                    ->requiresConfirmation()
                    ->modalDescription('Menghapus riwayat ini TIDAK mengembalikan users.contract_end_date ke nilai sebelumnya secara otomatis — sesuaikan manual di menu User kalau perlu.'),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListContractExtensions::route('/'),
            'create' => Pages\CreateContractExtension::route('/create'),
        ];
    }
}
