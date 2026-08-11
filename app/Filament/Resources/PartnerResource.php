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

    protected static ?string $navigationGroup = 'Partnership Referral';

    protected static ?int $navigationSort = 10;

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
     * (banyak partner sekaligus) — supaya sales GIIAS punya kartu QR
     * fisik/digital yang bisa langsung di-scan customer ke link
     * referral mereka masing-masing (https://ginnva.id/giias?ref=KODE),
     * tanpa perlu tool QR generator eksternal.
     */
    private static function downloadQrPdf(Collection $partners)
    {
        $baseUrl = rtrim(config('app.frontend_url', 'https://ginnva.id'), '/');

        $items = SupportCollection::make($partners)->map(function (Partner $partner) use ($baseUrl) {
            $link = "{$baseUrl}/giias?ref={$partner->referral_code}";

            return [
                'name' => $partner->business_name,
                'meta' => $partner->phone,
                'code' => $partner->referral_code,
                'link' => $link,
                'qr'   => QrCodeService::generateDataUri($link, 260),
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
