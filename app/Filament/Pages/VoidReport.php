<?php

namespace App\Filament\Pages;

use App\Exports\VoidReportExport;
use App\Models\Booking;
use App\Models\BookingDownPayment;
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
use Spatie\Activitylog\Models\Activity;

/**
 * "Laporan Pembatalan" (kelas tetap VoidReport supaya kunci izin Hak Akses tidak berubah) -- rekap booking yang DIBATALKAN dalam
 * 1 rentang tanggal, termasuk yang ditandai pelanggan tidak datang (no-show).
 *
 * Sumber: kolom Booking (cancelled_at, cancel_reason, cancelled_by_type, no_show_at; sejak 2026-10-05), sehingga laporan tidak
 * bergantung pada riwayat aktivitas yang bisa dibersihkan. Booking lama yang dibatalkan SEBELUM kolom itu ada (cancelled_at kosong)
 * ditemukan lewat log perubahan status ke 'cancelled' dan "dibatalkan oleh"-nya memakai pelaku log.
 *
 * "Nilai transaksi" memakai transaction_amount booking SAAT INI; booking yang batal sebelum nilai pernah diisi bernilai 0.
 * "DP belum dikembalikan" = uang muka booking batal yang masih tercatat di tangan toko. Pembatalan mengembalikan semua DP otomatis,
 * jadi angka > 0 berarti ada yang tersangkut dan perlu dicek.
 *
 * Scoping toko di SQL (kolom store_id booking, atau whereHasMorph untuk jalur log), bukan di PHP.
 */
class VoidReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-x-circle';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?string $navigationGroup = 'Laporan Penjualan';

    protected static ?string $navigationLabel = 'Laporan Pembatalan';

    protected static ?string $title = 'Laporan Pembatalan';

    protected static ?int $navigationSort = 8;

    protected static string $view = 'filament.pages.void-report';

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

        // "Sampai" < "Dari" via URL diutak-atik manual (audit Laporan Void 2026-09-29): dikoreksi
        // diam-diam di sini (kunjungan awal, belum ada UI untuk menampilkan notifikasi).
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

        // "Sampai" sebelum "Dari" (audit Laporan Void 2026-09-29): sebelumnya diam-diam
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

    /** Link drill-down ke halaman detail booking yang dibatalkan. */
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

    /** Log ekspor, konsisten dengan laporan lain. */
    private function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => 'void', 'format' => $format, 'from' => $this->data['from'] ?? null, 'to' => $this->data['to'] ?? null, 'store_id' => $this->effectiveStoreId()])
                ->log('Export Laporan Pembatalan (' . $format . ')');
        } catch (\Throwable $e) {
            report($e);
        }
    }

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
                        new VoidReportExport($this->getResult()),
                        'laporan-pembatalan-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $this->logExport('pdf');

                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.void_report', ['result' => $result])->setPaper('a4', 'landscape');
                    $filename = 'laporan-pembatalan-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    public function getResult(): array
    {
        // startOfDay(): nilai DatePicker bisa membawa jam; tanpa ini pembatalan sebelum jam itu di hari pertama tidak terhitung.
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();
        $storeId = $this->effectiveStoreId();

        $current = Booking::query()
            ->where('status', 'cancelled')
            ->whereNotNull('cancelled_at')
            ->whereBetween('cancelled_at', [$from, $to])
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->with(['store:id,name', 'customer'])
            ->get()
            ->map(fn (Booking $b) => ['booking' => $b, 'cancelled_at' => $b->cancelled_at, 'fallback' => null]);

        // Booking lama (sebelum kolom cancelled_at ada): waktu & pelaku dari log perubahan status ke 'cancelled'.
        $legacy = Activity::query()
            ->where('subject_type', Booking::class)
            // logOnlyDirty: 'status' di properties->attributes PASTI perubahan status, dan nilai barunya 'cancelled'.
            ->where('properties->attributes->status', 'cancelled')
            ->whereBetween('created_at', [$from, $to])
            ->when($storeId, fn ($q) => $q->whereHasMorph('subject', [Booking::class], fn ($q2) => $q2->where('store_id', $storeId)))
            ->with(['causer', 'subject' => fn ($q) => $q->with(['store:id,name', 'customer'])])
            ->get()
            ->filter(fn (Activity $a) => $a->subject !== null && $a->subject->status === 'cancelled' && $a->subject->cancelled_at === null)
            ->unique('subject_id')
            ->map(fn (Activity $a) => ['booking' => $a->subject, 'cancelled_at' => $a->created_at, 'fallback' => $a->causer?->name]);

        $merged = $current->concat($legacy);

        $openDp = BookingDownPayment::query()
            ->whereIn('booking_id', $merged->map(fn (array $r) => $r['booking']->id)->all())
            ->whereNull('refunded_at')
            ->selectRaw('booking_id, SUM(amount - refunded_amount) as total')
            ->groupBy('booking_id')
            ->pluck('total', 'booking_id');

        $rows = $merged
            ->map(function (array $r) use ($openDp) {
                /** @var Booking $booking */
                $booking = $r['booking'];

                return [
                    'booking' => $booking,
                    'cancelled_at' => $r['cancelled_at'],
                    'kind' => $booking->no_show_at !== null ? 'Tidak datang' : 'Dibatalkan',
                    'by' => $booking->cancelledByLabel($r['fallback']),
                    'actor' => match ($booking->cancelled_by_type) {
                        'customer' => 'Pelanggan',
                        'staff' => 'Staf',
                        'system' => 'Sistem (otomatis)',
                        default => 'Tidak tercatat (data lama)',
                    },
                    'reason' => $booking->cancel_reason,
                    'amount' => (float) ($booking->transaction_amount ?? 0),
                    'dp_open' => (float) ($openDp[$booking->id] ?? 0),
                ];
            })
            ->sortByDesc(fn (array $r) => $r['cancelled_at']->getTimestamp())
            ->values();

        $reasons = $rows->groupBy(fn (array $r) => $r['reason'] ?: 'Tanpa alasan')->map->count()->sortDesc();
        if ($reasons->count() > 8) {
            $others = $reasons->slice(8)->sum();
            $reasons = $reasons->take(8)->put('Lainnya', $others);
        }

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'totalCount' => $rows->count(),
            'noShowCount' => $rows->where('kind', 'Tidak datang')->count(),
            'totalLostRevenue' => $rows->sum('amount'),
            'openDpTotal' => $rows->sum('dp_open'),
            'openDpCount' => $rows->where('dp_open', '>', 0)->count(),
            'byReason' => $reasons,
            'byActor' => $rows->groupBy('actor')->map->count()->sortDesc(),
        ];
    }
}
