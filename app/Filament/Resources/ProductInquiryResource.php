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

    protected static ?string $cluster = \App\Filament\Clusters\MarketingKontenCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- band 300-399 "Inquiry & Kemitraan".
    protected static ?string $navigationGroup = 'Inquiry & Kemitraan';

    protected static ?int $navigationSort = -3;

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

    // Lead manual (mis. dari WhatsApp, telepon, atau datang langsung) dicatat staf di sini; lead dari formulir publik tetap masuk lewat API.
    public static function canCreate(): bool
    {
        return static::canViewAny()
            && (auth()->user()?->hasModuleAction(static::class, 'create', true) ?? false);
    }

    /**
     * SEBELUMNYA tidak ada — canEdit() bawaan Resource selalu FALSE
     * tanpa Policy, jadi EditAction (satu-satunya cara staff menindak-
     * lanjuti/memperbarui status inquiry ini) tidak pernah muncul.
     * Ditemukan lewat sapu bersih 2026-09-14 (audit framework,
     * "Otorisasi default-deny").
     */
    public static function canEdit($record): bool
    {
        return static::canViewAny()
            && (auth()->user()?->hasModuleAction(static::class, 'update', true) ?? false);
    }

    public static function canDelete($record): bool
    {
        // Sama seperti Warranty/Quotation — hapus data dibatasi super_admin
        // saja, KECUALI diberi izin granular eksplisit (audit Majoo f64,
        // 2026-09-23, default FALSE).
        $user = auth()->user();

        return $user?->isFullAccess()
            || ($user?->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'delete', false));
    }

    // Kolom Penanggung Jawab memuat relasi owner: di-eager-load supaya tidak N+1 per baris.
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with('owner');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Data Inquiry')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('inquiry_number')
                        ->label('No. Inquiry')
                        ->visible(fn (string $operation) => $operation === 'edit')
                        ->disabled(),

                    Forms\Components\TextInput::make('customer_name')
                        ->label('Nama Customer')
                        ->required(fn (string $operation) => $operation === 'create')
                        ->maxLength(255)
                        ->disabled(fn (string $operation) => $operation === 'edit'),

                    Forms\Components\TextInput::make('customer_contact')
                        ->label('Kontak (Telepon/Email)')
                        ->required(fn (string $operation) => $operation === 'create')
                        ->maxLength(255)
                        ->disabled(fn (string $operation) => $operation === 'edit'),

                    Forms\Components\Select::make('channel')
                        ->label('Kanal Masuk')
                        ->options(array_intersect_key(\App\Support\LeadAttribution::CHANNELS, array_flip(\App\Support\LeadAttribution::MANUAL_CHANNELS)))
                        ->required()
                        ->visible(fn (string $operation) => $operation === 'create')
                        ->helperText('Dari mana lead ini datang. Lead dari formulir web dan aplikasi terisi otomatis.'),

                    Forms\Components\Select::make('interest')
                        ->label('Jenis Minat')
                        ->options(['ppf' => 'PPF', 'kaca_film' => 'Kaca Film', 'detailing' => 'Detailing', 'premium_wash' => 'Premium Wash', 'color_change' => 'Color Change', 'kaca_bangunan' => 'Kaca Bangunan', 'lainnya' => 'Lainnya'])
                        ->placeholder('Belum jelas')
                        ->disabled(fn (string $operation) => $operation === 'edit'),

                    Forms\Components\Select::make('status')
                        ->label('Status Follow-up')
                        ->options([
                            'new' => 'Baru',
                            'contacted' => 'Sudah Dihubungi',
                            'closed' => 'Selesai',
                        ])
                        ->default('new')
                        ->required(),

                    Forms\Components\Select::make('owner_user_id')
                        ->label('Penanggung Jawab')
                        ->options(fn () => \App\Models\User::query()
                            ->where('is_active', true)
                            ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', ['installer', 'partner']))
                            ->orderBy('name')
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->placeholder('Belum ditentukan')
                        ->default(fn (string $operation) => $operation === 'create' ? auth()->id() : null),

                    Forms\Components\Select::make('outcome')
                        ->label('Hasil')
                        ->options(\App\Support\LeadOutcome::OUTCOMES)
                        ->placeholder('Belum ada hasil')
                        ->live()
                        ->helperText('Tandai Deal bila lead sudah jadi pelanggan/booking/partner, Tidak Jadi bila batal.'),

                    Forms\Components\Select::make('outcome_reason')
                        ->label('Alasan Tidak Jadi')
                        ->options(\App\Support\LeadOutcome::REASONS)
                        ->visible(fn (Forms\Get $get) => $get('outcome') === 'lost')
                        ->required(fn (Forms\Get $get) => $get('outcome') === 'lost'),

                    Forms\Components\Textarea::make('message')
                        ->label('Pertanyaan dari Customer')
                        ->maxLength(2000)
                        ->disabled(fn (string $operation) => $operation === 'edit')
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('notes')
                        ->label('Catatan Internal Sales')
                        ->placeholder('Catatan follow-up, hasil komunikasi, dll — tidak terlihat oleh customer.')
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Sumber Lead')
                ->description('Dari mana lead ini datang (otomatis dari situs/aplikasi; lead manual hanya punya kanal).')
                ->visible(fn (string $operation) => $operation === 'edit')
                ->columns(2)
                ->schema([
                    Forms\Components\Placeholder::make('channel_label')->label('Kanal')
                        ->content(fn (?\App\Models\ProductInquiry $record) => \App\Support\LeadAttribution::CHANNELS[$record?->channel] ?? 'Tidak tercatat'),
                    Forms\Components\Placeholder::make('utm_campaign_label')->label('Kampanye (utm_campaign)')->content(fn (?\App\Models\ProductInquiry $record) => $record?->utm_campaign ?: '—'),
                    Forms\Components\Placeholder::make('utm_source_label')->label('Sumber (utm_source / medium)')->content(fn (?\App\Models\ProductInquiry $record) => trim(($record?->utm_source ?: '—') . ' / ' . ($record?->utm_medium ?: '—'))),
                    Forms\Components\Placeholder::make('utm_content_label')->label('Materi (utm_content)')->content(fn (?\App\Models\ProductInquiry $record) => $record?->utm_content ?: '—'),
                    Forms\Components\Placeholder::make('landing_label')->label('Halaman asal')->content(fn (?\App\Models\ProductInquiry $record) => $record?->landing_path ?: '—'),
                    Forms\Components\Placeholder::make('referrer_label')->label('Perujuk')->content(fn (?\App\Models\ProductInquiry $record) => $record?->referrer ?: '—'),
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

                Tables\Columns\TextColumn::make('channel')
                    ->label('Kanal')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => \App\Support\LeadAttribution::CHANNELS[$state] ?? 'Tidak tercatat')
                    ->color(fn (?string $state) => $state ? 'info' : 'gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('utm_campaign')
                    ->label('Kampanye')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('owner.name')
                    ->label('Penanggung Jawab')
                    ->placeholder('Belum ditentukan')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('outcome')
                    ->label('Hasil')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state === 'won' ? 'Deal' : ($state === 'lost' ? 'Tidak Jadi' : '—'))
                    ->color(fn (?string $state) => match ($state) { 'won' => 'success', 'lost' => 'danger', default => 'gray' })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('interest')
                    ->label('Minat')
                    ->formatStateUsing(fn (?string $state) => ['ppf' => 'PPF', 'kaca_film' => 'Kaca Film', 'detailing' => 'Detailing', 'premium_wash' => 'Premium Wash', 'color_change' => 'Color Change', 'kaca_bangunan' => 'Kaca Bangunan', 'lainnya' => 'Lainnya'][$state] ?? '—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Masuk Pada')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('channel')
                    ->label('Kanal')
                    ->options(\App\Support\LeadAttribution::CHANNELS),
                Tables\Filters\SelectFilter::make('outcome')
                    ->label('Hasil')
                    ->options(\App\Support\LeadOutcome::OUTCOMES),
                Tables\Filters\SelectFilter::make('owner_user_id')
                    ->label('Penanggung Jawab')
                    ->relationship('owner', 'name'),
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
            'create' => Pages\CreateProductInquiry::route('/create'),
            'edit' => Pages\EditProductInquiry::route('/{record}/edit'),
        ];
    }
}