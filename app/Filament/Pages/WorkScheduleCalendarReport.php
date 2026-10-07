<?php

namespace App\Filament\Pages;

use App\Models\Attendance;
use App\Models\EmployeeScheduleAssignment;
use App\Models\LeaveRequest;
use App\Models\ScheduleDayOverride;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use App\Models\WorkSchedule;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * "Jadwal Kerja Karyawan" — kalender mingguan (karyawan × hari, shift
 * per sel). Audit Majoo vs Ginnva menandai ini SEBAGAI BENTUK UI PALING
 * ACTIONABLE dari seluruh cluster Jadwal Kerja — menyatukan Shift +
 * WorkSchedule + EmployeeScheduleAssignment jadi 1 tampilan operasional
 * yang langsung dipakai owner/admin sehari-hari. Dibangun 2026-09-22.
 *
 * Sejak 2026-09-22: quick-edit per hari via klik sel — override
 * ScheduleDayOverride (1 baris = 1 karyawan + 1 tanggal) menimpa hasil
 * template WorkSchedule TANPA mengubah template itu sendiri. Ubah jadwal
 * yang sifatnya permanen/berulang tetap lewat "Terapkan ke Karyawan" di
 * WorkScheduleResource.
 */
class WorkScheduleCalendarReport extends Page implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calendar';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'Jadwal Kerja';

    protected static ?string $navigationLabel = 'Jadwal Kerja Karyawan';

    protected static ?string $title = 'Jadwal Kerja Karyawan';

    // Direnumber ke band 200-299 (audit navigasi 2026-09-29).
    protected static ?int $navigationSort = 202;

    protected static string $view = 'filament.pages.work-schedule-calendar-report';

    public ?array $data = [];

    #[Url(as: 'minggu')]
    public ?string $weekOf = null;

    #[Url(as: 'cabang')]
    public ?int $storeId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    public function mount(): void
    {
        $this->weekOf = $this->safeWeekAnchor($this->weekOf)->toDateString();

        if (! (auth()->user()?->isFullAccess() ?? false)) {
            $this->storeId = auth()->user()?->store_id;
        }

        $this->form->fill([
            'weekOf' => $this->weekOf,
            'store_id' => $this->storeId,
        ]);
    }

    public function updatedData(mixed $value, string $key): void
    {
        match ($key) {
            'weekOf' => $this->weekOf = $value,
            'store_id' => $this->storeId = $value ? (int) $value : null,
            default => null,
        };
    }

    public function form(Form $form): Form
    {
        $isFullAccess = auth()->user()?->isFullAccess() ?? false;

        return $form->schema([
            DatePicker::make('weekOf')
                ->label('Minggu (tanggal apa saja dalam minggu itu)')
                ->native(false)
                ->required()
                ->live(),

            Select::make('store_id')
                ->label('Cabang')
                ->options(fn () => Store::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->visible($isFullAccess)
                ->required($isFullAccess)
                ->live(),
        ])->columns($isFullAccess ? 2 : 1)->statePath('data');
    }

    /**
     * @return array{weekStart: Carbon, days: Carbon[], rows: Collection}
     */
    public function getCalendar(): array
    {
        $anchor = $this->safeWeekAnchor($this->weekOf);
        $weekStart = $anchor->copy()->startOfWeek(Carbon::MONDAY);
        $days = collect(range(0, 6))->map(fn (int $i) => $weekStart->copy()->addDays($i));

        $storeId = (auth()->user()?->isFullAccess() ?? false) ? $this->storeId : auth()->user()?->store_id;

        if (! $storeId) {
            return ['weekStart' => $weekStart, 'days' => $days, 'rows' => collect()];
        }

        $employees = User::query()
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', ['partner']))
            ->orderBy('name')
            ->get();

        // Ambil SEMUA penugasan yang overlap rentang minggu ini sekaligus
        // (bukan query per-karyawan-per-hari) supaya tidak N+1 -- lalu
        // dicocokkan di memori.
        $assignments = EmployeeScheduleAssignment::query()
            ->where('store_id', $storeId)
            ->whereIn('user_id', $employees->pluck('id'))
            ->whereDate('effective_from', '<=', $weekStart->copy()->endOfWeek(Carbon::SUNDAY))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $weekStart))
            ->with('workSchedule')
            ->get();

        $overrides = ScheduleDayOverride::query()
            ->where('store_id', $storeId)
            ->whereIn('user_id', $employees->pluck('id'))
            ->whereBetween('date', [$weekStart->toDateString(), $weekStart->copy()->endOfWeek(Carbon::SUNDAY)->toDateString()])
            ->get();

        $shiftIdsFromSchedules = $assignments->pluck('workSchedule')->filter()
            ->flatMap(fn (WorkSchedule $ws) => collect($ws->days)->pluck('shift_id'))
            ->filter();

        $shiftsById = Shift::whereIn('id', $shiftIdsFromSchedules->merge($overrides->pluck('shift_id')->filter())->unique())
            ->get()
            ->keyBy('id');

        $dayCodes = WorkSchedule::DAYS; // ['mon', ..., 'sun'] -- index selaras dgn $days (Senin index 0)

        // Izin & Cuti yang SUDAH DISETUJUI di minggu ini -- ditandai di sel
        // (gap audit Jadwal Kerja Karyawan 2026-09-28: kalender tidak tahu
        // karyawan sedang cuti). Diambil sekali (bukan per sel).
        $leaves = LeaveRequest::query()
            ->whereIn('user_id', $employees->pluck('id'))
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $weekStart->copy()->endOfWeek(Carbon::SUNDAY))
            ->whereDate('end_date', '>=', $weekStart)
            ->get(['id', 'user_id', 'type', 'start_date', 'end_date']);

        $store = Store::find($storeId);
        $closedDays = $days->map(fn (Carbon $d) => (bool) $store?->isClosedOn($d))->all();

        $rows = $employees->map(function (User $employee) use ($assignments, $overrides, $days, $dayCodes, $shiftsById, $leaves) {
            $userAssignments = $assignments->where('user_id', $employee->id);
            $userLeaves = $leaves->where('user_id', $employee->id);
            $userOverrides = $overrides->where('user_id', $employee->id)->keyBy(fn (ScheduleDayOverride $o) => $o->date->toDateString());

            $cells = $days->map(function (Carbon $date, int $i) use ($userAssignments, $userOverrides, $dayCodes, $shiftsById) {
                $override = $userOverrides->get($date->toDateString());

                if ($override) {
                    if (! $override->shift_id || ! $shiftsById->has($override->shift_id)) {
                        return ['label' => 'Libur', 'color' => null, 'is_override' => true, 'shift_id' => null, 'reason' => $override->reason];
                    }

                    $shift = $shiftsById->get($override->shift_id);

                    return [
                        'label' => $shift->name,
                        'time' => Carbon::parse($shift->start_time)->format('H:i') . '–' . Carbon::parse($shift->end_time)->format('H:i'),
                        'color' => $shift->color,
                        'is_override' => true,
                        'shift_id' => $shift->id,
                        'reason' => $override->reason,
                    ];
                }

                // sortByDesc('effective_from') supaya konsisten dengan
                // Attendance::resolveShiftFor() kalau ada penugasan
                // tumpang tindih (audit Jadwal Kerja Karyawan 2026-09-28).
                $assignment = $userAssignments->sortByDesc('effective_from')->first(fn (EmployeeScheduleAssignment $a) => $a->effective_from->lte($date)
                    && ($a->effective_to === null || $a->effective_to->gte($date)));

                if (! $assignment || ! $assignment->workSchedule) {
                    return ['label' => '—', 'color' => null, 'is_override' => false, 'shift_id' => null];
                }

                $shiftId = $assignment->workSchedule->shiftIdFor($dayCodes[$i]);

                if (! $shiftId || ! $shiftsById->has($shiftId)) {
                    return ['label' => 'Libur', 'color' => null, 'is_override' => false, 'shift_id' => null];
                }

                $shift = $shiftsById->get($shiftId);

                return [
                    'label' => $shift->name,
                    'time' => Carbon::parse($shift->start_time)->format('H:i') . '–' . Carbon::parse($shift->end_time)->format('H:i'),
                    'color' => $shift->color,
                    'is_override' => false,
                    'shift_id' => $shift->id,
                ];
            });

            $cells = $cells->map(function (array $cell, int $i) use ($userLeaves, $days) {
                $leave = $userLeaves->first(fn (LeaveRequest $l) => $l->start_date->lte($days[$i]) && $l->end_date->gte($days[$i]));

                return $cell + ['leave' => $leave ? strtoupper((string) $leave->type) : null];
            });

            return [
                'employee' => $employee,
                'cells' => $cells,
            ];
        });

        return ['weekStart' => $weekStart, 'days' => $days, 'rows' => $rows, 'closedDays' => $closedDays];
    }

    public function shiftWeek(int $days): void
    {
        $this->weekOf = $this->safeWeekAnchor($this->weekOf)->addDays($days)->toDateString();
        $this->form->fill([
            'weekOf' => $this->weekOf,
            'store_id' => $this->storeId,
        ]);
    }

    /** Parse `weekOf` dengan aman -- nilai aneh (?minggu=abc) jatuh ke hari ini, bukan error 500. */
    private function safeWeekAnchor(?string $value): Carbon
    {
        try {
            return $value ? Carbon::parse($value) : now();
        } catch (\Throwable) {
            return now();
        }
    }

    private function visibleStoreId(): ?int
    {
        return (auth()->user()?->isFullAccess() ?? false) ? $this->storeId : auth()->user()?->store_id;
    }

    /**
     * Validasi SERVER atas argumen klik-sel (userId & date datang dari
     * Livewire dan bisa dimanipulasi klien) -- audit Jadwal Kerja Karyawan
     * 2026-09-28. Karyawan harus aktif, bukan partner, dan berada di toko
     * yang sedang ditampilkan/diakses admin; tanggal harus valid dan
     * berada di minggu yang sedang ditampilkan.
     *
     * @return array{0: User, 1: Carbon}
     */
    private function resolveOverrideTarget(array $arguments): array
    {
        $storeId = $this->visibleStoreId();

        $employee = User::query()
            ->where('id', $arguments['userId'] ?? 0)
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'partner'))
            ->first();

        if (! $employee) {
            throw new \InvalidArgumentException('Karyawan tidak valid atau bukan di toko yang sedang ditampilkan.');
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', (string) ($arguments['date'] ?? ''))->startOfDay();
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Tanggal tidak valid.');
        }

        $weekStart = $this->safeWeekAnchor($this->weekOf)->startOfWeek(Carbon::MONDAY)->startOfDay();
        if ($date->lt($weekStart) || $date->gt($weekStart->copy()->addDays(6))) {
            throw new \InvalidArgumentException('Tanggal di luar minggu yang sedang ditampilkan.');
        }

        return [$employee, $date];
    }

    public function overrideDayAction(): Action
    {
        return Action::make('overrideDay')
            ->label('Ubah Jadwal Hari Ini')
            ->visible(fn () => auth()->user()?->hasModuleAction(static::class, 'update', true) ?? false)
            // Tanggal datang dari klien -- jangan Carbon::parse mentah di sini (nilai aneh = error 500
            // sebelum validasi server di resolveOverrideTarget() sempat menolaknya).
            ->modalHeading(fn (array $arguments) => 'Ubah Jadwal — ' . $this->safeWeekAnchor($arguments['date'] ?? null)->translatedFormat('l, d M Y'))
            ->modalSubmitActionLabel('Simpan')
            ->form(function () {
                $storeId = $this->visibleStoreId();

                return [
                    Select::make('shift_choice')
                        ->label('Shift')
                        ->options(function () use ($storeId) {
                            $options = ['__default' => 'Ikuti Jadwal Normal (hapus override)', '__off' => 'Libur (override)'];

                            foreach (Shift::query()->where('store_id', $storeId)->where('is_active', true)->orderBy('name')->get() as $shift) {
                                $options[$shift->id] = $shift->name . ' (' . Carbon::parse($shift->start_time)->format('H:i') . '–' . Carbon::parse($shift->end_time)->format('H:i') . ')';
                            }

                            return $options;
                        })
                        ->helperText('"Ikuti Jadwal Normal" MENGHAPUS override hari ini (kembali ke jadwal template). "Libur (override)" menandai hari ini libur khusus.')
                        ->required()
                        ->native(false),

                    Textarea::make('reason')
                        ->label('Keterangan (opsional)')
                        ->placeholder('mis. tukar shift dadakan, izin, dsb.')
                        ->rows(2),
                ];
            })
            ->fillForm(function (array $arguments): array {
                try {
                    [$employee, $date] = $this->resolveOverrideTarget($arguments);
                } catch (\InvalidArgumentException) {
                    return ['shift_choice' => '__default', 'reason' => null];
                }

                $existing = ScheduleDayOverride::query()
                    ->where('user_id', $employee->id)
                    ->where('store_id', $employee->store_id)
                    ->whereDate('date', $date)
                    ->first();

                if (! $existing) {
                    return ['shift_choice' => '__default', 'reason' => null];
                }

                return [
                    'shift_choice' => $existing->shift_id ?: '__off',
                    'reason' => $existing->reason,
                ];
            })
            ->action(function (array $arguments, array $data): void {
                try {
                    [$employee, $date] = $this->resolveOverrideTarget($arguments);

                    $this->saveOverride($employee, $date, $data);
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->title('Tidak bisa mengubah jadwal')->body($e->getMessage())->danger()->send();
                }
            });
    }

    /**
     * Semua tulis/hapus override di 1 transaksi dgn lock baris karyawan --
     * dua admin di sel yang sama tidak lagi menghasilkan QueryException/
     * last-write-wins diam-diam. Hapus per-MODEL (bukan mass delete) supaya
     * LogsActivity mencatat 'deleted' (audit trail siapa menghapus).
     */
    private function saveOverride(User $employee, Carbon $date, array $data): void
    {
        $message = null;

        DB::transaction(function () use ($employee, $date, $data, &$message) {
            User::whereKey($employee->id)->lockForUpdate()->first();

            // Hari yang sudah punya absensi tidak boleh di-override:
            // mengganti shift/libur mengubah klasifikasi telat historis.
            if ($date->lt(today()) && Attendance::where('user_id', $employee->id)->whereDate('date', $date)->exists()) {
                throw new \InvalidArgumentException('Hari ini sudah punya data absensi, jadwalnya tidak bisa diubah lagi.');
            }

            if ($data['shift_choice'] === '__default') {
                $removed = ScheduleDayOverride::where('user_id', $employee->id)->whereDate('date', $date)->get();
                $removed->each->delete();

                if ($removed->isNotEmpty()) {
                    $message = 'Jadwal Anda pada ' . $date->translatedFormat('d M Y') . ' kembali ke jadwal normal.';
                }

                Notification::make()->title('Override dihapus, kembali ke jadwal normal')->success()->send();

                return;
            }

            // Bentrok dengan Izin & Cuti yang sudah disetujui: menjadwalkan
            // shift kerja di hari cuti disetujui hampir pasti salah -- batalkan
            // cutinya dulu kalau memang mau dipanggil masuk. Menandai "Libur"
            // tetap boleh.
            if ($data['shift_choice'] !== '__off' && LeaveRequest::where('user_id', $employee->id)
                ->where('status', 'approved')
                ->whereDate('start_date', '<=', $date)
                ->whereDate('end_date', '>=', $date)
                ->exists()) {
                throw new \InvalidArgumentException('Karyawan ini punya Izin/Cuti yang sudah disetujui pada tanggal tersebut, tidak bisa dijadwalkan masuk kerja.');
            }

            $shiftId = null;
            if ($data['shift_choice'] !== '__off') {
                $shift = Shift::where('id', (int) $data['shift_choice'])
                    ->where('store_id', $employee->store_id)
                    ->where('is_active', true)
                    ->first();

                if (! $shift) {
                    throw new \InvalidArgumentException('Shift tidak ditemukan, nonaktif, atau bukan milik toko karyawan ini.');
                }

                $shiftId = $shift->id;
            }

            ScheduleDayOverride::updateOrCreate(
                ['user_id' => $employee->id, 'date' => $date->toDateString()],
                [
                    'store_id' => $employee->store_id,
                    'shift_id' => $shiftId,
                    'reason' => $data['reason'] ?: null,
                    'created_by' => auth()->id(),
                ]
            );

            $what = $shiftId ? 'shift "' . Shift::find($shiftId)?->name . '"' : 'LIBUR';
            $message = 'Jadwal Anda pada ' . $date->translatedFormat('d M Y') . ' diubah menjadi ' . $what . '.';

            Notification::make()->title('Jadwal hari itu diubah')->success()->send();
        });

        // Push ke karyawan yang bersangkutan (gap audit 2026-09-28) --
        // setelah transaksi commit, dan tidak untuk hari yang sudah lewat.
        if ($message && ! $date->lt(today())) {
            app(\App\Services\PushNotificationService::class)->sendToUsers([$employee->id], 'Jadwal Kerja Diubah', $message);
        }
    }
}
