<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PayrollResource\Pages;
use App\Models\Payroll;
use App\Models\Store;
use App\Models\User;
use App\Services\PayrollPostingService;
use App\Services\PushNotificationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PayrollResource extends Resource
{
    protected static ?string $model = Payroll::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- band 400-499 "Penggajian".
    protected static ?string $navigationGroup = 'Penggajian';

    protected static ?string $navigationLabel = 'Penggajian';

    protected static ?string $modelLabel = 'Penggajian';

    protected static ?string $pluralModelLabel = 'Penggajian';

    protected static ?int $navigationSort = 400;

    /**
     * Uang gaji karyawan — SEBELUMNYA cuma isFullAccess() yang boleh
     * buka sama sekali, TIDAK lewat hasMenuAccess()/menuAccessOptions()
     * spt resource lain (sengaja, supaya tidak ada kemungkinan
     * store_manager kecentang aksesnya tidak sengaja). Diperluas
     * 2026-09-24 (keputusan user) — spv_finance sekarang BOLEH, tapi
     * TETAP lewat hasMenuAccess() (harus dicentang eksplisit di "Akses
     * Menu", tidak otomatis) supaya admin full-access masih pegang
     * kendali siapa persisnya yang benar-benar dapat akses.
     */
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->isFullAccess()
            || ($user?->hasRole('spv_finance') && $user->hasMenuAccess(static::class));
    }

    // TETAP false — baris Payroll cuma dibuat lewat aksi "Generate
    // Payroll Bulanan" (Payroll::generateForMonth()), tidak pernah lewat
    // form Create biasa, jadi bukan soal hak akses (tetap sama utk
    // spv_finance maupun full-access).
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * SEBELUMNYA tidak ada canDelete()/canDeleteAny() sama sekali, dan
     * tidak ada PayrollPolicy terdaftar — Gate default-deny bikin
     * DeleteAction (->visible() sudah ada, status draft saja) TIDAK
     * PERNAH muncul untuk siapa pun, termasuk isFullAccess() — padahal
     * baris payroll draft yang salah generate (mis. double generate,
     * atau baru sadar base_salary keliru sebelum ditandai dibayar) perlu
     * bisa dihapus & digenerate ulang. Disamakan persis dengan guard
     * ->visible() yang sudah ada (status draft) + breadth canViewAny()
     * (isFullAccess() saja).
     *
     * hasModuleAction(..., false) (audit Majoo f64, diperluas 2026-09-24
     * utk spv_finance) — default FALSE, spv_finance HARUS dicentang
     * eksplisit di "Hak Akses Detail" dulu baru boleh hapus, view saja
     * tidak otomatis termasuk hak hapus.
     */
    public static function canDelete($record): bool
    {
        $user = auth()->user();

        $allowed = $user?->isFullAccess()
            || ($user?->hasRole('spv_finance') && $user->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'delete', false));

        return $allowed && $record->status === 'draft';
    }

    public static function canDeleteAny(): bool
    {
        $user = auth()->user();

        return $user?->isFullAccess()
            || ($user?->hasRole('spv_finance') && $user->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'delete', false));
    }

    /**
     * Eager-load — kolom "journalEntry.entry_number", "store.name",
     * "user.name" di table() di bawah sebelumnya N+1 per baris (audit
     * framework 2026-09-14, "N+1 query & eager loading"). TIDAK
     * menambah filter store_id -- canViewAny() resource ini sudah
     * full-access-only (lihat komentar di atas), jadi tidak perlu.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->select('payrolls.*')
            ->addSelect(['stale_attendance' => Payroll::staleAttendanceSubquery(), 'stale_rule' => Payroll::staleRuleSubquery()])
            ->with(['journalEntry', 'store', 'user']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('period_month')
                    ->label('Periode')
                    ->date('F Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Karyawan')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('user.bank_account_number')
                    ->label('Rekening')
                    ->state(fn (Payroll $record) => filled($record->user?->bank_account_number)
                        ? trim("{$record->user->bank_name} {$record->user->bank_account_number}")
                        : 'Belum diisi')
                    ->description(fn (Payroll $record) => $record->user?->bank_account_holder_name ? 'a.n. ' . $record->user->bank_account_holder_name : null)
                    ->color(fn (Payroll $record) => blank($record->user?->bank_account_number) ? 'danger' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('base_salary')
                    ->label('Gaji Pokok')
                    ->money('IDR', locale: 'id')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('prorated_base_salary')
                    ->label('Gaji Berjalan')
                    ->money('IDR', locale: 'id')
                    ->description(fn (Payroll $record) => $record->prorated_base_salary < $record->base_salary
                        ? 'Diproporsikan (baru mulai kerja bulan ini)'
                        : null),

                Tables\Columns\TextColumn::make('total_late_minutes')
                    ->label('Total Telat')
                    ->formatStateUsing(fn (int $state) => $state > 0 ? "{$state} mnt" : '—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('late_violation_days')
                    ->label('Blok Telat Terpotong')
                    ->formatStateUsing(fn (int $state, Payroll $record) => $state > 0 ? "{$state} blok x {$record->late_block_minutes} mnt" : '—')
                    ->description(fn (Payroll $record) => ($record->late_deduction ?? 0) > 0 ? '- Rp ' . number_format((float) $record->late_deduction, 0, ',', '.') : null)
                    ->color(fn (int $state) => $state > 0 ? 'danger' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('alpha_days')
                    ->label('Hari Alpha')
                    ->formatStateUsing(fn (int $state) => $state > 0 ? "{$state} hari" : '—')
                    ->description(fn (Payroll $record) => $record->alpha_days > 0 && $record->alpha_hours !== null
                        ? number_format((float) $record->alpha_hours, 1, ',', '.') . ' jam' . ((float) $record->alpha_fine > 0 ? ' + denda Rp ' . number_format((float) $record->alpha_fine, 0, ',', '.') : '')
                        : null)
                    ->color(fn (int $state) => $state > 0 ? 'danger' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('total_additions')
                    ->label('Tambahan')
                    ->money('IDR', locale: 'id')
                    ->color(fn (Payroll $record) => $record->total_additions > 0 ? 'success' : 'gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total_item_deductions')
                    ->label('Potongan Komponen')
                    ->money('IDR', locale: 'id')
                    ->color(fn (Payroll $record) => $record->total_item_deductions > 0 ? 'danger' : 'gray')
                    ->description('BPJS, PPh 21, kasbon, dst.')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total_deduction')
                    ->label('Potongan Absensi')
                    ->money('IDR', locale: 'id')
                    ->color(fn (Payroll $record) => $record->total_deduction > 0 ? 'danger' : 'gray')
                    // Rincian telat dan Alpha diringkas di sini (kolom rinciannya bisa dinyalakan dari pemilih kolom).
                    ->description(function (Payroll $record) {
                        $parts = array_filter([
                            $record->total_late_minutes > 0 ? "Telat {$record->total_late_minutes} mnt" : null,
                            $record->alpha_days > 0 ? "Alpha {$record->alpha_days} hari" : null,
                        ]);

                        return $parts ? implode(' · ', $parts) : null;
                    }),

                Tables\Columns\TextColumn::make('total_commission')
                    ->label('Komisi')
                    ->money('IDR', locale: 'id')
                    ->color(fn (Payroll $record) => $record->total_commission > 0 ? 'success' : 'gray')
                    ->description(fn (Payroll $record) => $record->has_unrated_commission
                        ? 'Ada job dgn tarif belum diatur, belum ikut disumkan'
                        : null)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('net_pay')
                    ->label('Gaji Bersih')
                    ->money('IDR', locale: 'id')
                    ->weight('bold')
                    ->description(fn (Payroll $record) => $record->uncollected_deduction > 0
                        ? 'Potongan Rp ' . number_format($record->uncollected_deduction, 0, ',', '.') . ' tidak terpotong (melebihi gaji)'
                        : null)
                    // Total mengikuti filter yang aktif (bawaannya periode terbaru, jadi satu bulan).
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Total gaji bersih')->money('IDR', locale: 'id')),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'gray'    => 'draft',
                        'warning' => 'pending_approval',
                        'success' => 'paid',
                    ])
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'draft'            => 'Draft',
                        'pending_approval' => 'Menunggu Persetujuan Direksi',
                        'paid'             => 'Sudah Dibayar',
                        default            => $state,
                    })
                    ->description(fn (Payroll $record) => $record->isStale() ? 'Absensi, gaji pokok, atau aturan potongan berubah, hitung ulang' : null),

                Tables\Columns\TextColumn::make('journalEntry.entry_number')
                    ->label('No. Jurnal')
                    ->placeholder('—')
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(['draft' => 'Draft', 'pending_approval' => 'Menunggu Persetujuan Direksi', 'paid' => 'Sudah Dibayar']),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->relationship('store', 'name'),

                Tables\Filters\SelectFilter::make('period_month')
                    ->label('Periode')
                    // Periode yang benar-benar ada slipnya, terbaru di atas; bawaannya periode terbaru supaya daftar langsung satu bulan.
                    ->options(fn () => Payroll::query()->select('period_month')->distinct()->orderByDesc('period_month')->pluck('period_month')
                        ->mapWithKeys(fn ($month) => [Carbon::parse($month)->toDateString() => Carbon::parse($month)->translatedFormat('F Y')])
                        ->all())
                    ->default(fn () => ($latest = Payroll::query()->max('period_month')) ? Carbon::parse($latest)->startOfMonth()->toDateString() : null)
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->whereDate('period_month', $data['value']) : $query),
            ])
            ->headerActions([
                Tables\Actions\Action::make('generate')
                    ->label('Generate Payroll Bulanan')
                    ->icon('heroicon-o-calculator')
                    ->color('primary')
                    ->form([
                        Forms\Components\Select::make('store_id')
                            ->label('Toko')
                            ->helperText('Kosongkan untuk generate semua toko sekaligus.')
                            ->options(fn () => Store::where('is_active', true)->pluck('name', 'id'))
                            ->searchable(),

                        Forms\Components\Select::make('month')
                            ->label('Bulan')
                            ->helperText('Hindari generate bulan yang masih berjalan — angkanya cuma mencerminkan sebagian bulan, belum lengkap.')
                            ->options(function () {
                                $options = [];
                                for ($i = 0; $i < 12; $i++) {
                                    $date = Carbon::now()->subMonths($i)->startOfMonth();
                                    $label = $date->translatedFormat('F Y');
                                    if ($i === 0) $label .= ' (masih berjalan!)';
                                    $options[$date->toDateString()] = $label;
                                }
                                return $options;
                            })
                            // Default bulan LALU (bukan bulan berjalan) —
                            // supaya generate default-nya selalu bulan yang
                            // sudah selesai penuh, admin harus sengaja
                            // pilih bulan berjalan kalau memang mau itu.
                            ->default(Carbon::now()->subMonth()->startOfMonth()->toDateString())
                            ->required(),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('Pastikan bulan yang dipilih sudah SELESAI PENUH sebelum generate — kalau bulan masih berjalan, potongan telat/alpha yang dihasilkan belum lengkap.')
                    ->action(function (array $data) {
                        $month = Carbon::parse($data['month']);

                        // is_active=false (resign/dinonaktifkan, lihat
                        // UserResource "Nonaktifkan") DIKELUARKAN TOTAL di
                        // sini, bukan cuma dilaporkan seperti missingSalary/
                        // missingStore — MarkAbsences juga sudah melewati
                        // karyawan nonaktif (tidak membuat baris Alpha untuk
                        // mereka), jadi kalau tetap ikut di-generate di sini
                        // mereka akan dianggap kerja penuh sebulan (alpha_days
                        // = 0) padahal sudah tidak bekerja sama sekali.
                        // Karyawan yang berhenti (Hari Kerja Terakhir) di bulan ini tetap digenerate walau akunnya sudah dinonaktifkan;
                        // yang berhenti sebelum bulan ini tidak.
                        $monthStart = $month->copy()->startOfMonth()->toDateString();
                        $monthEnd = $month->copy()->endOfMonth()->toDateString();

                        $storeQuery = User::query()
                            ->when($data['store_id'] ?? null, fn ($q) => $q->where('store_id', $data['store_id']))
                            ->where(fn ($q) => $q->where('is_active', true)->orWhereBetween('last_work_date', [$monthStart, $monthEnd]))
                            ->where(fn ($q) => $q->whereNull('last_work_date')->orWhereDate('last_work_date', '>=', $monthStart))
                            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'partner'));

                        // Dipisah 2 query (bukan langsung whereNotNull di
                        // query utama) supaya bisa laporkan SIAPA yang
                        // terlewat & kenapa — sebelumnya karyawan tanpa
                        // gaji pokok/toko cuma hilang diam-diam tanpa admin
                        // tahu ada yang belum ke-generate.
                        $missingSalary = (clone $storeQuery)->whereNull('base_salary')->pluck('name');
                        $missingStore = (clone $storeQuery)->whereNull('store_id')->pluck('name');

                        $users = (clone $storeQuery)->whereNotNull('base_salary')->whereNotNull('store_id')->get();

                        $generated = 0;
                        $skippedPaid = 0;
                        $overDeducted = collect();
                        foreach ($users as $user) {
                            try {
                                $payroll = Payroll::generateForMonth($user, $month);
                                $generated++;

                                if ($payroll->uncollected_deduction > 0) {
                                    $overDeducted->push("{$user->name} (Rp " . number_format($payroll->uncollected_deduction, 0, ',', '.') . ')');
                                }
                            } catch (\InvalidArgumentException $e) {
                                // Aturan potongan belum diisi berlaku untuk SEMUA karyawan: hentikan dan jelaskan, jangan dihitung sebagai "dilewati".
                                if (str_contains($e->getMessage(), 'Aturan Potongan Absensi')) {
                                    Notification::make()->title('Payroll tidak digenerate')->body($e->getMessage())->danger()->send();

                                    return;
                                }

                                $skippedPaid++;
                            }
                        }

                        $inactiveCount = User::query()
                            ->when($data['store_id'] ?? null, fn ($q) => $q->where('store_id', $data['store_id']))
                            ->where('is_active', false)
                            ->where(fn ($q) => $q->whereNull('last_work_date')->orWhereDate('last_work_date', '<', $monthStart))
                            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'partner'))
                            ->count();

                        $bodyLines = ["{$generated} karyawan berhasil digenerate."];
                        if ($skippedPaid > 0) $bodyLines[] = "{$skippedPaid} dilewati (sudah dibayar / sedang menunggu persetujuan direksi).";
                        if ($missingSalary->isNotEmpty()) $bodyLines[] = 'Belum ada Gaji Pokok: ' . $missingSalary->implode(', ') . '.';
                        if ($missingStore->isNotEmpty()) $bodyLines[] = 'Belum terhubung toko: ' . $missingStore->implode(', ') . '.';
                        if ($inactiveCount > 0) $bodyLines[] = "{$inactiveCount} karyawan nonaktif dilewati (tidak digenerate).";
                        if ($overDeducted->isNotEmpty()) $bodyLines[] = 'Potongan melebihi gaji (selisih tidak terpotong): ' . $overDeducted->implode(', ') . '.';

                        // Dasar hukum potongan: Peraturan Perusahaan terbit dan sudah diterima (peringatan, bukan blokir, supaya gaji tetap bisa dibayar).
                        $policyWarning = \App\Models\CompanyPolicy::payrollDeductionWarning($users);
                        if ($policyWarning) $bodyLines[] = $policyWarning;

                        $hasWarning = $missingSalary->isNotEmpty() || $missingStore->isNotEmpty() || $policyWarning !== null || $overDeducted->isNotEmpty();
                        $notification = Notification::make()->title('Payroll digenerate')->body(implode(' ', $bodyLines));
                        $hasWarning ? $notification->warning()->send() : $notification->success()->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('components')
                    ->label('Komponen Gaji')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->color('gray')
                    ->visible(fn (Payroll $record) => $record->status === 'draft')
                    ->modalHeading(fn (Payroll $record) => 'Komponen Gaji - ' . $record->user?->name)
                    ->modalDescription('Tambahan (tunjangan, bonus, THR, uang kompensasi) dan potongan (BPJS, PPh 21, kasbon) sesuai hitungan akuntan. Hanya dicatat; sistem tidak menghitung pajak dan iuran.')
                    ->modalWidth('4xl')
                    ->fillForm(fn (Payroll $record) => ['items' => $record->items()->orderBy('id')->get()->map(fn ($i) => [
                        'id' => $i->id, 'payroll_item_category_id' => $i->payroll_item_category_id, 'label' => $i->label,
                        'amount' => (float) $i->amount, 'is_recurring' => $i->is_recurring, 'notes' => $i->notes,
                    ])->all()])
                    ->form([
                        Forms\Components\Repeater::make('items')
                            ->label('Komponen')
                            ->addActionLabel('Tambah Komponen')
                            ->defaultItems(0)
                            ->columns(4)
                            ->schema([
                                Forms\Components\Hidden::make('id'),
                                Forms\Components\Select::make('payroll_item_category_id')
                                    ->label('Kategori')
                                    ->options(fn () => \App\Models\PayrollItemCategory::where('is_active', true)->orderBy('sort_order')->get()
                                        ->mapWithKeys(fn ($c) => [$c->id => ($c->isEarning() ? '(+) ' : '(-) ') . $c->name]))
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(fn ($state, Forms\Set $set) => $set('is_recurring', (bool) \App\Models\PayrollItemCategory::whereKey($state)->value('is_recurring_default')))
                                    ->columnSpan(2),
                                Forms\Components\TextInput::make('amount')->label('Nominal (Rp)')->numeric()->prefix('Rp')->minValue(0.01)->required()->columnSpan(2),
                                Forms\Components\TextInput::make('label')->label('Nama di slip (opsional)')->maxLength(120)->placeholder('Kosong = nama kategori')->columnSpan(2),
                                Forms\Components\TextInput::make('notes')->label('Catatan (opsional)')->maxLength(255)->columnSpan(1),
                                Forms\Components\Toggle::make('is_recurring')->label('Salin ke bulan depan')->columnSpan(1),
                            ]),
                    ])
                    ->action(function (Payroll $record, array $data) {
                        try {
                            app(\App\Services\PayrollItemService::class)->sync($record, $data['items'] ?? [], auth()->id());
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Komponen tidak tersimpan')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Komponen gaji disimpan')->success()->send();
                    }),

                Tables\Actions\Action::make('recalculate')
                    ->label('Hitung Ulang')
                    ->icon('heroicon-o-arrow-path')
                    ->color(fn (Payroll $record) => $record->isStale() ? 'warning' : 'gray')
                    ->visible(fn (Payroll $record) => $record->status === 'draft')
                    ->requiresConfirmation()
                    ->modalDescription('Menghitung ulang slip ini dari absensi, aturan potongan, komisi, dan gaji pokok terbaru. Komponen gaji yang sudah dicatat tetap dipakai.')
                    ->action(function (Payroll $record) {
                        try {
                            $fresh = Payroll::generateForMonth($record->user, $record->period_month);
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->title('Tidak bisa dihitung ulang')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        $notification = Notification::make()->title('Slip dihitung ulang')
                            ->body('Gaji bersih sekarang Rp ' . number_format((float) $fresh->net_pay, 0, ',', '.') . ($fresh->uncollected_deduction > 0 ? '. Potongan melebihi gaji, selisih tidak terpotong.' : '.'));
                        $fresh->uncollected_deduction > 0 ? $notification->warning()->send() : $notification->success()->send();
                    }),

                Tables\Actions\Action::make('copyComponents')
                    ->label('Salin dari Bulan Lalu')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->visible(fn (Payroll $record) => $record->status === 'draft')
                    ->requiresConfirmation()
                    ->modalDescription('Menyalin komponen yang ditandai "Salin ke bulan depan" dari slip bulan lalu karyawan ini. Komponen yang sudah ada dilewati.')
                    ->action(function (Payroll $record) {
                        try {
                            $copied = app(\App\Services\PayrollItemService::class)->copyRecurring($record, auth()->id());
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Tidak bisa menyalin')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title($copied > 0 ? "{$copied} komponen disalin" : 'Tidak ada komponen untuk disalin')->success()->send();
                    }),

                Tables\Actions\Action::make('downloadPayslip')
                    ->label('Download Slip Gaji')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->action(fn (Payroll $record) => static::downloadPayslipPdf($record)),

                /**
                 * Gap standar enterprise diperbaiki 2026-09-27 (audit
                 * Penggajian) -- SEBELUMNYA spv_finance sendirian bisa
                 * generate DAN markPaid, langsung posting ke Jurnal
                 * Umum, beda dari pola modul finansial lain sesi ini
                 * (Pengeluaran: staff->store_manager->direksi, lihat
                 * FinanceTransactionApprovalService). Atasan langsung
                 * spv_finance adalah direksi (dikonfirmasi user) --
                 * Payroll cukup 1 tingkat approval (bukan 2 tingkat
                 * seperti Pengeluaran): spv_finance WAJIB "Ajukan
                 * Pembayaran" dulu, hanya isFullAccess() (super_admin/
                 * direksi) yang bisa menyetujui & memposting.
                 * isFullAccess() TETAP boleh langsung "Tandai Dibayar"
                 * dari draft tanpa lewat pengajuan -- mereka sudah
                 * ujung rantai approval, tidak ada gunanya mengajukan
                 * ke diri sendiri.
                 */
                Tables\Actions\Action::make('requestPayment')
                    ->label('Ajukan Pembayaran')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->visible(fn (Payroll $record) => $record->status === 'draft' && ! auth()->user()?->isFullAccess())
                    ->requiresConfirmation()
                    ->modalDescription('Pengajuan ini akan dikirim ke direksi untuk disetujui sebelum benar-benar ditandai dibayar & diposting ke Jurnal Umum.')
                    ->action(function (Payroll $record) {
                        try {
                            static::requestOne($record->id, auth()->id());
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Gagal mengajukan')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        $recipients = User::where('is_active', true)->get()->filter(fn (User $u) => $u->isFullAccess());
                        $amountLabel = 'Rp' . number_format((float) $record->net_pay, 0, ',', '.');
                        foreach ($recipients as $recipient) {
                            Notification::make()
                                ->title("Menunggu persetujuan Anda: gaji {$record->user?->name} ({$amountLabel})")
                                ->warning()
                                ->sendToDatabase($recipient);
                        }

                        Notification::make()->title('Pengajuan pembayaran dikirim ke direksi')->success()->send();
                    }),

                Tables\Actions\Action::make('approvePayment')
                    ->label('Setujui & Tandai Dibayar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Payroll $record) => $record->status === 'pending_approval' && auth()->user()?->isFullAccess())
                    ->requiresConfirmation()
                    ->modalDescription(fn (Payroll $record) => static::bankLine($record->user) . ' Pastikan gaji sudah benar-benar ditransfer sebelum menyetujui ini — status ini mengunci baris payroll dari generate ulang.')
                    ->action(fn (Payroll $record) => static::finalizePayment($record, 'pending_approval')),

                Tables\Actions\Action::make('rejectPayment')
                    ->label('Tolak Pengajuan')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Payroll $record) => $record->status === 'pending_approval' && auth()->user()?->isFullAccess())
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('note')->label('Alasan Penolakan')->required(),
                    ])
                    ->action(function (Payroll $record, array $data) {
                        if (! (auth()->user()?->isFullAccess() ?? false)) {
                            Notification::make()->title('Tidak berwenang')->body('Hanya direksi/full-access yang boleh menolak pengajuan pembayaran.')->danger()->send();

                            return;
                        }

                        try {
                            DB::transaction(function () use ($record) {
                                $locked = Payroll::whereKey($record->id)->lockForUpdate()->first();

                                if (! $locked || $locked->status !== 'pending_approval') {
                                    throw new RuntimeException('Pengajuan ini sudah diproses sebelumnya.');
                                }

                                $locked->update([
                                    'status'                => 'draft',
                                    'payment_requested_by'  => null,
                                    'payment_requested_at'  => null,
                                ]);
                            });
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Gagal menolak')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        if ($record->payment_requested_by) {
                            app(\App\Services\StaffNotifier::class)->toUsers(
                                [$record->payment_requested_by],
                                'Pengajuan Pembayaran Gaji Ditolak',
                                "Pengajuan gaji {$record->user?->name} periode " . $record->period_month->translatedFormat('F Y') . ' ditolak: ' . $data['note']
                            );
                        }

                        Notification::make()->title('Pengajuan ditolak, kembali ke draft')->success()->send();
                    }),

                Tables\Actions\Action::make('markPaid')
                    ->label('Tandai Dibayar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Payroll $record) => $record->status === 'draft' && auth()->user()?->isFullAccess())
                    ->requiresConfirmation()
                    ->modalDescription(fn (Payroll $record) => static::bankLine($record->user) . ' Pastikan gaji sudah benar-benar ditransfer sebelum menandai ini — status ini mengunci baris payroll dari generate ulang.')
                    ->action(fn (Payroll $record) => static::finalizePayment($record, 'draft')),

                // Salah menandai dibayar: kembalikan ke draf (alasan wajib, tercatat). Tidak tersedia bila slip sudah diposting ke jurnal.
                Tables\Actions\Action::make('unpay')
                    ->label('Batalkan Status Dibayar')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (Payroll $record) => (auth()->user()?->isFullAccess() ?? false) && $record->status === 'paid' && $record->journal_entry_id === null)
                    ->modalHeading(fn (Payroll $record) => 'Batalkan status dibayar: ' . $record->user?->name)
                    ->modalDescription('Slip kembali menjadi draf dan hilang dari slip gaji karyawan di aplikasi sampai dibayar lagi. Karyawan diberi tahu. Gunakan hanya bila slip salah ditandai dibayar.')
                    ->form([
                        Forms\Components\Textarea::make('reason')->label('Alasan')->required()->rows(2),
                    ])
                    ->action(function (Payroll $record, array $data) {
                        try {
                            $reverted = static::unpayOne($record->id, $data['reason']);
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Gagal membatalkan')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        app(\App\Services\StaffNotifier::class)->toUsers(
                            [$reverted->user_id],
                            'Pembayaran Gaji Dibatalkan',
                            'Status dibayar gaji periode ' . $reverted->period_month->translatedFormat('F Y') . ' dibatalkan: ' . $data['reason']
                        );

                        Notification::make()->title('Status dibayar dibatalkan, slip kembali ke draf')->warning()->send();
                    }),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Payroll $record) => $record->status === 'draft'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // Spv Finance: ajukan banyak slip draft sekaligus ke direksi (satu pemberitahuan ringkasan, bukan satu per slip).
                    Tables\Actions\BulkAction::make('bulkRequestPayment')
                        ->label('Ajukan Pembayaran (terpilih)')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('warning')
                        ->visible(fn () => ! (auth()->user()?->isFullAccess() ?? false))
                        ->requiresConfirmation()
                        ->modalDescription(fn (\Illuminate\Support\Collection $records) => static::selectionSummary($records) . 'Hanya slip berstatus draft yang diajukan; sisanya dilewati.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (\Illuminate\Support\Collection $records) {
                            $done = static::runBulk($records, fn (Payroll $p) => static::requestOne($p->id, auth()->id()), 'diajukan ke direksi');

                            if ($done->isNotEmpty()) {
                                $total = 'Rp' . number_format((float) $done->sum('net_pay'), 0, ',', '.');
                                User::where('is_active', true)->get()->filter(fn (User $u) => $u->isFullAccess())->each(fn (User $u) => Notification::make()
                                    ->title("Menunggu persetujuan Anda: {$done->count()} slip gaji (total {$total})")
                                    ->warning()
                                    ->sendToDatabase($u));
                            }
                        }),

                    Tables\Actions\BulkAction::make('bulkRecalculate')
                        ->label('Hitung Ulang (terpilih)')
                        ->icon('heroicon-o-arrow-path')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalDescription(fn (\Illuminate\Support\Collection $records) => static::selectionSummary($records) . 'Hanya slip berstatus draft yang dihitung ulang; sisanya dilewati.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (\Illuminate\Support\Collection $records) {
                            static::runBulk($records, function (Payroll $p) {
                                if ($p->status !== 'draft') {
                                    throw new RuntimeException('bukan draf');
                                }

                                try {
                                    return Payroll::generateForMonth($p->user, $p->period_month);
                                } catch (\InvalidArgumentException $e) {
                                    throw new RuntimeException($e->getMessage());
                                }
                            }, 'dihitung ulang');
                        }),

                    Tables\Actions\BulkAction::make('bulkApprove')
                        ->label('Setujui & Tandai Dibayar (terpilih)')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                        ->requiresConfirmation()
                        ->modalDescription(fn (\Illuminate\Support\Collection $records) => static::selectionSummary($records, withBank: true) . 'Hanya slip berstatus menunggu persetujuan yang diproses. Pastikan gaji sudah ditransfer; status ini mengunci slip dari generate ulang.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (\Illuminate\Support\Collection $records) {
                            $done = static::runBulk($records, function (Payroll $p) {
                                $paid = static::payOne($p->id, 'pending_approval');
                                static::notifyPaid($paid);

                                return $paid;
                            }, 'disetujui dan ditandai dibayar');

                            static::notifyRequesters($done);
                        }),

                    Tables\Actions\BulkAction::make('bulkMarkPaid')
                        ->label('Tandai Dibayar (terpilih)')
                        ->icon('heroicon-o-banknotes')
                        ->color('success')
                        ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                        ->requiresConfirmation()
                        ->modalDescription(fn (\Illuminate\Support\Collection $records) => static::selectionSummary($records, withBank: true) . 'Hanya slip berstatus draft yang diproses. Pastikan gaji sudah ditransfer; status ini mengunci slip dari generate ulang.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (\Illuminate\Support\Collection $records) {
                            static::runBulk($records, function (Payroll $p) {
                                $paid = static::payOne($p->id, 'draft');
                                static::notifyPaid($paid);

                                return $paid;
                            }, 'ditandai dibayar');
                        }),
                ]),
            ])
            ->defaultSort('period_month', 'desc');
    }

    /**
     * Logika inti "Tandai Dibayar" — dipakai baik oleh isFullAccess()
     * yang langsung membayar dari 'draft' (markPaid), maupun oleh
     * isFullAccess() yang menyetujui pengajuan spv_finance dari
     * 'pending_approval' (approvePayment). $expectedStatus dicek ULANG
     * di dalam lock supaya dua aksi/dua admin yang nyaris bersamaan
     * tidak bisa dua-duanya lolos.
     *
     * Ditandai dibayar SEKALIGUS diposting otomatis ke Jurnal Umum
     * (PayrollPostingService), dibungkus 1 DB transaction — kalau
     * posting gagal (mis. akun Bagan Akun yang dibutuhkan belum ada,
     * atau periode sudah ditutup), status payroll juga TIDAK ikut
     * berubah jadi 'paid', supaya tidak ada payroll yang ditandai
     * dibayar tanpa jurnal.
     *
     * Bug diperbaiki 2026-09-27 (audit Penggajian) -- SEBELUMNYA tidak
     * ada lockForUpdate()/recheck status di dalam transaction (beda
     * dari generateForMonth() yang sudah benar), pola race yang sama
     * berulang ditemukan sesi ini
     * (AttendanceCorrectionService/LeaveRequestResource).
     */
    private static function finalizePayment(Payroll $record, string $expectedStatus): void
    {
        // Otorisasi di SERVER (bukan cuma ->visible() di tombol): hanya
        // direksi/full-access.
        if (! (auth()->user()?->isFullAccess() ?? false)) {
            Notification::make()
                ->title('Tidak berwenang')
                ->body('Hanya direksi/full-access yang boleh menandai payroll dibayar.')
                ->danger()
                ->send();

            return;
        }

        try {
            $record = static::payOne($record->id, $expectedStatus);
        } catch (RuntimeException $e) {
            Notification::make()
                ->title('Gagal menandai payroll dibayar')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        static::notifyPaid($record);

        if ($expectedStatus === 'pending_approval') {
            static::notifyRequesters(collect([$record]));
        }

        Notification::make()->title('Payroll ditandai dibayar')->success()->send();
    }

    /**
     * Satu payroll menjadi 'paid' di dalam kunci transaksi (dipakai aksi baris dan aksi massal). Otorisasi dicek di sini juga,
     * bukan hanya pada tombol.
     *
     * @throws RuntimeException bila bukan akses penuh, atau status sudah berubah dari $expectedStatus.
     */
    private static function payOne(int $id, string $expectedStatus): Payroll
    {
        if (! (auth()->user()?->isFullAccess() ?? false)) {
            throw new RuntimeException('Hanya direksi/full-access yang boleh menandai payroll dibayar.');
        }

        return DB::transaction(function () use ($id, $expectedStatus) {
            $locked = Payroll::whereKey($id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== $expectedStatus) {
                throw new RuntimeException('Payroll ini sudah diproses (dibayar/diajukan/ditolak) sebelumnya.');
            }

            $locked->update([
                'status'   => 'paid',
                'paid_by'  => auth()->id(),
                'paid_at'  => now(),
            ]);

            // Pembukuan dipegang Accurate 5 (FEATURE_FINANCE mati): gaji cukup ditandai dibayar, tanpa jurnal.
            if (config('features.finance')) {
                $entry = app(PayrollPostingService::class)->post($locked->refresh());
                $locked->update(['journal_entry_id' => $entry->id]);
            }

            return $locked;
        });
    }

    /**
     * Satu payroll draft menjadi 'pending_approval' di dalam kunci transaksi.
     *
     * @throws RuntimeException bila statusnya bukan draft lagi.
     */
    private static function requestOne(int $id, int $requestedBy): Payroll
    {
        return DB::transaction(function () use ($id, $requestedBy) {
            $locked = Payroll::whereKey($id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== 'draft') {
                throw new RuntimeException('Payroll ini sudah diajukan atau sudah dibayar sebelumnya.');
            }

            $locked->update([
                'status'               => 'pending_approval',
                'payment_requested_by' => $requestedBy,
                'payment_requested_at' => now(),
            ]);

            return $locked;
        });
    }

    /**
     * Slip 'paid' kembali ke 'draft' (salah tandai). Hanya akses penuh, hanya bila belum diposting ke jurnal, dengan jejak alasan.
     *
     * @throws RuntimeException
     */
    private static function unpayOne(int $id, string $reason): Payroll
    {
        if (! (auth()->user()?->isFullAccess() ?? false)) {
            throw new RuntimeException('Hanya direksi/full-access yang boleh membatalkan status dibayar.');
        }

        return DB::transaction(function () use ($id, $reason) {
            $locked = Payroll::whereKey($id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== 'paid') {
                throw new RuntimeException('Hanya slip yang berstatus dibayar yang bisa dibatalkan.');
            }

            if ($locked->journal_entry_id !== null) {
                throw new RuntimeException('Slip ini sudah diposting ke jurnal; batalkan lewat pembalikan jurnal, bukan di sini.');
            }

            $locked->update([
                'status'               => 'draft',
                'paid_by'              => null,
                'paid_at'              => null,
                'payment_requested_by' => null,
                'payment_requested_at' => null,
            ]);

            activity('payroll')
                ->causedBy(auth()->user())
                ->performedOn($locked)
                ->withProperties(['reason' => $reason])
                ->log("Status dibayar slip {$locked->user?->name} periode {$locked->period_month->format('m/Y')} dibatalkan: {$reason}");

            return $locked;
        });
    }

    /**
     * Beri tahu pengaju (Spv Finance) bahwa pengajuan pembayarannya disetujui. Satu pemberitahuan per pengaju, merangkum jumlah slip,
     * supaya persetujuan massal tidak membanjiri. Pengaju yang sekaligus penyetuju tidak diberi tahu.
     *
     * @param \Illuminate\Support\Collection<int, Payroll> $paid
     */
    private static function notifyRequesters(\Illuminate\Support\Collection $paid): void
    {
        $paid->filter(fn (Payroll $p) => $p->payment_requested_by && $p->payment_requested_by !== auth()->id())
            ->groupBy('payment_requested_by')
            ->each(function ($slips, $requesterId) {
                $names = $slips->map(fn (Payroll $p) => $p->user?->name)->filter();
                $what = $slips->count() === 1
                    ? "gaji {$names->first()} periode " . $slips->first()->period_month->translatedFormat('F Y')
                    : $slips->count() . ' slip gaji';

                app(\App\Services\StaffNotifier::class)->toUsers(
                    [(int) $requesterId],
                    'Pengajuan Pembayaran Gaji Disetujui',
                    "Pengajuan {$what} disetujui dan ditandai dibayar."
                );
            });
    }

    private static function notifyPaid(Payroll $record): void
    {
        app(\App\Services\StaffNotifier::class)->toUsers(
            [$record->user_id],
            'Gaji Sudah Dibayar',
            'Gaji periode ' . $record->period_month->translatedFormat('F Y') . ' sudah ditransfer. Buka app untuk lihat rincian.'
        );
    }

    /** Ringkasan pilihan untuk modal konfirmasi aksi massal: jumlah slip dan total gaji bersihnya. */
    private static function selectionSummary(\Illuminate\Support\Collection $records, bool $withBank = false): string
    {
        $summary = $records->count() . ' slip terpilih, total gaji bersih Rp ' . number_format((float) $records->sum('net_pay'), 0, ',', '.') . '. ';

        if ($withBank) {
            $noBank = $records->filter(fn (Payroll $p) => blank($p->user?->bank_account_number))->map(fn (Payroll $p) => $p->user?->name)->filter();

            if ($noBank->isNotEmpty()) {
                $summary .= 'PERHATIAN: ' . $noBank->count() . ' karyawan belum punya nomor rekening (' . $noBank->take(5)->implode(', ') . ($noBank->count() > 5 ? ', …' : '') . '). ';
            }
        }

        return $summary;
    }

    /** Tujuan transfer untuk ditampilkan di modal pembayaran, atau peringatan bila rekening belum diisi. */
    private static function bankLine(?User $user): string
    {
        if (! $user || blank($user->bank_account_number)) {
            return 'PERHATIAN: ' . ($user?->name ?? 'karyawan') . ' belum punya nomor rekening di data karyawan.';
        }

        return 'Transfer ke: ' . trim("{$user->bank_name} {$user->bank_account_number}") . ($user->bank_account_holder_name ? " a.n. {$user->bank_account_holder_name}" : '') . '.';
    }

    /**
     * Jalankan $step untuk tiap slip terpilih, kumpulkan yang berhasil dan yang dilewati beserta alasannya, lalu laporkan sekali.
     *
     * @param \Closure(Payroll): Payroll $step
     * @return \Illuminate\Support\Collection<int, Payroll> slip yang berhasil
     */
    private static function runBulk(\Illuminate\Support\Collection $records, \Closure $step, string $doneLabel): \Illuminate\Support\Collection
    {
        $done = collect();
        $skipped = collect();

        foreach ($records as $record) {
            try {
                $done->push($step($record));
            } catch (RuntimeException $e) {
                $skipped->push("{$record->user?->name} ({$e->getMessage()})");
            }
        }

        $body = "{$done->count()} slip {$doneLabel}.";
        if ($skipped->isNotEmpty()) {
            $body .= " {$skipped->count()} dilewati: " . $skipped->take(5)->implode('; ') . ($skipped->count() > 5 ? '; …' : '');
        }

        $notification = Notification::make()->title($done->isEmpty() ? 'Tidak ada yang diproses' : 'Selesai')->body($body);
        $skipped->isEmpty() ? $notification->success()->send() : $notification->warning()->send();

        return $done;
    }

    /**
     * Baris 'draft' TETAP bisa diunduh (bukan cuma 'paid') — berguna buat
     * admin tinjau angkanya sebelum ditandai dibayar, makanya PDF-nya
     * kasih watermark "DRAFT — BELUM FINAL" kalau belum 'paid' (lihat
     * resources/views/pdf/payslip.blade.php), supaya tidak disalahartikan
     * sebagai slip resmi final.
     */
    private static function downloadPayslipPdf(Payroll $record)
    {
        $periodLabel = $record->period_month->translatedFormat('F Y');

        $pdf = Pdf::loadView('pdf.payslip', ['payroll' => $record, 'periodLabel' => $periodLabel])
            ->setPaper('a4', 'portrait');

        $filename = 'Slip-Gaji-' . str_replace(' ', '-', $record->user->name) . '-' . $record->period_month->format('Ym') . '.pdf';

        return response()->streamDownload(fn () => print($pdf->output()), $filename);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayrolls::route('/'),
        ];
    }
}
