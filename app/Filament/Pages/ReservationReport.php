<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use App\Models\Store;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * "Laporan Reservasi" — diminta 2026-09-09, analog "Laporan Reservasi"
 * Majoo. BEDA dari laporan Penjualan lain (yang berbasis journalEntry/
 * transaction_amount, "sudah jadi uang"): ini berbasis preferred_date
 * booking — daftar JADWAL instalasi (mau confirmed atau masih pending
 * approval), termasuk yang BELUM tentu jadi pendapatan (belum
 * dikerjakan/dibayar). Fokusnya operasional (siapa dijadwalkan kapan),
 * bukan finansial.
 *
 * Status yang ditampilkan: 'confirmed' & 'pending' (yang benar-benar
 * "reservasi aktif") -- 'completed' sudah tercover laporan Penjualan,
 * 'cancelled' sudah tercover Laporan Void, supaya tidak tumpang tindih.
 */
class ReservationReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?string $navigationGroup = 'Laporan Jasa';

    protected static ?string $navigationLabel = 'Laporan Reservasi';

    protected static ?string $title = 'Laporan Reservasi';

    // 102 -- band grup 'Laporan Jasa' (lihat catatan sistem band di
    // ProductSalesReport.php).
    protected static ?int $navigationSort = 102;

    protected static string $view = 'filament.pages.reservation-report';

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
            'status' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),
            Select::make('status')
                ->label('Status')
                ->options(['confirmed' => 'Terkonfirmasi', 'pending' => 'Menunggu Approval'])
                ->placeholder('Semua Status (Confirmed + Pending)')
                ->live(),
        ])->columns(3)->statePath('data');
    }

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();
        $user = auth()->user();
        $isFullAccess = $user?->isFullAccess() ?? false;

        $bookings = Booking::query()
            ->with(['store:id,name', 'installers:id,name'])
            ->whereIn('status', ['confirmed', 'pending'])
            ->when($this->data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->whereBetween('preferred_date', [$from->toDateString(), $to->toDateString()])
            ->when(! $isFullAccess, fn ($q) => $q->where('store_id', $user?->store_id))
            ->orderBy('preferred_date')
            ->get();

        // Stat "Dibuat/Selesai/Dibatalkan/Tingkat Pembatalan" (diminta
        // 2026-09-09, analog Majoo) -- BEDA basis dari tabel di atas:
        // dihitung dari created_at (kapan booking DIAJUKAN customer,
        // bukan preferred_date-nya), dan SEMUA status diikutkan (bukan
        // cuma confirmed/pending) supaya "Tingkat Pembatalan" benar-benar
        // mencerminkan proporsi dari SEMUA yang pernah diajukan di
        // periode ini, sama seperti definisi Majoo.
        $createdInPeriod = Booking::query()
            ->whereBetween('created_at', [$from, $to])
            ->when(! $isFullAccess, fn ($q) => $q->where('store_id', $user?->store_id))
            ->get(['status']);

        $totalCreated = $createdInPeriod->count();
        $totalCancelled = $createdInPeriod->where('status', 'cancelled')->count();

        return [
            'from' => $from,
            'to' => $to,
            'bookings' => $bookings,
            'totalCount' => $bookings->count(),
            'confirmedCount' => $bookings->where('status', 'confirmed')->count(),
            'pendingCount' => $bookings->where('status', 'pending')->count(),
            'totalCreated' => $totalCreated,
            'totalCompleted' => $createdInPeriod->where('status', 'completed')->count(),
            'totalCancelled' => $totalCancelled,
            'cancellationRate' => $totalCreated > 0 ? $totalCancelled / $totalCreated * 100 : 0,
        ];
    }
}
