<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryItemResource\Pages;
use App\Filament\Resources\InventoryItemResource\RelationManagers\MovementsRelationManager;
use App\Models\InventoryItem;
use App\Services\QrCodeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

class InventoryItemResource extends Resource
{
    protected static ?string $model = InventoryItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Inventaris';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Barang';

    protected static ?string $modelLabel = 'Barang';

    protected static ?string $pluralModelLabel = 'Barang';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Detail Barang')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nama Barang')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('category')
                        ->label('Kategori')
                        ->maxLength(255)
                        ->placeholder('Mis. PPF, Kaca Film, Sparepart'),

                    Forms\Components\TextInput::make('code')
                        ->label('Kode / QR')
                        ->disabled()
                        ->dehydrated(false)
                        ->visible(fn (?InventoryItem $record) => $record !== null)
                        ->helperText('Kode ini otomatis dibuat sistem dan tidak bisa diubah — tempel QR-nya ke kardus fisik.'),

                    Forms\Components\Textarea::make('notes')
                        ->label('Catatan')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Kode')
                    ->badge()
                    ->color('info')
                    ->copyable()
                    ->copyMessage('Kode disalin')
                    ->searchable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Barang')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('category')
                    ->label('Kategori')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'success' => 'in_stock',
                        'danger' => 'out',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'in_stock' => 'Ada Stok',
                        'out' => 'Habis',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Didaftarkan')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'in_stock' => 'Ada Stok',
                        'out' => 'Habis',
                    ]),
                Tables\Filters\SelectFilter::make('category')
                    ->label('Kategori')
                    ->options(fn () => InventoryItem::query()
                        ->whereNotNull('category')
                        ->distinct()
                        ->pluck('category', 'category')
                        ->toArray()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('download_qr')
                    ->label('Unduh QR')
                    ->icon('heroicon-o-qr-code')
                    ->color('gray')
                    ->action(fn (InventoryItem $record) => static::downloadQrPdf(new Collection([$record]))),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('download_qr_bulk')
                        ->label('Unduh QR (PDF Massal)')
                        ->icon('heroicon-o-qr-code')
                        ->action(fn (Collection $records) => static::downloadQrPdf($records))
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * QR berisi KODE POLOS (mis. "INV-A1B2C3D4"), bukan URL — beda dari
     * QR referral GIIAS. App mobile staff yang scan langsung query
     * GET /api/staff/inventory/{code} pakai isi QR-nya, tidak perlu buka
     * browser sama sekali (QR ini tidak dimaksudkan untuk publik/customer).
     */
    private static function downloadQrPdf(Collection $items)
    {
        $rows = SupportCollection::make($items)->map(function (InventoryItem $item) {
            return [
                'name' => $item->name,
                'meta' => $item->category,
                'code' => $item->code,
                'qr'   => QrCodeService::generateDataUri($item->code, 260),
            ];
        });

        $pdf = Pdf::loadView('pdf.inventory_qr_batch', ['items' => $rows])->setPaper('a4', 'portrait');

        $filename = $items->count() === 1
            ? "QR-Inventaris-{$items->first()->code}.pdf"
            : 'QR-Inventaris-Batch-' . now()->format('Ymd-His') . '.pdf';

        return response()->streamDownload(fn () => print($pdf->output()), $filename);
    }

    public static function getRelations(): array
    {
        return [
            MovementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListInventoryItems::route('/'),
            'create' => Pages\CreateInventoryItem::route('/create'),
            'edit'   => Pages\EditInventoryItem::route('/{record}/edit'),
        ];
    }
}
