<?php

namespace App\Filament\Pages;

use App\Exports\RefundReportExport;
use App\Models\Booking;
use App\Models\BookingDownPayment;
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
 * "Laporan Refund" -- daftar uang yang dikembalikan ke pelanggan dalam 1 rentang tanggal (menurut hari diproses).
 *
 * Dua jenis baris: "Refund Penjualan" (tabel refunds, lewat aksi Proses Refund -- parsial boleh, berkali-kali, wajib punya alasan dan
 * metode pengembalian, dan mencatat penyetujunya bila diajukan staf) dan "Refund DP" (uang muka yang dikembalikan saat booking dibatalkan
 * atau sisa DP yang tidak terpakai). Dengan begitu total uang keluar ke pelanggan utuh.
 *
 * Penjualan Ringkasan/Per Periode/Outlet memakai tabel refunds yang SAMA untuk "Pengembalian"; refund DP bukan pengurang penjualan
 * (DP bukan pendapatan), jadi hanya muncul di sini.
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
            && $user->hasExplicitMenuAccess(static::class);
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
                ->label('Toko')
                ->placeholder('Semua toko')
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

    /**
     * Toko yang BENAR-BENAR berlaku: full-access memilih (null = semua cabang), staf toko dikunci ke tokonya,
     * dan staf tanpa toko dikunci ke -1 (tidak cocok toko mana pun) -- bukan null yang berarti semua cabang.
     * Dipakai getResult() dan log ekspor supaya keduanya merujuk toko yang sama.
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

    /** Log ekspor (audit Laporan Refund 2026-09-29), konsisten dengan laporan lain. */
    private function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => 'refund', 'format' => $format, 'from' => $this->data['from'] ?? null, 'to' => $this->data['to'] ?? null, 'store_id' => $this->effectiveStoreId()])
                ->log('Export Laporan Refund (' . $format . ')');
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
        // startOfDay(): nilai DatePicker bisa membawa jam; tanpa ini refund sebelum jam itu di hari pertama tidak terhitung.
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();
        $storeId = $this->effectiveStoreId();
        $finance = (bool) config('features.finance');

        $refunds = Refund::query()
            ->whereBetween('created_at', [$from, $to])
            ->with([
                'booking:id,booking_number,customer_id,customer_name,store_id,transaction_amount',
                'booking.store:id,name',
                'booking.customer',
                'creator:id,name',
                'approver:id,name',
                'journalEntry:id,entry_number',
            ])
            ->when($storeId, fn ($q) => $q->whereHas('booking', fn ($q2) => $q2->where('store_id', $storeId)))
            ->get();

        // Sisa nilai transaksi SETELAH tiap refund = nilai transaksi dikurangi refund booking itu sampai (dan termasuk) refund ini.
        $history = Refund::query()
            ->whereIn('booking_id', $refunds->pluck('booking_id')->unique()->all())
            ->orderBy('id')
            ->get(['id', 'booking_id', 'amount'])
            ->groupBy('booking_id');

        $rows = $refunds->map(function (Refund $refund) use ($history, $finance) {
            $receivable = (float) ($refund->receivable_reduced ?? 0);
            $amount = (float) $refund->amount;
            $cash = max(round($amount - $receivable, 2), 0);
            $methodName = $refund->method ? (Booking::PAYMENT_METHOD_LABELS[$refund->method] ?? $refund->method) : null;

            // Refund lama (tanpa metode): dengan Keuangan menyala dianggap Tunai seperti dulu; saat mati metodenya memang tidak tercatat.
            $label = match (true) {
                $receivable <= 0 => $methodName ?? ($finance ? 'Tunai' : '—'),
                $cash <= 0 => 'Kurangi Piutang',
                default => ($methodName ?? 'Tunai') . ' + Kurangi Piutang',
            };

            $remaining = $refund->booking
                ? round((float) $refund->booking->transaction_amount - (float) $history->get($refund->booking_id, collect())->where('id', '<=', $refund->id)->sum('amount'), 2)
                : null;

            return [
                'id' => $refund->id,
                'kind' => 'Refund Penjualan',
                'number' => $refund->refund_number,
                'date' => $refund->created_at,
                'booking' => $refund->booking,
                'method_label' => $label,
                'by' => $refund->creator?->name,
                'approved_by' => $refund->approver?->name,
                'journal' => $refund->journalEntry?->entry_number,
                'reason' => $refund->reason,
                'amount' => $amount,
                'cash' => $cash,
                'receivable' => $receivable,
                'remaining' => $remaining,
            ];
        });

        // Pengembalian DP (otomatis saat booking dibatalkan, atau sisa DP yang tidak terpakai) juga uang keluar ke pelanggan.
        // Tanggalnya refunded_at; sisa DP sebagian (DP sudah terpakai sebagian) tidak punya refunded_at, jadi memakai updated_at.
        $dpRows = BookingDownPayment::query()
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('refunded_at', [$from, $to])
                    ->orWhere(fn ($q2) => $q2->whereNull('refunded_at')->where('refunded_amount', '>', 0)->whereBetween('updated_at', [$from, $to]));
            })
            ->when($storeId, fn ($q) => $q->whereHas('booking', fn ($q2) => $q2->where('store_id', $storeId)))
            ->with(['booking:id,booking_number,customer_id,customer_name,store_id,status', 'booking.store:id,name', 'booking.customer', 'refundJournalEntry:id,entry_number'])
            ->get()
            ->map(function (BookingDownPayment $dp) use ($finance) {
                $amount = $dp->refunded_at
                    ? ((float) $dp->refunded_amount > 0 ? (float) $dp->refunded_amount : (float) $dp->amount)
                    : (float) $dp->refunded_amount;

                return [
                    'id' => $dp->id,
                    'kind' => 'Refund DP',
                    'number' => null,
                    'date' => $dp->refunded_at ?? $dp->updated_at,
                    'booking' => $dp->booking,
                    'method_label' => $dp->method ? (Booking::PAYMENT_METHOD_LABELS[$dp->method] ?? $dp->method) : '—',
                    'by' => null,
                    'approved_by' => null,
                    'journal' => $finance ? $dp->refundJournalEntry?->entry_number : null,
                    'reason' => $dp->booking?->status === 'cancelled' ? 'Booking dibatalkan' : 'Sisa DP tidak terpakai',
                    'amount' => $amount,
                    'cash' => $amount,
                    'receivable' => 0.0,
                    'remaining' => null,
                ];
            });

        $all = $rows->concat($dpRows)->sortByDesc(fn (array $r) => $r['date']->getTimestamp())->values();

        $byReason = $all->groupBy(fn (array $r) => $r['reason'] ?: 'Tanpa alasan')->map(fn ($g) => $g->count())->sortDesc();
        if ($byReason->count() > 8) {
            $others = $byReason->slice(8)->sum();
            $byReason = $byReason->take(8)->put('Lainnya', $others);
        }

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $all,
            'totalAmount' => (float) $all->sum('amount'),
            'totalCount' => $all->count(),
            'salesAmount' => (float) $rows->sum('amount'),
            'salesCount' => $rows->count(),
            'dpAmount' => (float) $dpRows->sum('amount'),
            'dpCount' => $dpRows->count(),
            'totalCash' => (float) $all->sum('cash'),
            'totalReceivableReduced' => (float) $all->sum('receivable'),
            'byReason' => $byReason,
            'byStore' => $all->groupBy(fn (array $r) => $r['booking']?->store?->name ?? '—')->map(fn ($g) => (float) $g->sum('amount'))->sortDesc(),
        ];
    }
}
