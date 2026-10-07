<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RecurringBillTemplateResource\Pages;
use App\Filament\Resources\RecurringBillTemplateResource\RelationManagers;
use App\Models\ChartOfAccount;
use App\Models\RecurringBillTemplate;
use App\Models\Store;
use App\Models\Supplier;
use App\Services\PayableService;
use App\Services\RecurringBillGenerationService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * "Template tagihan rutin yg auto-generate Biaya/AP" (audit Majoo,
 * f48), dibangun 2026-09-22 atas keputusan user. Membuat/mengubah template
 * TERBATAS full-access -- template ini memposting jurnal baru otomatis tiap
 * bulan. Melihat daftar bisa diberikan lewat Hak Akses Detail ('view', default
 * ditolak).
 *
 * Generate sungguhan lewat App\Console\Commands\GenerateRecurringBills
 * (terjadwal harian, lihat routes/console.php) atau tombol "Jalankan Sekarang".
 *
 * Audit Template Tagihan Rutin 2026-09-29: status eksekusi terakhir, guard hapus,
 * validasi akun/jadwal di server, master Supplier, masa berlaku & batas jumlah,
 * jeda/lewati bulan, pratinjau jadwal, riwayat tagihan, filter & ringkasan komitmen.
 */
class RecurringBillTemplateResource extends Resource
{
    protected static ?string $model = RecurringBillTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- band 0-99 "Transaksi".
    protected static ?string $navigationGroup = 'Transaksi';

    protected static ?string $navigationLabel = 'Template Tagihan Rutin';

    protected static ?string $modelLabel = 'Template Tagihan Rutin';

    protected static ?string $pluralModelLabel = 'Template Tagihan Rutin';

    protected static ?int $navigationSort = 1;

    private static function accessGate(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    /** Baca-saja untuk non-full-access hanya kalau diberi izin 'view' eksplisit (default FALSE). */
    private static function canViewOnly(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class)
            && $user->hasModuleAction(static::class, 'view', false);
    }

    public static function canViewAny(): bool
    {
        return static::accessGate() || static::canViewOnly();
    }

    public static function canCreate(): bool
    {
        return static::accessGate();
    }

    public static function canView($record): bool
    {
        return static::accessGate() || static::canViewOnly();
    }

    public static function canEdit($record): bool
    {
        return static::accessGate();
    }

    public static function canDelete($record): bool
    {
        return static::accessGate();
    }

    /** Tanpa bulk delete: template auto-post tiap bulan, tidak boleh hilang massal. */
    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['store', 'chartOfAccount'])
            ->withCount('generatedPayables');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nama Template')
                ->placeholder('mis. Sewa Toko Bulanan, Langganan Software XYZ')
                ->required()
                ->maxLength(150),

            Forms\Components\Select::make('supplier_id')
                ->label('Supplier / Penerima')
                ->options(fn () => Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->required()
                ->live()
                ->createOptionForm([
                    Forms\Components\TextInput::make('name')->label('Nama Supplier')->required()->maxLength(255),
                ])
                ->createOptionUsing(fn (array $data) => Supplier::findOrCreateByName($data['name'], $data)->getKey())
                ->afterStateUpdated(fn ($state, Forms\Set $set) => $state ? $set('supplier_name', Supplier::whereKey($state)->value('name')) : null),

            // Snapshot nama supplier (diisi otomatis dari pilihan di atas).
            Forms\Components\Hidden::make('supplier_name')
                ->dehydrateStateUsing(fn ($state, Forms\Get $get) => $get('supplier_id') ? Supplier::whereKey($get('supplier_id'))->value('name') : $state),

            Forms\Components\Select::make('store_id')
                ->label('Toko (opsional)')
                ->options(fn () => Store::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->placeholder('Tidak terikat 1 toko (perusahaan)')
                ->searchable(),

            Forms\Components\Select::make('chart_of_account_id')
                ->label('Akun Beban (Debit)')
                ->options(fn () => ChartOfAccount::where('is_active', true)->where('is_postable', true)
                    ->whereIn('type', RecurringBillTemplate::EXPENSE_TYPES)
                    ->orderBy('code')
                    ->get()
                    ->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name]))
                ->required()
                ->searchable()
                // Validasi di SERVER (bukan cuma opsi dropdown): akun harus aktif, postable, dan bertipe beban.
                ->rules([
                    fn () => Rule::exists('chart_of_accounts', 'id')->where(fn ($query) => $query
                        ->where('is_active', true)
                        ->where('is_postable', true)
                        ->whereIn('type', RecurringBillTemplate::EXPENSE_TYPES)),
                ])
                ->helperText('Akun yang didebit tiap kali tagihan ini di-generate (Kredit-nya selalu 2110 Hutang Usaha).'),

            Forms\Components\TextInput::make('amount')
                ->label('Nominal per Periode')
                ->numeric()
                ->prefix('Rp')
                ->minValue(0.01)
                ->maxValue(PayableService::MAX_AMOUNT)
                ->required(),

            Forms\Components\TextInput::make('day_of_month')
                ->label('Tanggal Generate Tiap Bulan')
                ->numeric()
                ->minValue(1)
                ->maxValue(31)
                ->required()
                ->live(onBlur: true)
                ->helperText('Kalau bulan itu tidak punya tanggal sebesar ini (mis. 31 di Februari), otomatis jatuh ke tanggal terakhir bulan itu.'),

            Forms\Components\DatePicker::make('next_run_date')
                ->label('Mulai Generate Dari')
                ->native(false)
                ->required()
                ->default(now()->addMonthNoOverflow()->startOfMonth())
                ->live()
                // Tidak boleh lebih dari 1 bulan ke belakang (mencegah catch-up massal tak sengaja);
                // saat mengedit, tanggal yang sudah tersimpan tetap boleh dipertahankan.
                ->minDate(fn (?RecurringBillTemplate $record) => $record?->next_run_date
                    ? $record->next_run_date->copy()->min(now()->subMonth()->startOfDay())
                    : now()->subMonth()->startOfDay())
                // Harus jatuh tepat pada "Tanggal Generate Tiap Bulan" (atau akhir bulan) supaya jadwal
                // sesudahnya tidak bergeser tanggal di tengah jalan.
                ->rules([
                    fn (Forms\Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                        $day = (int) $get('day_of_month');
                        if ($day < 1 || $day > 31 || ! $value) {
                            return;
                        }

                        $date = Carbon::parse($value);
                        $expected = min($day, $date->daysInMonth);

                        if ($date->day !== $expected) {
                            $fail("Tanggal mulai harus jatuh pada tanggal {$expected} (sesuai \"Tanggal Generate Tiap Bulan\" = {$day}, atau akhir bulan bila bulannya lebih pendek).");
                        }
                    },
                ])
                ->helperText('Generate PERTAMA terjadi pada tanggal ini (atau setelahnya, saat cron harian jalan) -- generate BERIKUTNYA otomatis 1 bulan setelahnya di tanggal yang sama.'),

            Forms\Components\Placeholder::make('catchup')
                ->label('Perkiraan Tagihan Saat Cron Berikutnya')
                ->visible(fn (Forms\Get $get) => $get('next_run_date') && Carbon::parse($get('next_run_date'))->lte(today()))
                ->content(function (Forms\Get $get): string {
                    $start = Carbon::parse($get('next_run_date'))->startOfMonth();
                    $months = $start->diffInMonths(today()->startOfMonth()) + 1;

                    return "Tanggal mulai sudah lewat: cron berikutnya akan LANGSUNG membuat sekitar {$months} tagihan dan jurnal (auto-post, tanpa approval). Pastikan ini disengaja.";
                }),

            Forms\Components\DatePicker::make('end_date')
                ->label('Berakhir Pada (opsional)')
                ->native(false)
                ->live()
                ->afterOrEqual('next_run_date')
                ->helperText('Setelah tanggal ini template dinonaktifkan otomatis. Kosongkan kalau berjalan terus.'),

            Forms\Components\TextInput::make('max_occurrences')
                ->label('Maksimal Jumlah Tagihan (opsional)')
                ->numeric()
                ->minValue(1)
                ->maxValue(600)
                ->live(onBlur: true)
                ->helperText('mis. 12 untuk sewa kontrak setahun / cicilan. Tagihan yang dibatalkan tidak dihitung.'),

            Forms\Components\DatePicker::make('paused_until')
                ->label('Jeda Sampai (opsional)')
                ->native(false)
                ->live()
                ->helperText('Bulan yang jatuh sampai tanggal ini DILEWATI (jadwal tetap maju, tanpa tagihan) — tidak ada catch-up mendadak setelah jeda.'),

            // Pratinjau 5 jadwal berikutnya (memperhitungkan batas & jeda).
            Forms\Components\Placeholder::make('preview')
                ->label('5 Jadwal Berikutnya')
                ->visible(fn (Forms\Get $get) => (bool) $get('next_run_date') && (int) $get('day_of_month') >= 1)
                ->content(function (Forms\Get $get, ?RecurringBillTemplate $record): \Illuminate\Support\HtmlString {
                    $template = $record ? clone $record : new RecurringBillTemplate();
                    $template->fill([
                        'day_of_month' => (int) $get('day_of_month'),
                        'next_run_date' => $get('next_run_date'),
                        'end_date' => $get('end_date') ?: null,
                        'max_occurrences' => $get('max_occurrences') ?: null,
                        'paused_until' => $get('paused_until') ?: null,
                    ]);

                    $rows = collect($template->upcomingSchedule(5))->map(fn ($r) => e($r['date']->translatedFormat('d M Y')) . ($r['skipped'] ? ' <em>(dilewati — jeda)</em>' : ''));

                    return new \Illuminate\Support\HtmlString($rows->isEmpty() ? 'Tidak ada jadwal (sudah melewati batas).' : $rows->implode('<br>'));
                }),

            Forms\Components\Toggle::make('is_active')
                ->label('Aktif')
                ->default(true)
                ->helperText('Nonaktifkan untuk menghentikan auto-generate tanpa menghapus riwayat/template ini.'),

            Forms\Components\Textarea::make('notes')
                ->label('Catatan (opsional)')
                ->rows(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Template')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('supplier_name')
                    ->label('Supplier')
                    ->searchable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('Perusahaan'),

                Tables\Columns\TextColumn::make('chartOfAccount.name')
                    ->label('Akun Beban'),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Nominal')
                    ->money('IDR')
                    ->sortable()
                    // Total komitmen bulanan = jumlah nominal semua template AKTIF.
                    ->summarize(
                        Tables\Columns\Summarizers\Summarizer::make()
                            ->label('Komitmen/bulan (aktif)')
                            ->using(fn (\Illuminate\Database\Query\Builder $query) => (float) $query->where('is_active', true)->sum('amount'))
                            ->money('IDR')
                    ),

                Tables\Columns\TextColumn::make('day_of_month')
                    ->label('Tgl/Bulan')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('next_run_date')
                    ->label('Generate Berikutnya')
                    ->date('d M Y')
                    ->sortable()
                    ->description(fn (RecurringBillTemplate $r) => $r->paused_until && $r->paused_until->gte(today())
                        ? 'Jeda s/d ' . $r->paused_until->format('d M Y')
                        : ($r->end_date ? 'Berakhir ' . $r->end_date->format('d M Y') : null)),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),

                // Status eksekusi terakhir: template yang macet langsung terlihat di sini.
                Tables\Columns\TextColumn::make('last_error')
                    ->label('Status')
                    ->badge()
                    ->state(fn (RecurringBillTemplate $r) => $r->last_error ? 'Gagal' : ($r->last_run_at ? 'OK' : 'Belum jalan'))
                    ->color(fn (string $state) => match ($state) { 'Gagal' => 'danger', 'OK' => 'success', default => 'gray' })
                    ->description(fn (RecurringBillTemplate $r) => $r->last_error
                        ? mb_substr($r->last_error, 0, 80) . ' (' . $r->last_error_at?->format('d M H:i') . ')'
                        : ($r->last_run_at ? 'Terakhir ' . $r->last_run_at->format('d M Y H:i') : null))
                    ->wrap(),

                Tables\Columns\TextColumn::make('generated_payables_count')
                    ->label('Tagihan Dibuat')
                    ->suffix(fn (RecurringBillTemplate $r) => $r->max_occurrences ? ' / ' . $r->max_occurrences : 'x')
                    ->toggleable(),
            ])
            ->defaultSort('next_run_date')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status Aktif'),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->options(fn () => Store::orderBy('name')->pluck('name', 'id')),

                Tables\Filters\SelectFilter::make('chart_of_account_id')
                    ->label('Akun Beban')
                    ->options(fn () => ChartOfAccount::whereIn('type', RecurringBillTemplate::EXPENSE_TYPES)->orderBy('code')->get()
                        ->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name]))
                    ->searchable(),

                Tables\Filters\Filter::make('due_soon')
                    ->label('Jatuh tempo ≤ 7 hari')
                    ->query(fn (Builder $query) => $query->where('is_active', true)
                        ->whereDate('next_run_date', '<=', now()->addDays(7))),

                Tables\Filters\Filter::make('failed')
                    ->label('Gagal terakhir')
                    ->query(fn (Builder $query) => $query->whereNotNull('last_error')),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->visible(fn () => static::canEdit(null)),

                Tables\Actions\ActionGroup::make([
                    // "Jalankan Sekarang": generate jadwal berikutnya tanpa menunggu cron.
                    Tables\Actions\Action::make('run_now')
                        ->label('Jalankan Sekarang')
                        ->icon('heroicon-o-play')
                        ->color('success')
                        ->visible(fn (RecurringBillTemplate $r) => static::accessGate() && $r->is_active)
                        ->requiresConfirmation()
                        ->modalDescription(fn (RecurringBillTemplate $r) => 'Membuat tagihan untuk jadwal ' . $r->next_run_date->format('d M Y') . ' SEKARANG (langsung terposting ke Hutang Usaha) lalu memajukan jadwal ke bulan berikutnya.')
                        ->action(function (RecurringBillTemplate $record) {
                            try {
                                $payable = app(RecurringBillGenerationService::class)->runNow($record);

                                Notification::make()
                                    ->title($payable ? 'Tagihan dibuat: ' . $payable->payable_number : 'Jadwal sudah pernah dibuat — hanya dimajukan')
                                    ->success()
                                    ->send();
                            } catch (RuntimeException|QueryException $e) {
                                Notification::make()
                                    ->title('Gagal menjalankan template')
                                    ->body($e instanceof QueryException ? 'Terjadi konflik data. Muat ulang lalu coba lagi.' : $e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),

                    // Lewati 1 bulan tanpa menghapus/menonaktifkan template.
                    Tables\Actions\Action::make('skip_next')
                        ->label('Lewati Bulan Berikutnya')
                        ->icon('heroicon-o-forward')
                        ->color('warning')
                        ->visible(fn (RecurringBillTemplate $r) => static::accessGate() && $r->is_active)
                        ->requiresConfirmation()
                        ->modalDescription(fn (RecurringBillTemplate $r) => 'Jadwal ' . $r->next_run_date->format('d M Y') . ' dilewati (TIDAK membuat tagihan); generate berikutnya ' . $r->computeNextRunDate()->format('d M Y') . '.')
                        ->action(function (RecurringBillTemplate $record) {
                            $record->update(['next_run_date' => $record->computeNextRunDate()]);

                            Notification::make()->title('Jadwal dilewati')->success()->send();
                        }),

                    Tables\Actions\DeleteAction::make()
                        // Hanya template yang BELUM pernah menghasilkan tagihan yang boleh dihapus;
                        // selebihnya cukup dinonaktifkan (source_id di tagihan lama tidak boleh yatim).
                        ->visible(fn (RecurringBillTemplate $record) => static::accessGate() && $record->generated_payables_count === 0)
                        ->modalDescription('Template ini belum pernah menghasilkan tagihan. Nonaktifkan saja kalau cuma mau menjeda sementara.'),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\GeneratedPayablesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRecurringBillTemplates::route('/'),
            'create' => Pages\CreateRecurringBillTemplate::route('/create'),
            'edit' => Pages\EditRecurringBillTemplate::route('/{record}/edit'),
        ];
    }
}
