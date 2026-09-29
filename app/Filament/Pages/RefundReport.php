<?php

namespace App\Filament\Pages;

use App\Exports\RefundReportExport;
use App\Models\Refund;
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
 * "Laporan Refund" — diminta 2026-09-09, analog "Laporan Refund" Majoo.
 * SEBELUMNYA blocked total (tidak ada mekanisme refund sama sekali) --
 * dibangun setelah aturan dikonfirmasi user: refund bisa PARSIAL, tiap
 * refund WAJIB bikin jurnal kontra otomatis (lihat RefundService, aksi
 * "Proses Refund" di BookingResource).
 *
 * Data di sini murni membaca tabel refunds (sumber kebenaran terpisah
 * dari Booking.transaction_amount) -- SalesSummaryReport/
 * SalesByPeriodReport/SalesByOutletReport ikut diperbarui memakai
 * sumber yang SAMA supaya "Pengembalian" konsisten di semua laporan.
 */
class RefundReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-refund';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?string $navigationGroup = 'Laporan Penjualan';

    protected static ?string $navigationLabel = 'Laporan Refund';

    protected static ?string $title = 'Laporan Refund';

    protected static ?int $navigationSort = 9;

    protected static string $view = 'filament.pages.refund-report';

    public ?array $data = [];

    // #[Url] (audit 2026-09-11, temuan D) — pola sama laporan Penjualan
    // lain.
    #[Url(as: 'from')]
    public ?string $from = null;

    #[Url(as: 'to')]
    public ?string $to = null;

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

        // "Sampai" < "Dari" via URL diutak-atik manual (audit Laporan Refund 2026-09-29):
        // dikoreksi diam-diam di sini, sama pola dengan laporan Penjualan lain.
        if (Carbon::parse($this->to)->lt(Carbon::parse($this->from))) {
            $this->to = $this->from;
        }

        // Filter cabang (audit 2026-09-29, sejajar laporan Penjualan lain) -- staff toko TIDAK
        // PERNAH boleh pilih cabang lain, URL yang tidak sah diabaikan.
        if (! (auth()->user()?->isFullAccess() ?? false)) {
            $this->storeId = null;
        }

        $this->form->fill([
            'from' => $this->from,
            'to' => $this->to,
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
            'store_id' => $this->storeId = $value ? (int) $value : null,
            default => null,
        };

        // "Sampai" sebelum "Dari" (audit Laporan Refund 2026-09-29): sebelumnya diam-diam
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

            // Filter cabang (audit 2026-09-29) -- cuma untuk full-access, sama pola dengan
            // laporan Penjualan lain.
            Select::make('store_id')
                ->label('Cabang')
                ->placeholder('Semua cabang')
                ->options(fn () => Store::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->visible($isFullAccess)
                ->live(),
        ])->columns($isFullAccess ? 4 : 3)->statePath('data');
    }

    /** Link drill-down ke halaman detail booking asal refund. */
    public function bookingUrl(int $bookingId): string
    {
        return \App\Filament\Resources\BookingResource::getUrl('view', ['record' => $bookingId]);
    }

    /** Log ekspor (audit Laporan Refund 2026-09-29), konsisten dengan laporan lain. */
    private function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => 'refund', 'format' => $format, 'from' => $this->data['from'] ?? null, 'to' => $this->data['to'] ?? null, 'store_id' => $this->data['store_id'] ?? null])
                ->log('Ekspor Laporan Refund (' . $format . ')');
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
                        new RefundReportExport($this->getResult()),
                        'laporan-refund-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $this->logExport('pdf');

                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.refund_report', ['result' => $result])->setPaper('a4', 'landscape');
                    $filename = 'laporan-refund-' . now()->format('Ymd-His') . '.pdf';

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
        // GAP DIPERBAIKI 2026-09-29: full-access sebelumnya tidak bisa mempersempit ke 1 cabang di
        // laporan ini -- sekarang filter 'store_id' di form dipakai kalau full-access memilihnya.
        $storeId = $isFullAccess ? ($this->data['store_id'] ?? null) : $user?->store_id;

        $refunds = Refund::query()
            ->whereBetween('created_at', [$from, $to])
            ->with([
                'booking:id,booking_number,customer_name,store_id',
                'booking.store:id,name',
                'creator:id,name',
                // BUG DIPERBAIKI 2026-09-11: deskripsi halaman ("klik
                // No. Jurnal untuk lihat detailnya") menjanjikan kolom
                // ini, tapi SEBELUMNYA tidak pernah di-eager-load ATAU
                // ditampilkan di tabel sama sekali.
                'journalEntry:id,entry_number',
            ])
            ->when(! $isFullAccess, fn ($q) => $q->whereHas('booking', fn ($q2) => $q2->where('store_id', $user?->store_id)))
            ->when($storeId, fn ($q) => $q->whereHas('booking', fn ($q2) => $q2->where('store_id', $storeId)))
            ->orderByDesc('created_at')
            ->get();

        // BUG DIPERBAIKI 2026-09-29: kolom "Metode Pembayaran" SEBELUMNYA selalu hardcode "Tunai"
        // untuk semua baris, dan deskripsi halaman menulis RefundService "SELALU" kredit Kas. Sejak
        // audit Piutang Usaha, RefundService bisa mengurangi Piutang (refunds.receivable_reduced)
        // dulu sebelum kas keluar -- jadi "Tunai" saja sudah tidak akurat. Dihitung per baris di sini
        // supaya blade tidak perlu tahu detail kolomnya, dan dipakai juga untuk breakdown total.
        $refunds->each(function (Refund $refund) {
            $receivableReduced = (float) ($refund->receivable_reduced ?? 0);
            $amount = (float) $refund->amount;
            $cashPortion = round($amount - $receivableReduced, 2);

            $refund->payment_method_label = match (true) {
                $receivableReduced <= 0 => 'Tunai',
                $cashPortion <= 0 => 'Kurangi Piutang',
                default => 'Tunai + Kurangi Piutang',
            };
            $refund->cash_portion = max($cashPortion, 0);
            $refund->receivable_portion = $receivableReduced;
        });

        return [
            'from' => $from,
            'to' => $to,
            'refunds' => $refunds,
            'totalAmount' => (float) $refunds->sum('amount'),
            'totalCount' => $refunds->count(),
            'totalCash' => (float) $refunds->sum('cash_portion'),
            'totalReceivableReduced' => (float) $refunds->sum('receivable_portion'),
        ];
    }
}
