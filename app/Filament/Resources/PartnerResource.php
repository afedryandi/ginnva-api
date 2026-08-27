<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PartnerResource\Pages;
use App\Models\Partner;
use App\Services\QrCodeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

class PartnerResource extends Resource
{
    protected static ?string $model = Partner::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Marketing/Konten';

    protected static ?int $navigationSort = 70;

    protected static ?string $navigationLabel = 'Partner';

    protected static ?string $modelLabel = 'Partner';

    protected static ?string $pluralModelLabel = 'Partner';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Akun Login Partner')
                ->description('Partner login di mobile app pakai email + password ini (endpoint yang sama dengan staff).')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->unique(table: 'users', column: 'email', ignoreRecord: true, modifyRuleUsing: fn ($rule, $record) => $record ? $rule->ignore($record->user_id, 'id') : $rule)
                        ->disabled(fn (?Partner $record) => $record !== null)
                        ->dehydrated()
                        ->helperText(fn (?Partner $record) => $record ? 'Email tidak bisa diubah setelah akun dibuat.' : null),

                    Forms\Components\TextInput::make('password')
                        ->label('Password')
                        ->password()
                        ->revealable()
                        ->required(fn (string $context): bool => $context === 'create')
                        ->dehydrated(fn ($state) => filled($state))
                        ->minLength(8)
                        ->same('passwordConfirmation')
                        ->live(debounce: 500)
                        ->helperText(fn (string $context) => $context === 'create'
                            ? 'Minimal 8 karakter.'
                            : 'Kosongkan kalau tidak ingin ganti password.'),

                    Forms\Components\TextInput::make('passwordConfirmation')
                        ->label('Konfirmasi Password')
                        ->password()
                        ->revealable()
                        ->required(fn (string $context): bool => $context === 'create')
                        ->dehydrated(false),
                ]),

            Forms\Components\Section::make('Data Partner')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('business_name')
                        ->label('Nama Partner / Usaha')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('phone')
                        ->label('No. Telepon / HP')
                        ->tel()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('referral_code')
                        ->label('Kode Referral')
                        ->disabled()
                        ->dehydrated(false)
                        ->visible(fn (?Partner $record) => $record !== null)
                        ->helperText('Kode ini otomatis dibuat sistem — dibagikan partner ke kenalan/customernya.'),

                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options([
                            'active'   => 'Aktif',
                            'inactive' => 'Nonaktif',
                        ])
                        ->required()
                        ->default('active'),

                    Forms\Components\Select::make('type')
                        ->label('Tipe Partner')
                        ->options([
                            'partner'    => 'Partner (Sales/Dealer)',
                            'influencer' => 'Influencer',
                            'komunitas'  => 'Komunitas',
                        ])
                        ->required()
                        ->default('partner'),

                    Forms\Components\Select::make('source')
                        ->label('Sumber Pendaftaran')
                        ->options([
                            'giias'   => 'GIIAS',
                            'partner' => 'Landing Page Umum (/partner)',
                        ])
                        ->placeholder('Dibuat manual admin')
                        ->helperText('Menentukan link mana yang dipakai saat "Unduh QR". Kosongkan kalau partner ini tidak daftar lewat salah satu landing page (mis. influencer/komunitas yang direkrut langsung) — QR yang di-generate tetap akan pakai link /partner (landing page umum) sebagai default, bukan dikosongkan.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('business_name')
                    ->label('Nama Partner')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.email')
                    ->label('Email')
                    ->searchable()
                    // Partner yang daftar sendiri lewat /giias tanpa isi
                    // email dapat placeholder otomatis (lihat
                    // GiiasPartnerSignupController::generatePlaceholderEmail())
                    // supaya admin tidak salah kira itu email asli
                    // partner-nya.
                    ->description(fn ($record) => str_ends_with((string) $record->user?->email, '@no-reply.ginnva.id')
                        ? 'Email otomatis — sales belum isi email asli'
                        : null),

                Tables\Columns\TextColumn::make('referral_code')
                    ->label('Kode Referral')
                    ->badge()
                    ->color('info')
                    ->copyable()
                    ->copyMessage('Kode referral disalin'),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('Tipe')
                    ->colors([
                        'gray'    => 'partner',
                        'warning' => 'influencer',
                        'success' => 'komunitas',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'partner'    => 'Partner',
                        'influencer' => 'Influencer',
                        'komunitas'  => 'Komunitas',
                        default      => $state,
                    })
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('source')
                    ->label('Sumber')
                    ->colors([
                        'gray'    => 'giias',
                        'primary' => 'partner',
                    ])
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'giias'   => 'GIIAS',
                        'partner' => 'Landing Page Umum',
                        default   => 'Manual',
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('points_balance')
                    ->label('Saldo Poin')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'success' => 'active',
                        'danger'  => 'inactive',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active'   => 'Aktif',
                        'inactive' => 'Nonaktif',
                        default    => $state,
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Bergabung')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active'   => 'Aktif',
                        'inactive' => 'Nonaktif',
                    ]),
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipe')
                    ->options([
                        'partner'    => 'Partner',
                        'influencer' => 'Influencer',
                        'komunitas'  => 'Komunitas',
                    ]),
                Tables\Filters\SelectFilter::make('source')
                    ->label('Sumber')
                    ->options([
                        'giias'   => 'GIIAS',
                        'partner' => 'Landing Page Umum',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('download_qr')
                    ->label('Unduh QR')
                    ->icon('heroicon-o-qr-code')
                    ->color('gray')
                    ->action(fn (Partner $record) => static::downloadQrPdf(new Collection([$record]))),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('download_qr_bulk')
                        ->label('Unduh QR (PDF Massal)')
                        ->icon('heroicon-o-qr-code')
                        ->action(fn (Collection $records) => static::downloadQrPdf($records))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Dipakai bareng oleh aksi unduh QR single (1 partner) dan bulk
     * (banyak partner sekaligus) — kartu QR fisik/digital yang bisa
     * langsung di-scan customer ke link referral mereka masing-masing,
     * tanpa perlu tool QR generator eksternal.
     *
     * Link-nya WAJIB dibedakan pakai kolom source ('giias' -> /giias,
     * selain itu -> /partner) — SEBELUMNYA hardcode /giias untuk semua
     * partner, jadi salah arah untuk yang daftar lewat /partner (baru
     * ada sejak landing page umum dibuat).
     */
    private static function downloadQrPdf(Collection $partners)
    {
        $baseUrl = rtrim(config('app.frontend_url', 'https://ginnva.id'), '/');

        $items = SupportCollection::make($partners)->map(function (Partner $partner) use ($baseUrl) {
            $path = $partner->source === 'giias' ? '/giias' : '/partner';
            $link = "{$baseUrl}{$path}?ref={$partner->referral_code}";

            return [
                'name'  => $partner->business_name,
                'meta'  => $partner->phone,
                'code'  => $partner->referral_code,
                'link'  => $link,
                // Dipakai template buat caption per kartu — batch bisa
                // campur partner GIIAS & landing page umum sekaligus,
                // jadi tidak bisa 1 judul statis untuk semua kartu. Untuk
                // source kosong (partner dibuat manual, mis. influencer/
                // komunitas), caption SENGAJA tidak mengklaim "landing
                // page partner" secara pasti — linknya tetap /partner,
                // tapi captionnya jujur bahwa ini fallback, bukan asal
                // pendaftaran yang sebenarnya.
                'label' => match ($partner->source) {
                    'giias' => 'LANDING PAGE GIIAS',
                    'partner' => 'LANDING PAGE PARTNER',
                    default => 'LINK REFERRAL ANDA',
                },
                'qr'    => QrCodeService::generateDataUri($link, 260),
            ];
        });

        $pdf = Pdf::loadView('pdf.giias_qr_batch', ['items' => $items])->setPaper('a4', 'portrait');

        $filename = $partners->count() === 1
            ? "QR-Referral-{$partners->first()->referral_code}.pdf"
            : 'QR-Referral-GIIAS-Batch-' . now()->format('Ymd-His') . '.pdf';

        return response()->streamDownload(fn () => print($pdf->output()), $filename);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPartners::route('/'),
            'create' => Pages\CreatePartner::route('/create'),
            'edit'   => Pages\EditPartner::route('/{record}/edit'),
        ];
    }
}