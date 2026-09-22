<?php

namespace App\Filament\Pages;

use App\Models\EmployeeScheduleAssignment;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use App\Models\WorkSchedule;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * "Jadwal Kerja Karyawan" — kalender mingguan (karyawan × hari, shift
 * per sel). Audit Majoo vs Ginnva menandai ini SEBAGAI BENTUK UI PALING
 * ACTIONABLE dari seluruh cluster Jadwal Kerja — menyatukan Shift +
 * WorkSchedule + EmployeeScheduleAssignment jadi 1 tampilan operasional
 * yang langsung dipakai owner/admin sehari-hari. Dibangun 2026-09-22.
 *
 * V1 READ-ONLY — belum ada "override 1 hari" (tukar shift dadakan tanpa
 * ubah template permanen) yang disebut di audit; itu enhancement
 * menyusul kalau memang dibutuhkan. Untuk sekarang, ubah jadwal cuma
 * lewat "Terapkan ke Karyawan" di WorkScheduleResource (assign ulang
 * dari tanggal tertentu).
 */
class WorkScheduleCalendarReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calendar';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'Jadwal Kerja';

    protected static ?string $navigationLabel = 'Jadwal Kerja Karyawan';

    protected static ?string $title = 'Jadwal Kerja Karyawan';

    protected static ?int $navigationSort = 42;

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
        if (! is_string($this->weekOf) || $this->weekOf === '') {
            $this->weekOf = now()->toDateString();
        }

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
        $anchor = Carbon::parse($this->weekOf ?? now());
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

        $shiftsById = Shift::whereIn('id', $assignments->pluck('workSchedule')->filter()
            ->flatMap(fn (WorkSchedule $ws) => collect($ws->days)->pluck('shift_id'))
            ->filter()
            ->unique())
            ->get()
            ->keyBy('id');

        $dayCodes = WorkSchedule::DAYS; // ['mon', ..., 'sun'] -- index selaras dgn $days (Senin index 0)

        $rows = $employees->map(function (User $employee) use ($assignments, $days, $dayCodes, $shiftsById) {
            $userAssignments = $assignments->where('user_id', $employee->id);

            $cells = $days->map(function (Carbon $date, int $i) use ($userAssignments, $dayCodes, $shiftsById) {
                $assignment = $userAssignments->first(fn (EmployeeScheduleAssignment $a) => $a->effective_from->lte($date)
                    && ($a->effective_to === null || $a->effective_to->gte($date)));

                if (! $assignment || ! $assignment->workSchedule) {
                    return ['label' => '—', 'color' => null];
                }

                $shiftId = $assignment->workSchedule->shiftIdFor($dayCodes[$i]);

                if (! $shiftId || ! $shiftsById->has($shiftId)) {
                    return ['label' => 'Libur', 'color' => null];
                }

                $shift = $shiftsById->get($shiftId);

                return [
                    'label' => $shift->name,
                    'time' => Carbon::parse($shift->start_time)->format('H:i') . '–' . Carbon::parse($shift->end_time)->format('H:i'),
                    'color' => $shift->color,
                ];
            });

            return [
                'employee' => $employee,
                'cells' => $cells,
            ];
        });

        return ['weekStart' => $weekStart, 'days' => $days, 'rows' => $rows];
    }
}
