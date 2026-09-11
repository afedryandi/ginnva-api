<?php

namespace App\Filament\Pages;

use App\Exports\ReservationReportExport;
use App\Models\Booking;
use App\Models\Store;
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

    // #[Url] (audit 2026-09-11, temuan D) — pola sama laporan Penjualan
    // lain.
    #[Url(as: 'from')]
    public ?string $from = null;

    #[Url(as: 'to')]
    public ?string $to = null;

    #[Url(as: 'status')]
    public ?string $statusFilter = null;

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

        if (! in_array($this->statusFilter, ['confirmed', 'pending'], true)) {
            $this->statusFilter = null;
        }

        $this->form->fill([
            'from' => $this->from,
            'to' => $this->to,
            'status' => $this->statusFilter,
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
            'status' => $this->statusFilter = $value ?: null,
            default => null,
        };
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
                    new ReservationReportExport($this->getResult()),
                    'laporan-reservasi-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.reservation_report', ['result' => $result])->setPaper('a4', 'landscape');
                    $filename = 'laporan-reservasi-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
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
            'storeId' => $isFullAccess ? null : $user?->store_id,
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
