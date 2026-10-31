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

    protected static ?string $navigationGroup = 'Pengaturan Karyawan';

    protected static ?string $navigationLabel = 'Peraturan & Kebijakan';

    protected static ?string $modelLabel = 'Dokumen Kebijakan';

    protected static ?string $pluralModelLabel = 'Peraturan & Kebijakan';

    protected static ?int $navigationSort = 606;

    /**
     * Peraturan Perusahaan menjadi dasar sah potongan telat dan Alpha, dan daftar "belum menerima" memuat nama karyawan. Akun baru (menu_access
     * kosong) tidak boleh membukanya, apalagi menerbitkan atau mencabut terbit aturan: hanya akses penuh atau staf yang menunya dicentang
     * eksplisit, dan membuat, mengubah, serta menghapus masing-masing butuh izin aksi eksplisit (audit Peraturan & Kebijakan, 2026-10-31).
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasExplicitMenuAccess(static::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny() && (auth()->user()?->hasModuleAction(static::class, 'create', false) ?? false);
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny() && (auth()->user()?->hasModuleAction(static::class, 'update', false) ?? false);
    }

    public static function canDelete($record): bool
    {
        // acknowledgments_exists ikut dimuat di daftar (getEloquentQuery) supaya tidak ada query per baris; model biasa memeriksa langsung.
        $hasAcknowledgments = array_key_exists('acknowledgments_exists', $record->getAttributes())
            ? (bool) $record->acknowledgments_exists
            : $record->acknowledgments()->exists();

        return ! $hasAcknowledgments
            && static::canViewAny()
            && (auth()->user()?->hasModuleAction(static::class, 'delete', false) ?? false);
    }

    /**
     * Dipanggil halaman Buat/Ubah setelah simpan: mencabut terbit versi yang digantikan, dan memberi tahu karyawan bila dokumen ini baru
     * terbit (tidak diumumkan ulang pada koreksi dokumen yang sudah terbit sebelumnya).
     */
    public static function afterPublish(CompanyPolicy $policy, ?int $replacesId, bool $wasPublished): void
    {
        if ($replacesId && $policy->is_published && $replacesId !== $policy->id) {
            CompanyPolicy::published()->whereKey($replacesId)->first()?->update(['is_published' => false]);
        }

        if ($policy->is_published && ! $wasPublished) {
            $policy->announce();
        }
    }

    /** Toko yang boleh dilihat daftar penerimaannya: akses penuh semua toko (null), selain itu hanya toko akun ini. */
    public static function audienceStoreId(): ?int
    {
        $user = auth()->user();

        return $user && ! $user->isFullAccess() ? (int) $user->store_id : null;
    }

    /**
     * Jumlah penerima dihitung SEKALI untuk seluruh daftar lewat subquery (bukan dua query per baris), hanya dari karyawan aktif non-mitra
     * dan, untuk akun non-akses-penuh, hanya tokonya sendiri, jadi angka tidak bisa melebihi total.
     */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $storeId = static::audienceStoreId();

        return parent::getEloquentQuery()
            ->select('company_policies.*')
            ->withExists('acknowledgments')
            ->addSelect([
                'accepted_count' => \App\Models\PolicyAcknowledgment::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('policy_acknowledgments.company_policy_id', 'company_policies.id')
                    ->whereIn('user_id', CompanyPolicy::audienceQuery($storeId)->select('users.id')),
                'audience_total' => CompanyPolicy::audienceQuery($storeId)->selectRaw('count(*)'),
            ]);
    }

    public static function form(Form $form): Form
    {
        $locked = fn (?CompanyPolicy $record) => $record?->acknowledgments()->exists() ?? false;

        return $form->schema([
            Forms\Components\Section::make('Dokumen')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('title')->label('Judul')->required()->maxLength(160)->disabled($locked)->dehydrated(fn (?CompanyPolicy $record) => ! $locked($record)),

                    Forms\Components\Select::make('category')
                        ->label('Jenis')
                        ->options(CompanyPolicy::CATEGORIES)
                        ->required()
                        ->native(false)
                        ->disabled($locked)
                        ->dehydrated(fn (?CompanyPolicy $record) => ! $locked($record)),

                    Forms\Components\TextInput::make('version')->label('Versi')->maxLength(40)->placeholder('mis. 1.0 atau Oktober 2026')->disabled($locked)->dehydrated(fn (?CompanyPolicy $record) => ! $locked($record)),

                    Forms\Components\DatePicker::make('effective_from')->label('Berlaku Sejak')->disabled($locked)->dehydrated(fn (?CompanyPolicy $record) => ! $locked($record)),

                    Forms\Components\FileUpload::make('file_path')
                        ->label('Berkas (PDF)')
                        ->disk('local')
                        ->visibility('private')
                        ->directory('company-policies')
                        ->acceptedFileTypes(['application/pdf'])
                        ->maxSize(20480)
                        ->storeFileNamesIn('original_filename')
                        ->required(fn (Forms\Get $get, ?CompanyPolicy $record) => ! ($record?->acknowledgments()->exists() ?? false) && (bool) $get('is_published'))
                        ->disabled($locked)
                        ->dehydrated(fn (?CompanyPolicy $record) => ! $locked($record))
                        ->helperText('Wajib sebelum dokumen diterbitkan. Disimpan privat; karyawan membukanya lewat aplikasi dengan tautan sementara.')
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('description')->label('Ringkasan untuk karyawan')->rows(3)->disabled($locked)->dehydrated(fn (?CompanyPolicy $record) => ! $locked($record))->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Penerbitan')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('requires_acknowledgment')
                        ->label('Karyawan wajib menyatakan menerima')
                        ->default(true)
                        ->disabled($locked)
                        ->dehydrated(fn (?CompanyPolicy $record) => ! $locked($record))
                        ->helperText(fn (?CompanyPolicy $record) => ($record?->acknowledgments()->exists() ?? false)
                            ? 'Terkunci karena sudah ada karyawan yang menerima dokumen ini.'
                            : 'Aktifkan untuk Peraturan Perusahaan dan kebijakan yang mengikat (mis. aturan potongan).'),

                    Forms\Components\Select::make('replaces')
                        ->label('Menggantikan versi lama')
                        ->options(fn (?CompanyPolicy $record) => CompanyPolicy::published()
                            ->when($record, fn ($q) => $q->whereKeyNot($record->id))
                            ->orderBy('title')
                            ->get()
                            ->mapWithKeys(fn (CompanyPolicy $p) => [$p->id => $p->title . ($p->version ? " ({$p->version})" : '')])
                            ->all())
                        ->native(false)
                        ->searchable()
                        ->placeholder('Tidak menggantikan apa pun')
                        ->visible(fn (Forms\Get $get) => (bool) $get('is_published'))
                        ->dehydrated(false)
                        ->helperText('Versi yang dipilih otomatis dicabut dari terbit begitu dokumen ini tersimpan. Bukti penerimaannya tetap utuh.')
                        ->columnSpanFull(),

                    Forms\Components\Toggle::make('is_published')
                        ->label('Terbit di aplikasi karyawan')
                        ->live()
                        ->helperText('Hanya dokumen terbit yang terlihat karyawan. Cabut terbit versi lama setelah versi baru terbit. Dokumen terbit wajib punya berkas PDF.'),
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

                        return (int) $record->accepted_count . ' dari ' . (int) $record->audience_total;
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
                        $names = $record->pendingUsers(static::audienceStoreId())->orderBy('name')->pluck('name');

                        return new HtmlString($names->isEmpty()
                            ? '<p>Semua karyawan aktif sudah menerima dokumen ini.</p>'
                            : '<ul style="list-style:disc;padding-left:1.25rem">' . $names->map(fn ($n) => '<li>' . e($n) . '</li>')->implode('') . '</ul>');
                    }),
                Tables\Actions\Action::make('remind')
                    ->label('Ingatkan')
                    ->icon('heroicon-o-bell-alert')
                    ->color('gray')
                    ->visible(fn (CompanyPolicy $record) => $record->is_published && $record->requires_acknowledgment && static::canEdit($record))
                    ->requiresConfirmation()
                    ->modalHeading(fn (CompanyPolicy $record) => 'Ingatkan yang belum menerima: ' . $record->title)
                    ->modalDescription(fn (CompanyPolicy $record) => $record->pendingUsers(static::audienceStoreId())->count() . ' karyawan aktif belum menerima dokumen ini. Mereka akan menerima notifikasi di aplikasi.')
                    ->action(function (CompanyPolicy $record) {
                        $count = $record->remindPending(static::audienceStoreId());

                        \Filament\Notifications\Notification::make()
                            ->title($count > 0 ? "{$count} karyawan diingatkan" : 'Semua karyawan sudah menerima dokumen ini')
                            ->{$count > 0 ? 'success' : 'info'}()
                            ->send();
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
