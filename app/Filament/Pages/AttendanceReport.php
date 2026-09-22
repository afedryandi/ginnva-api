<?php

namespace App\Filament\Pages;

use App\Exports\AttendanceReportExport;
use App\Models\Attendance;
use App\Models\Store;
use App\Services\AttendancePatternService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Facades\Excel;

/**
 * "Laporan Absensi" — diminta 2026-09-09, analog "Absensi" Majoo. BEDA
 * dari EmployeeReport ("Laporan Karyawan") yang ringkasan BULANAN dari
 * Payroll -- halaman ini absensi HARIAN mentah, langsung dari
 * `Attendance` (clock_in_at/clock_out_at/late_minutes/early_leave_minutes
 * per baris, sudah lengkap tersimpan dari fitur Absensi mobile app).
 *
 * KETERBATASAN: Attendance TIDAK menyimpan "Jadwal Masuk/Pulang" (jam
 * target) per baris -- cuma late_minutes/early_leave_minutes yang SUDAH
 * dihitung sistem terhadap jadwal itu saat clock in/out. Jadi kolom
 * "Jadwal Masuk/Pulang" mentah ala Majoo TIDAK ditampilkan per baris di
 * tabel Rincian Absensi (datanya tidak tersimpan terpisah).
 *
 * "Masuk Lebih Cepat"/"Keluar Lebih Lama" (audit Majoo f23, "6 jenis
 * keterlambatan/kecepatan") SEKARANG BISA dihitung (2026-09-22) berkat
 * modul Jadwal Kerja yang dibangun hari yang sama -- lihat tabel "Pola
 * Ketepatan Waktu per Karyawan" & App\Services\AttendancePatternService,
 * yang membandingkan clock_in_at/clock_out_at terhadap Shift yang
 * berlaku (via EmployeeScheduleAssignment/ScheduleDayOverride).
 * Karyawan yang belum di-assign Jadwal Kerja tetap TIDAK bisa
 * dibandingkan (ditandai "Tanpa Jadwal", bukan dipaksa 0).
 */
class AttendanceReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-finger-print';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?string $navigationGroup = 'Laporan Karyawan';

    protected static ?string $navigationLabel = 'Absensi';

    protected static ?string $title = 'Laporan Absensi';

    // 402 -- band grup 'Laporan Karyawan' (lihat catatan sistem band di
    // ProductSalesReport.php).
    protected static ?int $navigationSort = 402;

    protected static string $view = 'filament.pages.attendance-report';

    public ?array $data = [];

    // #[Url] (audit 2026-09-11, temuan D) — pola sama laporan Penjualan
    // lain.
    #[Url(as: 'from')]
    public ?string $from = null;

    #[Url(as: 'to')]
    public ?string $to = null;

    #[Url(as: 'cabang')]
    public ?int $storeIdFilter = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    public function mount(): void
    {
        $this->from = $this->queryDateOrDefault($this->from, now()->startOfMonth());
        $this->to = $this->queryDateOrDefault($this->to, now()->endOfMonth());

        if (! (auth()->user()?->isFullAccess() ?? false)) {
            $this->storeIdFilter = null;
        }

        $this->form->fill([
            'from' => $this->from,
            'to' => $this->to,
            'store_id' => $this->storeIdFilter,
        ]);
    }

    private function queryDateOrDefault(mixed $value, Carbon $default): string
    {
        if (! is_string($value) || $value === '') {
            return $default->toDateString();
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return $default->toDateString();
        }
    }

    public function updatedData(mixed $value, string $key): void
    {
        match ($key) {
            'from' => $this->from = $value,
            'to' => $this->to = $value,
            'store_id' => $this->storeIdFilter = $value ? (int) $value : null,
            default => null,
        };
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),
            Select::make('store_id')
                ->label('Toko')
                ->options(fn () => Store::where('is_active', true)->pluck('name', 'id'))
                ->searchable()
                ->placeholder('Semua Toko')
                ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                ->live(),
        ])->columns(3)->statePath('data');
    }

    /**
     * "Ekspor Laporan" (audit 2026-09-11, temuan B) — pola sama laporan
     * Penjualan lain.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new AttendanceReportExport($this->getResult()),
                    'laporan-absensi-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.attendance_report', ['result' => $result])->setPaper('a4', 'landscape');
                    $filename = 'laporan-absensi-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth());
        $user = auth()->user();
        $isFullAccess = $user?->isFullAccess() ?? false;

        $rows = Attendance::query()
            ->with(['user:id,name', 'store:id,name'])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when(! $isFullAccess, fn ($q) => $q->where('store_id', $user?->store_id))
            ->when($isFullAccess && ! empty($this->data['store_id']), fn ($q) => $q->where('store_id', $this->data['store_id']))
            ->orderByDesc('date')
            ->get();

        // Kategorisasi detail (audit Majoo f23, "6 jenis keterlambatan/
        // kecepatan") -- melengkapi lateCount/earlyLeaveCount lama dengan
        // 2 kategori simetris baru (Masuk Lebih Awal, Lembur/Pulang
        // Lambat) yang sekarang bisa dihitung berkat modul Jadwal Kerja.
        // Lihat AttendancePatternService untuk penjelasan lengkap.
        $classified = app(AttendancePatternService::class)->classify($rows);

        $patternByUser = $classified
            ->filter(fn (array $c) => $c['attendance']->user !== null)
            ->groupBy(fn (array $c) => $c['attendance']->user_id)
            ->map(function (\Illuminate\Support\Collection $group) {
                return [
                    'user' => $group->first()['attendance']->user,
                    'lateCount' => $group->where('isLate', true)->count(),
                    'earlyLeaveCount' => $group->where('isEarlyLeave', true)->count(),
                    'earlyArrivalCount' => $group->where('isEarlyArrival', true)->count(),
                    'overtimeCount' => $group->where('isOvertime', true)->count(),
                    'noScheduleCount' => $group->where('hasSchedule', false)->count(),
                ];
            })
            ->sortBy(fn ($row) => $row['user']->name)
            ->values();

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'onTimeCount' => $rows->filter(fn (Attendance $a) => in_array($a->entry_type, ['clock', 'manual', 'field_duty'], true) && $a->late_minutes <= 0 && $a->early_leave_minutes <= 0)->count(),
            'lateCount' => $rows->where('late_minutes', '>', 0)->count(),
            'earlyLeaveCount' => $rows->where('early_leave_minutes', '>', 0)->count(),
            'alphaCount' => $rows->where('entry_type', 'alpha')->count(),
            'leaveCount' => $rows->where('entry_type', 'leave')->count(),
            'earlyArrivalCount' => $classified->where('isEarlyArrival', true)->count(),
            'overtimeCount' => $classified->where('isOvertime', true)->count(),
            'patternByUser' => $patternByUser,
        ];
    }
}
