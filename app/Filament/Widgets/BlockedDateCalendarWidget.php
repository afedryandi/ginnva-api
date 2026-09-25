<?php

namespace App\Filament\Widgets;

use App\Models\BlockedDate;
use App\Models\Store;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * GAP DIPERBAIKI 2026-09-25 (audit Tanggal Tidak Tersedia, "tidak ada
 * tampilan kalender ringkas") -- SEBELUMNYA daftar tanggal blokir cuma
 * tabel flat, staff harus baca baris satu-satu untuk tahu tanggal mana
 * saja yang tertutup dalam sebulan. Widget ini MURNI BACA (tidak ada
 * aksi edit/hapus di sini, itu tetap lewat tabel di bawahnya) -- pola
 * grid bulan & Livewire property sama persis dengan
 * App\Filament\Pages\CapacityCalendar (sudah terbukti aman dari class
 * Tailwind yang di-compile), cuma disederhanakan jadi read-only.
 */
class BlockedDateCalendarWidget extends Widget
{
    protected static string $view = 'filament.widgets.blocked-date-calendar';

    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    /**
     * Widget ini hidup di app/Filament/Widgets (di-auto-discover
     * PANEL-WIDE lewat AdminPanelProvider::discoverWidgets()) -- TANPA
     * canView() ini, dia akan ikut nongol di Dashboard Utama & semua
     * halaman lain, bukan cuma di List Tanggal Tidak Tersedia seperti
     * niatnya. Pola SAMA PERSIS dengan bug yang sudah ditemukan &
     * diperbaiki di NotCheckedInTodayWidget sesi ini -- pakai
     * getRouteBaseName() dinamis (bukan hardcode string) karena
     * BlockedDateResource ada di dalam BookingCluster (route-nya
     * berprefix cluster, "filament.admin.resources.attendances.index"-
     * style hardcode pernah salah gara-gara ini).
     */
    public static function canView(): bool
    {
        return request()->routeIs(\App\Filament\Resources\BlockedDateResource::getRouteBaseName() . '.index');
    }

    #[Url(as: 'toko_kalender')]
    public ?int $storeId = null;

    #[Url(as: 'bulan_kalender')]
    public string $month = '';

    public function mount(): void
    {
        if ($this->month === '' || $this->month === null) {
            $this->month = now()->format('Y-m');
        }

        $user = auth()->user();

        if (! ($user?->isFullAccess() ?? false)) {
            $this->storeId = $user?->store_id;
        } elseif (! $this->storeId) {
            $this->storeId = Store::where('is_active', true)->orderBy('name')->value('id');
        }
    }

    public function getStoreOptions(): array
    {
        return Store::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    /** Sama pola dengan CapacityCalendar::effectiveStoreId() -- non-full-access selalu terkunci ke toko sendiri. */
    public function effectiveStoreId(): ?int
    {
        $user = auth()->user();

        return ($user?->isFullAccess() ?? false) ? $this->storeId : $user?->store_id;
    }

    public function updatedStoreId(): void
    {
        $user = auth()->user();
        if (! ($user?->isFullAccess() ?? false)) {
            $this->storeId = $user?->store_id;
        }
    }

    public function prevMonth(): void
    {
        $this->month = Carbon::parse($this->month . '-01')->subMonthNoOverflow()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = Carbon::parse($this->month . '-01')->addMonthNoOverflow()->format('Y-m');
    }

    public function goToday(): void
    {
        $this->month = now()->format('Y-m');
    }

    /**
     * @return list<array{date: string, inMonth: bool, isToday: bool, isPast: bool, blocked: bool, reason: ?string}>
     */
    public function getCalendarDays(): array
    {
        $storeId = $this->effectiveStoreId();

        if (! $storeId) {
            return [];
        }

        $monthStart = Carbon::parse($this->month . '-01');
        $monthEnd = $monthStart->copy()->endOfMonth();
        $gridStart = $monthStart->copy()->subDays($monthStart->dayOfWeekIso - 1);
        $gridEnd = $monthEnd->copy()->addDays(7 - $monthEnd->dayOfWeekIso);

        $blocked = BlockedDate::where('store_id', $storeId)
            ->whereBetween('date', [$gridStart->toDateString(), $gridEnd->toDateString()])
            ->get()
            ->keyBy(fn (BlockedDate $b) => $b->date->toDateString());

        $today = now()->startOfDay();
        $days = [];
        $cursor = $gridStart->copy();

        while ($cursor->lte($gridEnd)) {
            $dateStr = $cursor->toDateString();
            $row = $blocked->get($dateStr);

            $days[] = [
                'date'    => $dateStr,
                'inMonth' => $cursor->month === $monthStart->month,
                'isToday' => $cursor->isSameDay($today),
                'isPast'  => $cursor->lt($today),
                'blocked' => $row !== null,
                'reason'  => $row?->reason,
            ];

            $cursor->addDay();
        }

        return $days;
    }
}
