<?php

namespace App\Filament\Pages;

use App\Models\EmployeeScheduleAssignment;
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

        $rows = $employees->map(function (User $employee) use ($assignments, $overrides, $days, $dayCodes, $shiftsById) {
            $userAssignments = $assignments->where('user_id', $employee->id);
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

                $assignment = $userAssignments->first(fn (EmployeeScheduleAssignment $a) => $a->effective_from->lte($date)
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

            return [
                'employee' => $employee,
                'cells' => $cells,
            ];
        });

        return ['weekStart' => $weekStart, 'days' => $days, 'rows' => $rows];
    }

    public function overrideDayAction(): Action
    {
        return Action::make('overrideDay')
            ->label('Ubah Jadwal Hari Ini')
            ->modalHeading(fn (array $arguments) => 'Ubah Jadwal — ' . Carbon::parse($arguments['date'])->translatedFormat('l, d M Y'))
            ->modalSubmitActionLabel('Simpan')
            ->form(function (array $arguments) {
                $storeId = (auth()->user()?->isFullAccess() ?? false) ? $this->storeId : auth()->user()?->store_id;

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
                        ->required()
                        ->native(false),

                    Textarea::make('reason')
                        ->label('Keterangan (opsional)')
                        ->placeholder('mis. tukar shift dadakan, izin, dsb.')
                        ->rows(2),
                ];
            })
            ->fillForm(function (array $arguments): array {
                $existing = ScheduleDayOverride::query()
                    ->where('user_id', $arguments['userId'])
                    ->whereDate('date', $arguments['date'])
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
                $employee = User::findOrFail($arguments['userId']);
                $date = Carbon::parse($arguments['date']);

                if ($data['shift_choice'] === '__default') {
                    ScheduleDayOverride::where('user_id', $employee->id)->whereDate('date', $date)->delete();

                    Notification::make()->title('Override dihapus, kembali ke jadwal normal')->success()->send();

                    return;
                }

                ScheduleDayOverride::updateOrCreate(
                    ['user_id' => $employee->id, 'date' => $date->toDateString()],
                    [
                        'store_id' => $employee->store_id,
                        'shift_id' => $data['shift_choice'] === '__off' ? null : (int) $data['shift_choice'],
                        'reason' => $data['reason'] ?: null,
                        'created_by' => auth()->id(),
                    ]
                );

                Notification::make()->title('Jadwal hari itu diubah')->success()->send();
            });
    }
}
