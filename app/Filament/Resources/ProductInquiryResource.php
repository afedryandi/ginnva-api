<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductInquiryResource\Pages;
use App\Models\ProductInquiry;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProductInquiryResource extends Resource
{
    protected static ?string $model = ProductInquiry::class;

    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $navigationGroup = 'Marketing/Konten';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Inquiry Produk';

    protected static ?string $modelLabel = 'Inquiry';

    protected static ?string $pluralModelLabel = 'Inquiry Produk';

    protected static ?string $navigationBadgeColor = 'warning';

    /**
     * Badge angka di sidebar menunjukkan jumlah inquiry yang belum
     * di-follow up (status new), supaya admin langsung tahu ada yang
     * perlu ditindak tanpa harus klik masuk dulu.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::where('status', 'new')->count();

        return $count > 0 ? (string) $count : null;
    }

    /**
     * Tidak ada scope per-toko di sini (sesuai keputusan: inquiry produk
     * yang belum tersedia sifatnya nasional, bukan milik toko tertentu),
     * jadi semua admin (super_admin & staff toko) melihat data yang sama.
     */
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function canCreate(): bool
    {
        // Inquiry hanya masuk lewat form publik di web (POST /api/inquiry/submit),
        // admin tidak perlu/tidak boleh membuat inquiry palsu dari panel.
        return false;
    }

    public static function canDelete($record): bool
    {
        // Sama seperti Warranty/Quotation — hapus data dibatasi super_admin saja.
        return auth()->user()?->isFullAccess() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Data Inquiry')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('inquiry_number')
                        ->label('No. Inquiry')
                        ->disabled(),

                    Forms\Components\TextInput::make('customer_name')
                        ->label('Nama Customer')
                        ->disabled(),

                    Forms\Components\TextInput::make('customer_contact')
                        ->label('Kontak (Telepon/Email)')
                        ->disabled(),

                    Forms\Components\Select::make('status')
                        ->label('Status Follow-up')
                        ->options([
                            'new' => 'Baru',
                            'contacted' => 'Sudah Dihubungi',
                            'closed' => 'Selesai',
                        ])
                        ->required(),

                    Forms\Components\Textarea::make('message')
                        ->label('Pertanyaan dari Customer')
                        ->disabled()
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('notes')
                        ->label('Catatan Internal Sales')
                        ->placeholder('Catatan follow-up, hasil komunikasi, dll — tidak terlihat oleh customer.')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('inquiry_number')
                    ->label('No. Inquiry')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Nama Customer')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer_contact')
                    ->label('Kontak')
                    ->searchable(),

                Tables\Columns\TextColumn::make('message')
                    ->label('Pertanyaan')
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->message),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'new' => 'warning',
                        'contacted' => 'info',
                        'closed' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'new' => 'Baru',
                        'contacted' => 'Sudah Dihubungi',
                        'closed' => 'Selesai',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Masuk Pada')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'new' => 'Baru',
                        'contacted' => 'Sudah Dihubungi',
                        'closed' => 'Selesai',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Follow Up'),
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
            'index' => Pages\ListProductInquiries::route('/'),
            'edit' => Pages\EditProductInquiry::route('/{record}/edit'),
        ];
    }
}