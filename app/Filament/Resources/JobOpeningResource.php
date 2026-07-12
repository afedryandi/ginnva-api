<?php

namespace App\Filament\Resources;

use App\Filament\Resources\JobOpeningResource\Pages;
use App\Models\JobOpening;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class JobOpeningResource extends Resource
{
    protected static ?string $model = JobOpening::class;

    protected static ?string $navigationIcon = 'heroicon-o-briefcase';

    protected static ?string $navigationGroup = 'Konten';

    protected static ?string $navigationLabel = 'Lowongan Kerja';

    protected static ?string $modelLabel = 'Lowongan Kerja';

    protected static ?string $pluralModelLabel = 'Lowongan Kerja';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Detail Lowongan')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->label('Nama Posisi')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('department')
                        ->label('Departemen')
                        ->placeholder('mis. Operasional, Penjualan')
                        ->required()
                        ->maxLength(100),

                    Forms\Components\TextInput::make('location')
                        ->label('Lokasi')
                        ->default('PIK 2, Tangerang')
                        ->required()
                        ->maxLength(150),

                    Forms\Components\Select::make('type')
                        ->label('Tipe')
                        ->options([
                            'Full-time' => 'Full-time',
                            'Part-time' => 'Part-time',
                            'Kontrak'   => 'Kontrak',
                            'Magang'    => 'Magang',
                        ])
                        ->default('Full-time')
                        ->required(),

                    Forms\Components\Textarea::make('description')
                        ->label('Deskripsi Pekerjaan')
                        ->rows(4)
                        ->required()
                        ->columnSpanFull(),

                    Forms\Components\Repeater::make('requirements')
                        ->label('Kualifikasi')
                        ->helperText('Tambahkan baris per baris. Setiap baris satu poin kualifikasi.')
                        ->simple(
                            Forms\Components\TextInput::make('item')
                                ->placeholder('mis. Pengalaman minimal 1 tahun')
                                ->required()
                        )
                        ->addActionLabel('Tambah Kualifikasi')
                        ->reorderable()
                        ->columnSpanFull(),

                    Forms\Components\Toggle::make('is_published')
                        ->label('Tampilkan di Website')
                        ->default(true)
                        ->helperText('Matikan untuk menyembunyikan lowongan tanpa menghapusnya.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Posisi')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('department')
                    ->label('Departemen')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('location')
                    ->label('Lokasi')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Tipe')
                    ->badge()
                    ->color('info'),

                Tables\Columns\IconColumn::make('is_published')
                    ->label('Tampil')
                    ->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_published')
                    ->label('Status Tampil'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('sort_order')
            // reorderable() menambahkan drag-handle di tabel — urutan baru
            // langsung tersimpan ke kolom sort_order, mengatur urutan tampil
            // di halaman /karier.
            ->reorderable('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListJobOpenings::route('/'),
            'create' => Pages\CreateJobOpening::route('/create'),
            'edit'   => Pages\EditJobOpening::route('/{record}/edit'),
        ];
    }
}
