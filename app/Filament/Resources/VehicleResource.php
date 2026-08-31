<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VehicleResource\Pages;
use App\Models\Vehicle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\QueryException;

class VehicleResource extends Resource
{
    protected static ?string $model = Vehicle::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Kendaraan';

    protected static ?string $modelLabel = 'Kendaraan';

    protected static ?string $pluralModelLabel = 'Kendaraan';

    /**
     * Data master nasional, tidak ber-scope toko — super_admin dan
     * staff toko sama-sama lihat & bisa edit semua baris.
     * Dipakai untuk dropdown pilihan kendaraan di form quotation.
     */
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Data Kendaraan')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('brand')
                        ->label('Merek')
                        ->placeholder('Contoh: Honda, Toyota')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('model')
                        ->label('Tipe / Model')
                        ->placeholder('Contoh: Civic, Alphard (opsional — isi jika sudah diketahui)')
                        ->nullable()
                        ->maxLength(255),

                    // unique() di sini scoped ke brand+model lewat
                    // modifyRuleUsing — beda dari constraint database
                    // (unique(['brand','model','variant']) di migration),
                    // validasi Laravel ini BENAR menangkap kasus variant
                    // kosong (whereNull otomatis kalau value-nya null),
                    // yang justru tidak tertutup oleh unique index MySQL
                    // biasa (dua NULL dianggap tidak sama).
                    Forms\Components\TextInput::make('variant')
                        ->label('Varian')
                        ->placeholder('Contoh: 1.5 RS CVT, 2.5 G AT')
                        ->nullable()
                        ->maxLength(255)
                        ->unique(
                            table: 'vehicles',
                            column: 'variant',
                            ignoreRecord: true,
                            modifyRuleUsing: fn ($rule, Forms\Get $get) => $rule
                                ->where('brand', $get('brand'))
                                ->where('model', $get('model')),
                        )
                        ->validationMessages([
                            'unique' => 'Kendaraan dengan merek, model, dan varian yang sama sudah terdaftar.',
                        ]),

                    Forms\Components\Select::make('size_category')
                        ->label('Kategori Ukuran')
                        ->helperText('Menentukan koefisien harga (base_price × coefficient) saat kalkulasi quotation.')
                        ->options([
                            'S'   => 'S — Kecil (city car, hatchback)',
                            'M'   => 'M — Sedang (sedan, MPV kecil)',
                            'L'   => 'L — Besar (SUV, MPV besar)',
                            'XL'  => 'XL — Sangat Besar (double cabin, van)',
                            'XXL' => 'XXL — Ekstra Besar (sport car, luxury)',
                        ])
                        ->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('brand')
                    ->label('Merek')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('model')
                    ->label('Tipe / Model')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('variant')
                    ->label('Varian')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('size_category')
                    ->label('Ukuran')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('size_category')
                    ->label('Kategori Ukuran')
                    ->options([
                        'S'   => 'S',
                        'M'   => 'M',
                        'L'   => 'L',
                        'XL'  => 'XL',
                        'XXL' => 'XXL',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                // quotations.vehicle_id pakai onDelete('restrict') — hapus
                // kendaraan yang masih direferensikan quotation akan
                // ditolak database. Ditangkap di sini supaya staff dapat
                // pesan jelas, bukan error mentah (pola sama dengan
                // FilmProductResource).
                Tables\Actions\DeleteAction::make()
                    ->action(function (Vehicle $record) {
                        try {
                            $record->delete();
                        } catch (QueryException $e) {
                            Notification::make()
                                ->title('Tidak bisa menghapus kendaraan ini')
                                ->body('Data kendaraan ini masih dipakai oleh quotation yang terdaftar.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()->title('Kendaraan dihapus')->success()->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // SEBELUMNYA DeleteBulkAction::make() bawaan — beda
                    // dari DeleteAction satuan di atas yang sudah
                    // menangkap QueryException dengan pesan ramah, bulk
                    // delete TIDAK punya proteksi sama sekali. Kalau 1
                    // saja dari baris terpilih masih direferensikan
                    // quotation (onDelete('restrict')), staff dapat error
                    // mentah alih-alih pesan jelas. Custom action ini
                    // proses satu-satu supaya baris yang AMAN tetap
                    // terhapus, baris yang masih dipakai dilewati dengan
                    // laporan jumlahnya. Ditemukan & diperbaiki 2026-08-29,
                    // audit modul Kendaraan.
                    Tables\Actions\BulkAction::make('delete')
                        ->label('Hapus')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Support\Collection $records) {
                            $deleted = 0;
                            $blocked = 0;

                            foreach ($records as $record) {
                                try {
                                    $record->delete();
                                    $deleted++;
                                } catch (QueryException $e) {
                                    $blocked++;
                                }
                            }

                            if ($blocked > 0) {
                                Notification::make()
                                    ->title($deleted > 0
                                        ? "{$deleted} kendaraan dihapus, {$blocked} tidak bisa dihapus"
                                        : 'Tidak ada kendaraan yang bisa dihapus')
                                    ->body("{$blocked} kendaraan masih dipakai oleh quotation yang terdaftar, dilewati.")
                                    ->warning()
                                    ->send();

                                return;
                            }

                            Notification::make()->title("{$deleted} kendaraan dihapus")->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('brand')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVehicles::route('/'),
            'create' => Pages\CreateVehicle::route('/create'),
            'edit' => Pages\EditVehicle::route('/{record}/edit'),
        ];
    }
}