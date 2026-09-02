<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PayrollResource\Pages;
use App\Models\Payroll;
use App\Models\Store;
use App\Models\User;
use App\Services\PushNotificationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class PayrollResource extends Resource
{
    protected static ?string $model = Payroll::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Karyawan';

    protected static ?string $navigationLabel = 'Penggajian';

    protected static ?string $modelLabel = 'Penggajian';

    protected static ?string $pluralModelLabel = 'Penggajian';

    protected static ?int $navigationSort = 50;

    // Uang gaji karyawan — SENGAJA lebih ketat dari resource Karyawan
    // lain (Absensi/Izin bisa dilihat store_manager untuk toko sendiri),
    // cuma isFullAccess() yang boleh buka sama sekali. Tidak dikontrol
    // lewat hasMenuAccess()/menuAccessOptions() seperti resource lain,
    // supaya tidak ada kemungkinan store_manager kecentang aksesnya secara
    // tidak sengaja lewat checklist "Akses Menu".
    public static function canViewAny(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
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
                    ->toggleable(),

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
                    ->formatStateUsing(fn (int $state) => $state > 0 ? "{$state} mnt" : '—'),

                Tables\Columns\TextColumn::make('late_violation_days')
                    ->label('Hari Kena Potongan Telat')
                    ->formatStateUsing(fn (int $state) => $state > 0 ? "{$state} hari" : '—')
                    ->color(fn (int $state) => $state > 0 ? 'danger' : 'gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('alpha_days')
                    ->label('Hari Alpha')
                    ->formatStateUsing(fn (int $state) => $state > 0 ? "{$state} hari" : '—')
                    ->color(fn (int $state) => $state > 0 ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('total_deduction')
                    ->label('Total Potongan')
                    ->money('IDR', locale: 'id')
                    ->color(fn (Payroll $record) => $record->total_deduction > 0 ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('net_pay')
                    ->label('Gaji Bersih')
                    ->money('IDR', locale: 'id')
                    ->weight('bold'),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'draft',
                        'success' => 'paid',
                    ])
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'draft' => 'Draft',
                        'paid'  => 'Sudah Dibayar',
                        default => $state,
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(['draft' => 'Draft', 'paid' => 'Sudah Dibayar']),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->relationship('store', 'name'),
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
                        $storeQuery = User::query()
                            ->when($data['store_id'] ?? null, fn ($q) => $q->where('store_id', $data['store_id']))
                            ->where('is_active', true)
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
                        foreach ($users as $user) {
                            try {
                                Payroll::generateForMonth($user, $month);
                                $generated++;
                            } catch (\InvalidArgumentException $e) {
                                $skippedPaid++;
                            }
                        }

                        $inactiveCount = User::query()
                            ->when($data['store_id'] ?? null, fn ($q) => $q->where('store_id', $data['store_id']))
                            ->where('is_active', false)
                            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'partner'))
                            ->count();

                        $bodyLines = ["{$generated} karyawan berhasil digenerate."];
                        if ($skippedPaid > 0) $bodyLines[] = "{$skippedPaid} dilewati (sudah ditandai dibayar).";
                        if ($missingSalary->isNotEmpty()) $bodyLines[] = 'Belum ada Gaji Pokok: ' . $missingSalary->implode(', ') . '.';
                        if ($missingStore->isNotEmpty()) $bodyLines[] = 'Belum terhubung toko: ' . $missingStore->implode(', ') . '.';
                        if ($inactiveCount > 0) $bodyLines[] = "{$inactiveCount} karyawan nonaktif dilewati (tidak digenerate).";

                        $hasWarning = $missingSalary->isNotEmpty() || $missingStore->isNotEmpty();
                        $notification = Notification::make()->title('Payroll digenerate')->body(implode(' ', $bodyLines));
                        $hasWarning ? $notification->warning()->send() : $notification->success()->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('downloadPayslip')
                    ->label('Unduh Slip Gaji')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->action(fn (Payroll $record) => static::downloadPayslipPdf($record)),

                Tables\Actions\Action::make('markPaid')
                    ->label('Tandai Dibayar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Payroll $record) => $record->status === 'draft')
                    ->requiresConfirmation()
                    ->modalDescription('Pastikan gaji sudah benar-benar ditransfer sebelum menandai ini — status ini mengunci baris payroll dari generate ulang.')
                    ->action(function (Payroll $record) {
                        $record->update([
                            'status'   => 'paid',
                            'paid_by'  => auth()->id(),
                            'paid_at'  => now(),
                        ]);

                        app(PushNotificationService::class)->sendToUsers(
                            [$record->user_id],
                            'Gaji Sudah Dibayar',
                            'Gaji periode ' . $record->period_month->translatedFormat('F Y') . ' sudah ditransfer. Buka app untuk lihat rincian.'
                        );

                        Notification::make()->title('Payroll ditandai dibayar')->success()->send();
                    }),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Payroll $record) => $record->status === 'draft'),
            ])
            ->defaultSort('period_month', 'desc');
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
