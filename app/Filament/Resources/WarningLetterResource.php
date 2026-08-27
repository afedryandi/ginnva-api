<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WarningLetterResource\Pages;
use App\Models\Store;
use App\Models\User;
use App\Models\WarningLetter;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WarningLetterResource extends Resource
{
    protected static ?string $model = WarningLetter::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'Karyawan';

    protected static ?string $navigationLabel = 'Surat Peringatan';

    protected static ?string $modelLabel = 'Surat Peringatan';

    protected static ?string $pluralModelLabel = 'Surat Peringatan';

    protected static ?int $navigationSort = 60;

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

    public static function form(Form $form): Form
    {
        $isSuperAdmin = auth()->user()?->isFullAccess();

        return $form->schema([
            Forms\Components\Section::make('Surat Peringatan')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('store_id')
                        ->label('Toko')
                        ->options(fn () => Store::where('is_active', true)->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->default(fn () => $isSuperAdmin ? null : auth()->user()?->store_id)
                        ->disabled(! $isSuperAdmin)
                        ->dehydrated()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('user_id', null)),

                    // Karyawan difilter per toko yang dipilih — beda dari
                    // AttendanceResource/LeaveRequestResource yang sengaja
                    // dibalik urutannya, karena di sini toko SELALU sudah
                    // ada isinya lebih dulu (default ke toko sendiri untuk
                    // non-super-admin), jadi tidak ada masalah "toko dulu
                    // baru karyawan hilang dari daftar" seperti kasus itu.
                    Forms\Components\Select::make('user_id')
                        ->label('Karyawan')
                        ->options(fn (Forms\Get $get) => User::where('store_id', $get('store_id'))
                            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'partner'))
                            ->pluck('name', 'id')
                        )
                        ->searchable()
                        ->required(),

                    Forms\Components\Select::make('level')
                        ->label('Tingkat')
                        ->options([
                            'sp1' => 'SP 1',
                            'sp2' => 'SP 2',
                            'sp3' => 'SP 3',
                        ])
                        ->required(),

                    Forms\Components\DatePicker::make('issued_date')
                        ->label('Tanggal Diterbitkan')
                        ->required()
                        ->default(today()),

                    Forms\Components\DatePicker::make('valid_until')
                        ->label('Berlaku Sampai (opsional)')
                        ->helperText('Kosongkan kalau tidak ada batas waktu berlaku.')
                        ->minDate(fn (Forms\Get $get) => $get('issued_date')),

                    Forms\Components\FileUpload::make('document')
                        ->label('Scan Surat (opsional)')
                        ->directory('warning-letters')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                        ->maxSize(10240),

                    Forms\Components\Textarea::make('reason')
                        ->label('Alasan / Pelanggaran')
                        ->required()
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('warning_number')
                    ->label('No. Surat')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Karyawan')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('level')
                    ->label('Tingkat')
                    ->colors([
                        'warning' => 'sp1',
                        'danger'  => 'sp2',
                        'gray'    => 'sp3',
                    ])
                    ->formatStateUsing(fn (string $state) => strtoupper(str_replace('sp', 'SP ', $state))),

                Tables\Columns\TextColumn::make('issued_date')
                    ->label('Diterbitkan')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('valid_until')
                    ->label('Berlaku Sampai')
                    ->date('d M Y')
                    ->placeholder('Tidak ada batas'),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Alasan')
                    ->limit(40)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('issuer.name')
                    ->label('Diterbitkan Oleh')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('level')
                    ->label('Tingkat')
                    ->options(['sp1' => 'SP 1', 'sp2' => 'SP 2', 'sp3' => 'SP 3']),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->relationship('store', 'name')
                    ->visible(fn () => auth()->user()?->isFullAccess()),
            ])
            ->actions([
                Tables\Actions\Action::make('viewDocument')
                    ->label('Lihat Scan')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->visible(fn (WarningLetter $record) => filled($record->document))
                    ->url(fn (WarningLetter $record) => \Illuminate\Support\Facades\Storage::disk('public')->url($record->document))
                    ->openUrlInNewTab(),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn () => auth()->user()?->isFullAccess()),
            ])
            ->defaultSort('issued_date', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListWarningLetters::route('/'),
            'create' => Pages\CreateWarningLetter::route('/create'),
            'edit'   => Pages\EditWarningLetter::route('/{record}/edit'),
        ];
    }
}
