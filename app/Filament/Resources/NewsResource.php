<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NewsResource\Pages;
use App\Models\News;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class NewsResource extends Resource
{
    protected static ?string $model = News::class;

    protected static ?string $navigationIcon = 'heroicon-o-newspaper';

    protected static ?string $navigationGroup = 'Marketing/Konten';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Berita';

    protected static ?string $modelLabel = 'Berita';

    protected static ?string $pluralModelLabel = 'Berita';

    /**
     * Resource ini company-wide (bukan per-toko), jadi tidak ada
     * navigationGroup scoping store di sini — aksesnya diatur lewat
     * NewsPolicy (canAccessStaffArea() + hasMenuAccess()), jadi bisa
     * didelegasikan ke role/staff tertentu lewat "Akses Menu".
     */
    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Konten Berita')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->label('Judul')
                        ->required()
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (string $operation, $state, Forms\Set $set) {
                            if ($operation === 'create') {
                                $set('slug', Str::slug($state));
                            }
                        })
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('slug')
                        ->label('Slug')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),

                    Forms\Components\TextInput::make('source_url')
                        ->label('Link Sumber (opsional, kalau berita hanya link keluar)')
                        ->url()
                        ->maxLength(255),

                    Forms\Components\Textarea::make('excerpt')
                        ->label('Ringkasan Singkat')
                        ->columnSpanFull(),

                    Forms\Components\RichEditor::make('content')
                        ->label('Isi Berita')
                        ->columnSpanFull(),

                    Forms\Components\FileUpload::make('cover_image')
                        ->label('Gambar Cover')
                        ->image()
                        ->directory('news')
                        ->maxSize(2048)
                        ->helperText('Maks. 2 MB. Format: JPG, PNG, WebP.')
                        ->columnSpanFull(),

                    Forms\Components\Hidden::make('author_id')
                        ->default(fn () => auth()->id()),

                    Forms\Components\Toggle::make('is_published')
                        ->label('Publikasikan')
                        ->live()
                        ->default(false),

                    Forms\Components\DateTimePicker::make('published_at')
                        ->label('Tanggal Publish')
                        ->default(now())
                        ->visible(fn (Forms\Get $get) => $get('is_published')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Judul')
                    ->searchable()
                    ->limit(50),

                // SEBELUMNYA cuma IconColumn boolean is_published — admin
                // bisa salah kira artikel sudah tayang ke publik padahal
                // masih terjadwal masa depan (API publik NewsController
                // sudah benar menyembunyikannya sampai published_at
                // terlewati, tapi tabel ini tidak pernah membedakannya
                // secara visual, cuma kelihatan kalau baca kolom tanggal
                // dengan teliti). Ditemukan saat audit modul Marketing > Berita.
                Tables\Columns\BadgeColumn::make('status_tayang')
                    ->label('Status')
                    ->getStateUsing(function (News $record): string {
                        if (! $record->is_published) return 'draft';
                        if ($record->published_at && $record->published_at->isFuture()) return 'scheduled';
                        return 'live';
                    })
                    ->colors([
                        'gray'    => 'draft',
                        'warning' => 'scheduled',
                        'success' => 'live',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'draft'     => 'Draft',
                        'scheduled' => 'Terjadwal',
                        'live'      => 'Tayang',
                        default     => $state,
                    }),

                Tables\Columns\TextColumn::make('published_at')
                    ->label('Tanggal Publish')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('author.name')
                    ->label('Penulis')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_published')
                    ->label('Status Publish'),
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
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNews::route('/'),
            'create' => Pages\CreateNews::route('/create'),
            'edit' => Pages\EditNews::route('/{record}/edit'),
        ];
    }
}
