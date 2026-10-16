<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductClaimResource\Pages;
use App\Models\ProductClaim;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Klaim Produk (Marketing/Konten Fase 2): satu-satunya sumber angka TSER/UV/VLT/garansi yang boleh tayang di web dan aplikasi. Staf
 * menyusun klaim beserta buktinya; hanya akses penuh yang dapat menyetujui. Mengubah klaim yang sudah disetujui mengembalikannya ke draft.
 */
class ProductClaimResource extends Resource
{
    protected static ?string $model = ProductClaim::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $cluster = \App\Filament\Clusters\MarketingKontenCluster::class;

    protected static ?string $navigationGroup = 'Klaim Produk';

    protected static ?string $navigationLabel = 'Klaim & Bukti';

    protected static ?string $modelLabel = 'Klaim Produk';

    protected static ?string $pluralModelLabel = 'Klaim Produk';

    protected static ?int $navigationSort = 50;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function canCreate(): bool
    {
        return static::canViewAny()
            && (auth()->user()?->hasModuleAction(static::class, 'create', true) ?? false);
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny()
            && (auth()->user()?->hasModuleAction(static::class, 'update', true) ?? false);
    }

    public static function canDelete($record): bool
    {
        // Klaim yang pernah tayang tidak dihapus (jejak bukti); cukup ditarik. Hanya draft yang boleh dihapus.
        return $record->status === 'draft'
            && static::canViewAny()
            && (auth()->user()?->hasModuleAction(static::class, 'delete', true) ?? false);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Klaim')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('series')
                        ->label('Seri / Produk')
                        ->required()
                        ->maxLength(60)
                        ->datalist(fn () => ProductClaim::query()->distinct()->pluck('series')->all())
                        ->helperText('Contoh: A70, H70, H30, H15, atau "Umum" untuk klaim yang berlaku ke semua.'),

                    Forms\Components\Select::make('claim_type')
                        ->label('Jenis Klaim')
                        ->options(ProductClaim::TYPES)
                        ->required()
                        ->native(false),

                    Forms\Components\TextInput::make('label')
                        ->label('Teks yang Tampil')
                        ->required()
                        ->maxLength(120)
                        ->helperText('Contoh: "Heat Rejection (TSER)".'),

                    Forms\Components\TextInput::make('value')
                        ->label('Nilai')
                        ->required()
                        ->maxLength(60)
                        ->helperText('Contoh: "61%" atau "10 tahun". Tulis persis seperti yang akan tampil.'),
                ]),

            Forms\Components\Section::make('Bukti')
                ->description('Angka hanya dapat disetujui bila ada berkas bukti atau catatan sumber, dan tanggalnya jelas. Mengubah klaim yang sudah disetujui membuatnya kembali berstatus draft.')
                ->columns(2)
                ->schema([
                    Forms\Components\FileUpload::make('evidence_path')
                        ->label('Berkas Bukti (laporan uji / sertifikat)')
                        ->directory('product-claims')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                        ->maxSize(5120)
                        ->helperText('PDF, JPG, atau PNG. Maks. 5 MB.')
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('source_note')
                        ->label('Catatan Sumber')
                        ->rows(2)
                        ->helperText('Asal angka bila tidak ada berkas. Contoh: "Lembar data pabrikan, revisi Maret 2026".')
                        ->columnSpanFull(),

                    Forms\Components\DatePicker::make('tested_at')
                        ->label('Tanggal Uji / Dokumen')
                        ->native(false),
                ]),

            Forms\Components\Hidden::make('created_by')->default(fn () => auth()->id()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('series')->label('Seri')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('label')->label('Klaim')->searchable(),
                Tables\Columns\TextColumn::make('value')->label('Nilai'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ProductClaim::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success',
                        'withdrawn' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('tested_at')->label('Tanggal Uji')->date('d M Y')->placeholder('—'),
                Tables\Columns\IconColumn::make('evidence')
                    ->label('Bukti')
                    ->boolean()
                    ->state(fn (ProductClaim $record) => filled($record->evidence_path) || filled($record->source_note)),
                Tables\Columns\TextColumn::make('approver.name')->label('Disetujui Oleh')->placeholder('—')->toggleable(),
            ])
            ->defaultSort('series')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Status')->options(ProductClaim::STATUSES),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Setelah disetujui, angka ini boleh tayang di web dan aplikasi.')
                    ->visible(fn (ProductClaim $record) => $record->status !== 'approved' && (auth()->user()?->isFullAccess() ?? false))
                    ->action(function (ProductClaim $record) {
                        if ($blocker = $record->approvalBlocker()) {
                            Notification::make()->title('Belum bisa disetujui')->body($blocker)->danger()->send();

                            return;
                        }

                        $record->approve(auth()->user());
                        Notification::make()->title('Klaim disetujui')->success()->send();
                    }),
                Tables\Actions\Action::make('withdraw')
                    ->label('Tarik')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Angka ini langsung berhenti tayang.')
                    ->visible(fn (ProductClaim $record) => $record->status === 'approved' && (auth()->user()?->isFullAccess() ?? false))
                    ->action(function (ProductClaim $record) {
                        $record->withdraw();
                        Notification::make()->title('Klaim ditarik')->success()->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductClaims::route('/'),
            'create' => Pages\CreateProductClaim::route('/create'),
            'edit' => Pages\EditProductClaim::route('/{record}/edit'),
        ];
    }
}
