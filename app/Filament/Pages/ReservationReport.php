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
use Filament\Notifications\Notification;
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
        $this->from = $this->queryDateOrDefault($this->from, now()->startOfMonth());
        $this->to = $this->queryDateOrDefault($this->to, now()->endOfMonth());

        // "Sampai" < "Dari" via URL diutak-atik manual (audit Laporan Reservasi 2026-09-29):
        // dikoreksi diam-diam di sini, sama pola dengan laporan Penjualan lain.
        if (Carbon::parse($this->to)->lt(Carbon::parse($this->from))) {
            $this->to = $this->from;
        }

        if (! in_array($this->statusFilter, ['confirmed', 'pending'], true)) {
            $this->statusFilter = null;
        }

        // Filter cabang (audit 2026-09-29, sejajar laporan Penjualan lain) -- staff toko TIDAK
        // PERNAH boleh pilih cabang lain, URL yang tidak sah diabaikan.
        if (! (auth()->user()?->isFullAccess() ?? false)) {
            $this->storeId = null;
        }

        $this->form->fill([
            'from' => $this->from,
            'to' => $this->to,
            'status' => $this->statusFilter,
            'store_id' => $this->storeId,
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
            'store_id' => $this->storeId = $value ? (int) $value : null,
            default => null,
        };

        // "Sampai" sebelum "Dari" (audit Laporan Reservasi 2026-09-29): sebelumnya diam-diam
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
        $isFullAccess = auth()->user()?->isFullAccess() ?? false;

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
            Select::make('status')
                ->label('Status')
                ->options(['confirmed' => 'Terkonfirmasi', 'pending' => 'Menunggu Approval'])
                ->placeholder('Semua Status (Confirmed + Pending)')
                ->live(),

            // Filter cabang (audit 2026-09-29) -- cuma untuk full-access, sama pola dengan
            // laporan Penjualan lain.
            Select::make('store_id')
                ->label('Cabang')
                ->placeholder('Semua cabang')
                ->options(fn () => Store::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->visible($isFullAccess)
                ->live(),
        ])->columns($isFullAccess ? 5 : 4)->statePath('data');
    }

    /** Link drill-down ke halaman detail booking. */
    public function bookingUrl(int $bookingId): string
    {
        return \App\Filament\Resources\BookingResource::getUrl('view', ['record' => $bookingId]);
    }

    /** Log ekspor (audit Laporan Reservasi 2026-09-29), konsisten dengan laporan lain. */
    private function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => 'reservation', 'format' => $format, 'from' => $this->data['from'] ?? null, 'to' => $this->data['to'] ?? null, 'status' => $this->data['status'] ?? null, 'store_id' => $this->data['store_id'] ?? null])
                ->log('Ekspor Laporan Reservasi (' . $format . ')');
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
                        new ReservationReportExport($this->getResult()),
                        'laporan-reservasi-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $this->logExport('pdf');

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
        // GAP DIPERBAIKI 2026-09-29: full-access sebelumnya tidak bisa mempersempit ke 1 cabang --
        // sekarang filter 'store_id' di form dipakai kalau full-access memilihnya.
        $storeId = $isFullAccess ? ($this->data['store_id'] ?? null) : $user?->store_id;

        $bookings = Booking::query()
            ->with(['store:id,name', 'installers:id,name'])
            ->whereIn('status', ['confirmed', 'pending'])
            ->when($this->data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->whereBetween('preferred_date', [$from->toDateString(), $to->toDateString()])
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
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
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->get(['status']);

        $totalCreated = $createdInPeriod->count();
        $totalCancelled = $createdInPeriod->where('status', 'cancelled')->count();

        return [
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
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
