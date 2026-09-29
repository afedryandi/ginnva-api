<?php

namespace App\Filament\Pages;

use App\Exports\CustomerReportExport;
use App\Models\Customer;
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
 * "Laporan Pelanggan" — diminta 2026-09-08, analog "Laporan Pelanggan"
 * Majoo. CustomerResource yang sudah ada cuma daftar akun mentah, tidak
 * ada analitik (repeat vs baru, total belanja). Halaman ini mengagregasi
 * data yang SUDAH ADA (tidak ada tabel/kolom baru).
 *
 * DIBATASI ke pelanggan yang PUNYA AKUN (Customer::bookings(), via
 * customer_id) — booking walk-in tanpa akun (cuma customer_name string)
 * TIDAK bisa diandalkan untuk deteksi "pelanggan yang sama" (nama bisa
 * beda ketik antar kunjungan), jadi sengaja tidak dipaksa masuk supaya
 * tidak menyesatkan (under-count "repeat" itu lebih aman daripada
 * over-count karena salah cocokkan nama).
 *
 * SEBELUMNYA di cluster Marketing/Konten — dipindah ke PenjualanCluster
 * (diminta 2026-09-08, permintaan susulan) supaya SEMUA laporan ngumpul
 * di 1 tab Penjualan, tidak tercecer ke cluster lain.
 */
class CustomerReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    // Grup sendiri 'Laporan Pelanggan' (diubah 2026-09-09 dari 'Laporan'
    // gabungan) -- sejajar dengan grup kategori laporan lain.
    protected static ?string $navigationGroup = 'Laporan Pelanggan';

    protected static ?string $navigationLabel = 'Laporan Pelanggan';

    protected static ?string $title = 'Laporan Pelanggan';

    // 300 -- band grup 'Laporan Pelanggan' (lihat catatan sistem band di
    // ProductSalesReport.php, diperbaiki 2026-09-09).
    protected static ?int $navigationSort = 300;

    protected static string $view = 'filament.pages.customer-report';

    public ?array $data = [];

    // BUG DIPERBAIKI 2026-09-29 (ditemukan saat audit): mount() SEBELUMNYA tidak baca #[Url] sama
    // sekali -- beda dari semua laporan lain -- jadi refresh halaman/share link selalu balik ke
    // default "bulan ini", filter yang dipilih user hilang.
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

        // "Sampai" < "Dari" via URL diutak-atik manual (audit Laporan Pelanggan 2026-09-29):
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

        // "Sampai" sebelum "Dari" (audit Laporan Pelanggan 2026-09-29): sebelumnya diam-diam
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

    /** Link drill-down ke halaman detail customer. */
    public function customerUrl(int $customerId): string
    {
        return \App\Filament\Resources\CustomerResource::getUrl('view', ['record' => $customerId]);
    }

    /** Log ekspor (audit Laporan Pelanggan 2026-09-29), konsisten dengan laporan lain. */
    private function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => 'customer', 'format' => $format, 'from' => $this->data['from'] ?? null, 'to' => $this->data['to'] ?? null, 'store_id' => $this->data['store_id'] ?? null])
                ->log('Ekspor Laporan Pelanggan (' . $format . ')');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * "Ekspor Laporan" -- pola sama laporan Penjualan lain (SalesSummaryReport/
     * VoidReport).
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
                        new CustomerReportExport($this->getResult()),
                        'laporan-pelanggan-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $this->logExport('pdf');

                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.customer_report', ['result' => $result])->setPaper('a4', 'portrait');
                    $filename = 'laporan-pelanggan-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();

        // "Pelanggan Baru Daftar" & "Pelanggan Repeat" SENGAJA TETAP
        // company-wide (audit 2026-09-11): akun Customer tidak punya
        // store_id sama sekali (didaftarkan lewat mobile app, bukan 1
        // outlet), dan "repeat" cuma headcount (bukan rincian Rp),
        // konsepnya memang lintas-cabang (pelanggan bisa pindah toko).
        $newCustomers = Customer::whereBetween('created_at', [$from, $to])->count();

        // BUG DIPERBAIKI 2026-09-11 (ditemukan saat audit): "Top
        // Pelanggan" SEBELUMNYA tidak di-scope toko sama sekali —
        // manajer toko manapun lihat peringkat belanja yang dipengaruhi
        // transaksi pelanggan di TOKO LAIN juga. Sekarang di-scope: full-
        // access tetap company-wide, staff toko cuma lihat booking di
        // tokonya sendiri (jadi "Top Pelanggan DI TOKO INI", bukan
        // lintas-cabang).
        $user = auth()->user();
        $isFullAccess = $user?->isFullAccess() ?? false;
        // GAP DIPERBAIKI 2026-09-29: full-access sebelumnya tidak bisa mempersempit ke 1 cabang --
        // sekarang filter 'store_id' di form dipakai kalau full-access memilihnya.
        $storeId = $isFullAccess ? ($this->data['store_id'] ?? null) : $user?->store_id;

        // "Repeat" = pelanggan yang punya >1 booking BERBAYAR (bukan
        // sekadar >1 pengajuan booking apa pun) SEPANJANG WAKTU (bukan
        // dibatasi rentang tanggal filter — status "repeat customer"
        // itu sifat kumulatif, bukan per-periode), dihitung lewat
        // whereHas('journalEntry') sama filter dengan Laporan Jasa/
        // SalesResource.
        $topCustomers = Customer::query()
            ->withCount(['bookings as bookings_in_period' => fn ($q) => $q
                ->whereHas('journalEntry', fn ($q2) => $q2->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]))
                ->where('transaction_amount', '>', 0)
                ->when($storeId, fn ($q2) => $q2->where('store_id', $storeId))])
            ->withSum(['bookings as spend_in_period' => fn ($q) => $q
                ->whereHas('journalEntry', fn ($q2) => $q2->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]))
                ->where('transaction_amount', '>', 0)
                ->when($storeId, fn ($q2) => $q2->where('store_id', $storeId))], 'transaction_amount')
            ->withCount(['bookings as bookings_all_time' => fn ($q) => $q
                ->whereHas('journalEntry')
                ->where('transaction_amount', '>', 0)
                ->when($storeId, fn ($q2) => $q2->where('store_id', $storeId))])
            // Ditambahkan 2026-09-09 (analog Laporan Pelanggan Majoo):
            // total belanja SEPANJANG WAKTU (bukan cuma periode filter)
            // + kunjungan terakhir, dasar hitung rata-rata/bulan.
            ->withSum(['bookings as spend_all_time' => fn ($q) => $q
                ->whereHas('journalEntry')
                ->where('transaction_amount', '>', 0)
                ->when($storeId, fn ($q2) => $q2->where('store_id', $storeId))], 'transaction_amount')
            ->withMax(['bookings as last_visit' => fn ($q) => $q
                ->whereHas('journalEntry')
                ->where('transaction_amount', '>', 0)
                ->when($storeId, fn ($q2) => $q2->where('store_id', $storeId))], 'preferred_date')
            ->having('bookings_in_period', '>', 0)
            ->orderByDesc('spend_in_period')
            ->limit(20)
            ->get()
            ->map(function (Customer $customer) {
                // Rata-rata/bulan dihitung dari umur akun (created_at
                // sampai sekarang), MINIMAL 1 bulan supaya akun yang
                // baru terdaftar hari ini tidak dibagi 0/pecahan bulan
                // kecil yang bikin rata-rata meledak tinggi tidak wajar.
                $monthsActive = max(1, $customer->created_at->diffInMonths(now()));
                $customer->avg_bookings_per_month = round($customer->bookings_all_time / $monthsActive, 1);
                $customer->avg_spend_per_month = $customer->spend_all_time / $monthsActive;

                return $customer;
            });

        // ->get()->count() (bukan ->count() langsung) — count() Query
        // Builder tidak selalu aman dikombinasikan dengan having() di atas
        // kolom hasil withCount() (bukan kolom GROUP BY sungguhan), jadi
        // ambil koleksinya dulu baru dihitung supaya query yang benar-
        // benar dieksekusi PERSIS sama dengan yang dipakai $topCustomers.
        $repeatCount = Customer::query()
            ->withCount(['bookings as bookings_all_time' => fn ($q) => $q
                ->whereHas('journalEntry')
                ->where('transaction_amount', '>', 0)])
            ->having('bookings_all_time', '>', 1)
            ->get()
            ->count();

        return [
            // 'from'/'to' ditambahkan untuk header periode di file
            // Export/PDF (sama pola dengan laporan Keuangan) -- halaman
            // web sendiri tidak menampilkan rentang tanggal literal di
            // luar form, tapi file export perlu 1 array self-contained.
            'from' => $from,
            'to' => $to,
            'newCustomers' => $newCustomers,
            'repeatCount' => $repeatCount,
            'topCustomers' => $topCustomers,
        ];
    }
}
