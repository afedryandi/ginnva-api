<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CompanyPolicyResource\Pages;
use App\Models\CompanyPolicy;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Kebijakan Perusahaan (Karyawan Fase 3, 2026-10-31): Peraturan Perusahaan, kebijakan, dan SOP yang diunggah sebagai PDF, diterbitkan ke
 * aplikasi karyawan, dan dicatat siapa yang sudah menerimanya. Potongan telat dan Alpha hanya sah bila tertulis di Peraturan Perusahaan
 * yang sudah diterima karyawan. Dokumen yang sudah punya bukti penerimaan terkunci (judul, versi, berkas); untuk versi baru buat dokumen
 * baru lalu cabut terbit versi lama.
 */
class CompanyPolicyResource extends Resource
{
    protected static ?string $model = CompanyPolicy::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'Cuti & Kontrak';

    protected static ?string $navigationLabel = 'Peraturan & Kebijakan';

    protected static ?string $modelLabel = 'Dokumen Kebijakan';

    protected static ?string $pluralModelLabel = 'Peraturan & Kebijakan';

    protected static ?int $navigationSort = 320;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function canCreate(): bool
    {
        return static::canViewAny() && (auth()->user()?->hasModuleAction(static::class, 'create', true) ?? false);
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny() && (auth()->user()?->hasModuleAction(static::class, 'update', true) ?? false);
    }

    public static function canDelete($record): bool
    {
        return ! $record->acknowledgments()->exists()
            && static::canViewAny()
            && (auth()->user()?->hasModuleAction(static::class, 'delete', true) ?? false);
    }

    public static function form(Form $form): Form
    {
        $locked = fn (?CompanyPolicy $record) => $record?->acknowledgments()->exists() ?? false;

        return $form->schema([
            Forms\Components\Section::make('Dokumen')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('title')->label('Judul')->required()->maxLength(160)->disabled($locked)->dehydrated(),

                    Forms\Components\Select::make('category')
                        ->label('Jenis')
                        ->options(CompanyPolicy::CATEGORIES)
                        ->required()
                        ->native(false)
                        ->disabled($locked)
                        ->dehydrated(),

                    Forms\Components\TextInput::make('version')->label('Versi')->maxLength(40)->placeholder('mis. 1.0 atau Oktober 2026')->disabled($locked)->dehydrated(),

                    Forms\Components\DatePicker::make('effective_from')->label('Berlaku Sejak')->disabled($locked)->dehydrated(),

                    Forms\Components\FileUpload::make('file_path')
                        ->label('Berkas (PDF)')
                        ->disk('local')
                        ->visibility('private')
                        ->directory('company-policies')
                        ->acceptedFileTypes(['application/pdf'])
                        ->maxSize(20480)
                        ->storeFileNamesIn('original_filename')
                        ->disabled($locked)
                        ->dehydrated()
                        ->helperText('Disimpan privat; karyawan membukanya lewat aplikasi dengan tautan sementara.')
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('description')->label('Ringkasan untuk karyawan')->rows(3)->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Penerbitan')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('requires_acknowledgment')
                        ->label('Karyawan wajib menyatakan menerima')
                        ->default(true)
                        ->helperText('Aktifkan untuk Peraturan Perusahaan dan kebijakan yang mengikat (mis. aturan potongan).'),

                    Forms\Components\Toggle::make('is_published')
                        ->label('Terbit di aplikasi karyawan')
                        ->helperText('Hanya dokumen terbit yang terlihat karyawan. Cabut terbit versi lama setelah versi baru terbit.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Judul')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('category')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => CompanyPolicy::CATEGORIES[$state] ?? $state),
                Tables\Columns\TextColumn::make('version')->label('Versi')->placeholder('—'),
                Tables\Columns\TextColumn::make('effective_from')->label('Berlaku sejak')->date('d M Y')->placeholder('—'),
                Tables\Columns\IconColumn::make('is_published')->label('Terbit')->boolean(),
                Tables\Columns\TextColumn::make('acknowledgments_count')
                    ->label('Sudah menerima')
                    ->state(function (CompanyPolicy $record) {
                        if (! $record->requires_acknowledgment) {
                            return 'Tidak wajib';
                        }

                        $total = CompanyPolicy::audienceQuery()->count();

                        return $record->acknowledgments()->count() . ' dari ' . $total;
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('category')->label('Jenis')->options(CompanyPolicy::CATEGORIES),
                Tables\Filters\TernaryFilter::make('is_published')->label('Terbit'),
            ])
            ->actions([
                Tables\Actions\Action::make('pending')
                    ->label('Belum menerima')
                    ->icon('heroicon-o-user-minus')
                    ->color('warning')
                    ->visible(fn (CompanyPolicy $record) => $record->requires_acknowledgment)
                    ->modalHeading(fn (CompanyPolicy $record) => 'Belum menerima: ' . $record->title)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->modalContent(function (CompanyPolicy $record) {
                        $names = $record->pendingUsers()->orderBy('name')->pluck('name');

                        return new HtmlString($names->isEmpty()
                            ? '<p>Semua karyawan aktif sudah menerima dokumen ini.</p>'
                            : '<ul style="list-style:disc;padding-left:1.25rem">' . $names->map(fn ($n) => '<li>' . e($n) . '</li>')->implode('') . '</ul>');
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompanyPolicies::route('/'),
            'create' => Pages\CreateCompanyPolicy::route('/create'),
            'edit' => Pages\EditCompanyPolicy::route('/{record}/edit'),
        ];
    }
}
