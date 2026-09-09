<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

/**
 * "Laporan Void" — diminta 2026-09-09, analog "Laporan Void" Majoo:
 * rekap booking yang DIBATALKAN (status berubah jadi 'cancelled') dalam
 * 1 rentang tanggal.
 *
 * Booking TIDAK punya kolom cancelled_at/cancel_reason terpisah, TAPI
 * setiap perubahan status sudah otomatis tercatat di activity_log lewat
 * LogsActivity (lihat Booking::getActivitylogOptions(), logOnlyDirty)
 * — sama sumber data yang dipakai ActivityResource ("Aktivitas"). Jadi
 * "kapan dibatalkan" & "siapa yang membatalkan" DIAMBIL DARI LOG,
 * bukan dari kolom Booking langsung, supaya tidak perlu migrasi kolom
 * baru untuk data yang sebenarnya sudah tercatat.
 *
 * "Potensi kehilangan pendapatan" dihitung dari transaction_amount
 * booking SAAT INI (bukan snapshot nilai transaksi persis di detik
 * dibatalkan) -- untuk booking yang batal SEBELUM transaction_amount
 * pernah diisi, nilainya 0 (memang belum ada nilai transaksi yang
 * hilang). Ini pendekatan yang sama dipakai laporan lain yang membaca
 * kondisi Booking saat ini, bukan histori nilai per field.
 */
class VoidReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-x-circle';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?string $navigationGroup = 'Laporan Penjualan';

    protected static ?string $navigationLabel = 'Laporan Void';

    protected static ?string $title = 'Laporan Void';

    protected static ?int $navigationSort = 8;

    protected static string $view = 'filament.pages.void-report';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),
        ])->columns(2)->statePath('data');
    }

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();
        $user = auth()->user();
        $isFullAccess = $user?->isFullAccess() ?? false;

        $events = Activity::query()
            ->where('subject_type', Booking::class)
            // logOnlyDirty berarti kalau 'status' muncul di
            // properties->attributes, itu PASTI perubahan status (bukan
            // field lain yang kebetulan ikut ter-log) -- dan nilai
            // barunya 'cancelled'.
            ->where('properties->attributes->status', 'cancelled')
            ->whereBetween('created_at', [$from, $to])
            ->with(['causer', 'subject' => fn ($q) => $q->with('store:id,name')])
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (Activity $activity) => $activity->subject !== null)
            ->when(! $isFullAccess, fn ($collection) => $collection->filter(
                fn (Activity $activity) => $activity->subject->store_id === $user?->store_id
            ))
            ->values();

        $totalLostRevenue = $events->sum(fn (Activity $activity) => (float) ($activity->subject->transaction_amount ?? 0));

        return [
            'from' => $from,
            'to' => $to,
            'events' => $events,
            'totalCount' => $events->count(),
            'totalLostRevenue' => $totalLostRevenue,
        ];
    }
}
