<?php

namespace App\Filament\Pages;

use App\Exports\AttendanceReportExport;
use App\Exports\AttendanceSummaryExport;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Services\OvertimeRequestService;
use App\Models\Store;
use App\Services\AttendancePatternService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
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

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'Absensi & Cuti';

    protected static ?string $navigationLabel = 'Laporan Absensi';

    protected static ?string $title = 'Laporan Absensi';

    // Dipindah dari klaster Penjualan ke Karyawan, grup Absensi & Cuti (2026-11-11): satu tempat dengan Absensi Karyawan. 106 = setelah Pengajuan Lembur (104) dan Pengajuan Dinas Luar (105).
    protected static ?int $navigationSort = 106;

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

        // "Sampai" < "Dari" via URL diutak-atik manual (audit Laporan Absensi 2026-09-29):
        // dikoreksi diam-diam di sini, sama pola dengan laporan Penjualan lain.
        if (Carbon::parse($this->to)->lt(Carbon::parse($this->from))) {
            $this->to = $this->from;
        }

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

        // "Sampai" sebelum "Dari" (audit Laporan Absensi 2026-09-29): sebelumnya diam-diam
        // menghasilkan tabel kosong tanpa penjelasan -- dikoreksi + diberi tahu, sama pola dengan
        // laporan Penjualan lain. TIDAK pakai minDate() reaktif di form (pernah membuat panel
        // filter gagal render di Detail Penjualan) -- validasi murni lewat hook Livewire ini.
        if (in_array($key, ['from', 'to'], true) && $this->from && $this->to && Carbon::parse($this->to)->lt(Carbon::parse($this->from))) {
            $this->to = $this->from;
            $this->data['to'] = $this->from;

            Notification::make()
                ->title('Tanggal "Sampai" tidak boleh sebelum "Dari"')
                ->body('Diset sama dengan tanggal "Dari".')
                ->warning()
                ->send();
        }
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            // Periode Cepat (audit 2026-09-29, sejajar laporan Penjualan lain).
            Select::make('preset')
                ->label('Periode Cepat')
                ->options([
                    'this_month' => 'Bulan ini',
                    'last_month' => 'Bulan lalu',
                    'this_quarter' => 'Kuartal ini',
                    'ytd' => 'Tahun ini (s.d. hari ini)',
                    'last_year' => 'Tahun lalu',
                ])
                ->placeholder('Pilih untuk mengisi tanggal otomatis')
                ->live()
                ->afterStateUpdated(function (?string $state, \Filament\Forms\Set $set) {
                    $range = match ($state) {
                        'this_month' => [now()->startOfMonth(), now()->endOfMonth()],
                        'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
                        'this_quarter' => [now()->startOfQuarter(), now()->endOfQuarter()],
                        'ytd' => [now()->startOfYear(), now()],
                        'last_year' => [now()->subYear()->startOfYear(), now()->subYear()->endOfYear()],
                        default => null,
                    };

                    if ($range) {
                        $set('from', $range[0]->toDateString());
                        $set('to', $range[1]->toDateString());
                        $this->from = $range[0]->toDateString();
                        $this->to = $range[1]->toDateString();
                    }
                }),

            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),
            Select::make('store_id')
                ->label('Toko')
                ->options(fn () => Store::where('is_active', true)->pluck('name', 'id'))
                ->searchable()
                ->placeholder('Semua Toko')
                ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                ->live(),
        ])->columns(4)->statePath('data');
    }

    /**
     * Toko yang BENAR-BENAR berlaku: full-access memilih (null = semua toko), staf toko dikunci ke tokonya, dan
     * staf tanpa toko dikunci ke -1 (tidak cocok toko mana pun) -- bukan null, yang pada query berarti "store_id IS
     * NULL" / semua toko. Dipakai getResult() dan log ekspor supaya keduanya merujuk toko yang sama.
     */
    private function effectiveStoreId(): ?int
    {
        $user = auth()->user();

        if ($user?->isFullAccess() ?? false) {
            $chosen = $this->data['store_id'] ?? null;

            return $chosen ? (int) $chosen : null;
        }

        return $user?->store_id ?? -1;
    }

    /** Log ekspor (audit Laporan Absensi 2026-09-29), konsisten dengan laporan lain. */
    private function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => 'attendance', 'format' => $format, 'from' => $this->data['from'] ?? null, 'to' => $this->data['to'] ?? null, 'store_id' => $this->effectiveStoreId()])
                ->log('Export Laporan Absensi (' . $format . ')');
        } catch (\Throwable $e) {
            report($e);
        }
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
                ->action(function () {
                    $this->logExport('xlsx');

                    return Excel::download(
                        new AttendanceReportExport($this->getResult()),
                        'laporan-absensi-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),

            // Rekap bulanan per karyawan untuk HR/Accurate (gaji tidak dihitung di sistem ini; 2026-11-10).
            Action::make('exportSummary')
                ->label('Export Rekap per Karyawan')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->action(function () {
                    $this->logExport('xlsx-rekap');

                    $result = $this->getResult();

                    return Excel::download(
                        new AttendanceSummaryExport($this->summaryByEmployee($result['rows']), $result['from'], $result['to']),
                        'rekap-absensi-karyawan-' . $result['from']->format('Ymd') . '-' . $result['to']->format('Ymd') . '.xlsx'
                    );
                }),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $this->logExport('pdf');

                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.attendance_report', ['result' => $result])->setPaper('a4', 'landscape');
                    $filename = 'laporan-absensi-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    /**
     * Rekap satu baris per karyawan dari baris Absensi mentah. Hadir = hari dengan absen nyata (clock, manual, tugas lapangan);
     * Alpha dan Izin/Cuti dihitung per baris jenisnya. Urut nama.
     *
     * @param  \Illuminate\Support\Collection<int, Attendance>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function summaryByEmployee(\Illuminate\Support\Collection $rows): array
    {
        $leaveTypes = $this->leaveTypeByRow($rows);
        [$overtimeRecognized, $overtimeUnapproved] = config('features.overtime_requests') ? $this->overtimeMinutesByUser($rows) : [[], []];

        return $rows
            ->filter(fn (Attendance $a) => $a->user !== null)
            ->groupBy('user_id')
            ->map(function (\Illuminate\Support\Collection $group) use ($leaveTypes, $overtimeRecognized, $overtimeUnapproved) {
                $real = $group->filter(fn (Attendance $a) => in_array($a->entry_type, ['clock', 'manual', 'field_duty'], true));

                return [
                    'employee_number' => $group->first()->user->employee_number ?: '-',
                    'name' => $group->first()->user->name,
                    'store' => $group->first()->store?->name ?? '-',
                    'present' => $real->count(),
                    'late_count' => $group->where('late_minutes', '>', 0)->count(),
                    'late_minutes' => (int) $group->sum('late_minutes'),
                    'early_leave_count' => $group->where('early_leave_minutes', '>', 0)->count(),
                    // Total jam kerja dari jam masuk sampai keluar; hari yang belum absen keluar (atau jamnya terbalik) tidak ikut dijumlah.
                    'work_hours' => round($real->sum(fn (Attendance $a) => ($a->clock_in_at && $a->clock_out_at && $a->clock_out_at->gt($a->clock_in_at))
                        ? $a->clock_in_at->diffInMinutes($a->clock_out_at) : 0) / 60, 2),
                    // Hari absen masuk tanpa absen keluar: jamnya tidak ikut Total Jam Kerja, jadi HR perlu tahu angkanya.
                    // Lembur: hanya jam yang disetujui diakui; jam kerja di luar jam resmi tanpa persetujuan ditandai terpisah (lihat OvertimeRequestService).
                    'overtime_hours' => round(($overtimeRecognized[$group->first()->user_id] ?? 0) / 60, 2),
                    'unapproved_hours' => round(($overtimeUnapproved[$group->first()->user_id] ?? 0) / 60, 2),
                    'missing_clock_out' => $real->filter(fn (Attendance $a) => $a->clock_in_at && ! $a->clock_out_at)->count(),
                    'alpha' => $group->where('entry_type', 'alpha')->count(),
                    'leave' => $group->where('entry_type', 'leave')->count(),
                    // Pecahan Izin/Cuti per jenis: {jenis => hari}, dari pengajuan yang disetujui dan menutupi tanggal baris itu.
                    'leave_by_type' => $group->where('entry_type', 'leave')
                        ->countBy(fn (Attendance $a) => $leaveTypes[$a->id] ?? 'Tanpa Pengajuan')
                        ->all(),
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Menit lembur yang diakui dan menit kerja di luar jam resmi tanpa persetujuan, per karyawan, dari baris Absensi pada rentang ini.
     * Jam tutup dan status libur toko dicari sekali per toko per tanggal.
     *
     * \param  \Illuminate\Support\Collection<int, Attendance>  $rows
     * \return array{0: array<int, int>, 1: array<int, int>} [user_id => menit diakui, user_id => menit tanpa persetujuan]
     */
    private function overtimeMinutesByUser(\Illuminate\Support\Collection $rows): array
    {
        $withTimes = $rows->filter(fn (Attendance $a) => $a->clock_in_at && $a->clock_out_at);

        if ($withTimes->isEmpty()) {
            return [[], []];
        }

        $approved = OvertimeRequest::withoutGlobalScopes()
            ->where('status', OvertimeRequest::STATUS_APPROVED)
            ->whereIn('user_id', $withTimes->pluck('user_id')->unique())
            ->whereBetween('date', [$withTimes->min(fn (Attendance $a) => $a->date->toDateString()), $withTimes->max(fn (Attendance $a) => $a->date->toDateString())])
            ->get()
            ->groupBy(fn (OvertimeRequest $r) => $r->user_id . '|' . $r->date->toDateString());

        $service = app(OvertimeRequestService::class);
        $dayInfo = [];
        $recognized = [];
        $unapproved = [];

        foreach ($withTimes as $row) {
            $key = $row->user_id . '|' . $row->date->toDateString();
            $forDay = $approved[$key] ?? collect();

            $infoKey = $row->store_id . '|' . $row->date->toDateString();
            $dayInfo[$infoKey] ??= $row->store
                ? (($closed = $row->store->isClosedOn($row->date)) ? [true, null] : [false, $row->store->closingTimeOn($row->date)])
                : [false, null];

            [$closed, $closing] = $dayInfo[$infoKey];

            $recognized[$row->user_id] = ($recognized[$row->user_id] ?? 0) + $service->recognizedMinutes($row, $forDay);
            $unapproved[$row->user_id] = ($unapproved[$row->user_id] ?? 0) + $service->unapprovedExtraMinutes($row, $forDay, $closed, $closing);
        }

        return [$recognized, $unapproved];
    }

    /**
     * Jenis izin/cuti tiap baris Absensi berjenis leave: dicari dari pengajuan yang disetujui dan menutupi tanggalnya (baris Izin/Cuti tidak
     * menyimpan tautan ke pengajuan). Baris tanpa pengajuan yang cocok (mis. entri manual) tidak masuk peta ini.
     *
     * \param  \Illuminate\Support\Collection<int, Attendance>  $rows
     * \return array<int, string> id baris Absensi => nama jenis
     */
    private function leaveTypeByRow(\Illuminate\Support\Collection $rows): array
    {
        $leaveRows = $rows->where('entry_type', 'leave');

        if ($leaveRows->isEmpty()) {
            return [];
        }

        $requests = LeaveRequest::with('leaveType:id,name')
            ->where('status', 'approved')
            ->whereIn('user_id', $leaveRows->pluck('user_id')->unique())
            ->whereDate('start_date', '<=', $leaveRows->max(fn (Attendance $a) => $a->date->toDateString()))
            ->whereDate('end_date', '>=', $leaveRows->min(fn (Attendance $a) => $a->date->toDateString()))
            ->get()
            ->groupBy('user_id');

        $map = [];

        foreach ($leaveRows as $row) {
            $request = ($requests[$row->user_id] ?? collect())->first(fn (LeaveRequest $r) => $r->start_date->lte($row->date) && $r->end_date->gte($row->date));

            if ($request) {
                $map[$row->id] = $request->leaveType?->name ?? ucfirst((string) $request->type);
            }
        }

        return $map;
    }

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth());
        $storeId = $this->effectiveStoreId();

        $rows = Attendance::query()
            ->with(['user:id,name,employee_number', 'store'])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($storeId !== null, fn ($q) => $q->where('store_id', $storeId))
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
                    // Hanya baris yang benar-benar absen (clock/manual/tugas lapangan) yang bisa "tanpa jadwal" --
                    // baris alpha & izin/cuti tidak punya jam masuk untuk dibandingkan, jadi tidak dihitung di sini.
                    'noScheduleCount' => $group
                        ->filter(fn (array $c) => ! $c['hasSchedule'] && in_array($c['attendance']->entry_type, ['clock', 'manual', 'field_duty'], true))
                        ->count(),
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
