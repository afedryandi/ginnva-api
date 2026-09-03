<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MaterialResource\Pages;
use App\Models\Material;
use App\Models\MaterialCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class MaterialResource extends Resource
{
    protected static ?string $model = Material::class;

    protected static ?string $navigationIcon = 'heroicon-o-folder-arrow-down';

    protected static ?string $navigationGroup = 'Marketing/Konten';

    protected static ?string $navigationLabel = 'Materi Download';

    protected static ?string $modelLabel = 'Materi';

    protected static ?string $pluralModelLabel = 'Materi Download';

    protected static ?int $navigationSort = 45;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Detail Materi')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('material_category_id')
                        ->label('Kategori')
                        ->options(fn () => MaterialCategory::orderBy('sort_order')->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->createOptionForm([
                            Forms\Components\TextInput::make('name')
                                ->label('Nama Kategori')
                                ->required()
                                ->unique('material_categories', 'name')
                                ->maxLength(255),
                        ])
                        ->createOptionUsing(function (array $data) {
                            $last = MaterialCategory::max('sort_order') ?? 0;
                            return MaterialCategory::create([
                                'name'       => $data['name'],
                                'sort_order' => $last + 1,
                            ])->id;
                        }),

                    Forms\Components\TextInput::make('name')
                        ->label('Nama File / Dokumen')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\FileUpload::make('file')
                        ->label('Upload File')
                        ->directory('materials')
                        ->acceptedFileTypes([
                            'application/pdf',
                            'application/zip',
                            'application/x-zip-compressed',
                            'image/jpeg',
                            'image/png',
                            'image/webp',
                            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                            'application/vnd.ms-powerpoint',
                        ])
                        ->maxSize(51200)
                        ->helperText('Maks. 50 MB. Format: PDF, ZIP, JPG, PNG, PPTX.')
                        ->required()
                        ->columnSpanFull()
                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                            if (! $state) return;

                            $path = is_array($state) ? reset($state) : $state;
                            $set('file_type', pathinfo($path, PATHINFO_EXTENSION));

                            $fullPath = Storage::disk('public')->path($path);
                            if (file_exists($fullPath)) {
                                $set('file_size', filesize($fullPath));
                            }
                        })
                        ->live(),

                    Forms\Components\Hidden::make('file_type'),
                    Forms\Components\Hidden::make('file_size'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Tampilkan ke Publik')
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Materi')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('category.name')
                    ->label('Kategori')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('file_type')
                    ->label('Tipe')
                    ->badge()
                    ->color(fn (?string $state): string => match (strtolower($state ?? '')) {
                        'pdf'          => 'danger',
                        'zip'          => 'warning',
                        'jpg', 'jpeg',
                        'png', 'webp'  => 'info',
                        'pptx', 'ppt' => 'warning',
                        default        => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state) => strtoupper($state ?? '—')),

                Tables\Columns\TextColumn::make('file_size_formatted')
                    ->label('Ukuran')
                    ->state(fn (Material $record) => $record->file_size_formatted),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Publik')
                    ->boolean(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('material_category_id')
                    ->label('Kategori')
                    ->options(fn () => MaterialCategory::pluck('name', 'id')),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status'),
            ])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->url(fn (Material $record) => Storage::disk('public')->url($record->file))
                    ->openUrlInNewTab(),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            // SEBELUMNYA tidak ada cara sama sekali bagi admin mengatur
            // sort_order — kolomnya aktif dipakai query publik
            // (MaterialController::index() -> orderBy('sort_order')),
            // tapi selalu bernilai 0 (default kolom) karena tidak pernah
            // diisi form/tabel manapun. Filter Kategori di atas dipakai
            // dulu supaya drag-reorder ini masuk akal (urutan cuma
            // relevan DALAM 1 kategori, lihat CreateMaterial).
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->groups([
                Tables\Grouping\Group::make('category.name')
                    ->label('Kategori')
                    ->collapsible(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListMaterials::route('/'),
            'create' => Pages\CreateMaterial::route('/create'),
            'edit'   => Pages\EditMaterial::route('/{record}/edit'),
        ];
    }
}