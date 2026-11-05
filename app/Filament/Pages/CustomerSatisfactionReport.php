<?php

namespace App\Filament\Pages;

use App\Exports\CustomerSatisfactionReportExport;
use App\Models\Store;
use App\Models\Booking;
use App\Models\StoreReview;
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
 * "Kepuasan Pelanggan" -- agregasi ulasan toko (StoreReview: sentimen positif/netral/negatif, tag aspek, komentar; satu ulasan per booking
 * selesai, diisi pelanggan lewat aplikasi) dalam satu rentang tanggal ulasan masuk.
 *
 * Ukuran: tingkat positif, skor bersih ((positif - negatif) / total), tingkat respons (ulasan / booking selesai pada rentang), ulasan
 * negatif yang belum ditindaklanjuti dan rata-rata lama tindak lanjutnya, tag dipisah menurut nada, tren (mingguan atau bulanan), per toko.
 * Tindak lanjut dilakukan di menu Review Toko (panel); aplikasi karyawan belum punya layar ulasan.
 */
class CustomerSatisfactionReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-face-smile';

    protected static ?string $cluster = \App\Filament\Clusters\AnalisaLaporanCluster::class;

    protected static ?string $navigationLabel = 'Kepuasan Pelanggan';

    protected static ?string $title = 'Kepuasan Pelanggan';

    // 603 -- band grup 'Analisa Laporan' (lihat catatan sistem band di
    // ProductSalesReport.php).
    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.customer-satisfaction-report';

    public ?array $data = [];

    // #[Url] (audit 2026-09-12, temuan D) — pola sama laporan Penjualan
    // lain.
    #[Url(as: 'from')]
    public ?string $from = null;

    #[Url(as: 'to')]
    public ?string $to = null;

    // Filter toko (audit 2026-09-30) -- sejajar laporan Penjualan lain.
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

        // "Sampai" < "Dari" via URL diutak-atik manual (audit Kepuasan Pelanggan
        // 2026-09-30): dikoreksi diam-diam di sini, sama pola dengan laporan Penjualan lain.
        if (Carbon::parse($this->to)->lt(Carbon::parse($this->from))) {
            $this->to = $this->from;
        }

        if (! (auth()->user()?->isFullAccess() ?? false)) {
            $this->storeId = auth()->user()?->store_id;
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

        // "Sampai" sebelum "Dari" (audit Kepuasan Pelanggan 2026-09-30): sebelumnya diam-diam
        // menghasilkan tabel kosong tanpa penjelasan -- dikoreksi + diberi tahu, sama pola dengan
        // laporan Penjualan lain.
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
            // Periode Cepat (audit 2026-09-30, sejajar laporan Penjualan lain).
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

            // Filter toko (audit 2026-09-30) -- full-access sebelumnya cuma bisa lihat
            // breakdown "Per Toko", tidak bisa mempersempit daftar ulasan ke 1 toko saja.
            Select::make('store_id')
                ->label('Toko')
                ->placeholder('Semua toko')
                ->options(fn () => Store::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->visible($isFullAccess)
                ->live(),

            // Dua pilihan di bawah hanya menyaring DAFTAR ulasan; ringkasan, tag, dan tabel tetap dari seluruh ulasan pada rentang dan toko itu.
            Select::make('sentiment')
                ->label('Daftar: sentimen')
                ->options(['positive' => 'Positif', 'neutral' => 'Netral', 'negative' => 'Negatif'])
                ->placeholder('Semua sentimen')
                ->live(),

            Select::make('follow_up')
                ->label('Daftar: tindak lanjut')
                ->options(['pending' => 'Negatif yang belum ditindaklanjuti', 'done' => 'Sudah ditindaklanjuti'])
                ->placeholder('Semua status')
                ->live(),
        ])->columns(3)->statePath('data');
    }

    /** Link drill-down ke halaman detail ulasan (tombol "Tandai Ditindaklanjuti" ada di sana). */
    public function reviewUrl(int $id): string
    {
        return \App\Filament\Resources\StoreReviewResource::getUrl('view', ['record' => $id]);
    }

    /**
     * Toko yang BENAR-BENAR berlaku: full-access memilih (null = semua cabang), staf toko dikunci ke tokonya, dan
     * staf tanpa toko dikunci ke -1 (tidak cocok toko mana pun) -- bukan null yang berarti semua cabang.
     */
    private function effectiveStoreId(): ?int
    {
        $user = auth()->user();

        if ($user?->isFullAccess() ?? false) {
            return $this->storeId ?: null;
        }

        return $user?->store_id ?? -1;
    }

    /** Log ekspor (audit 2026-09-30), konsisten dengan laporan lain. */
    private function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => 'customer_satisfaction', 'format' => $format, 'from' => $this->from, 'to' => $this->to, 'store_id' => $this->effectiveStoreId()])
                ->log('Export Kepuasan Pelanggan (' . $format . ')');
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
                        new CustomerSatisfactionReportExport($this->getResult()),
                        'kepuasan-pelanggan-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $this->logExport('pdf');

                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.customer_satisfaction_report', ['result' => $result])->setPaper('a4', 'portrait');
                    $filename = 'kepuasan-pelanggan-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    public function getResult(): array
    {
        // startOfDay(): nilai DatePicker bisa membawa jam; tanpa ini ulasan sebelum jam itu di hari pertama tidak terhitung.
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();
        $storeId = $this->effectiveStoreId();

        $reviews = StoreReview::query()
            ->with(['store:id,name', 'customer:id,name', 'booking:id,booking_number,product_kaca_film,product_ppf,product_detailing,product_premium_wash'])
            ->whereBetween('created_at', [$from, $to])
            ->when($storeId !== null, fn ($q) => $q->where('store_id', $storeId))
            ->orderByDesc('created_at')
            ->get();

        $total = $reviews->count();
        $positive = $reviews->where('sentiment', 'positive')->count();
        $neutral = $reviews->where('sentiment', 'neutral')->count();
        $negative = $reviews->where('sentiment', 'negative')->count();

        // Tingkat respons: ulasan masuk dibanding booking yang selesai pada rentang dan toko yang sama (perkiraan: ulasan bisa untuk booking
        // yang selesai sebelum rentang). Tanpa booking selesai tidak ada angkanya.
        $completedBookings = Booking::query()
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$from, $to])
            ->when($storeId !== null, fn ($q) => $q->where('store_id', $storeId))
            ->count();

        // Lama tindak lanjut ulasan negatif (jam) dari ulasan masuk sampai ditandai ditindaklanjuti.
        $followedNegatives = $reviews->where('sentiment', 'negative')->whereNotNull('followed_up_at');
        $avgFollowUpHours = $followedNegatives->isNotEmpty()
            ? (float) $followedNegatives->avg(fn (StoreReview $r) => abs($r->created_at->diffInMinutes($r->followed_up_at)) / 60)
            : null;

        // Tag paling sering muncul -- `tags` disimpan sebagai array (json cast), gabungkan semua lalu hitung frekuensi.
        $tagCounts = $reviews->flatMap(fn (StoreReview $r) => $r->tags ?? [])
            ->countBy()
            ->map(fn ($count, $key) => ['key' => $key, 'count' => $count])
            ->sortBy([['count', 'desc'], ['key', 'asc']])   // seri diurutkan kunci supaya tampilan stabil
            ->mapWithKeys(fn ($item) => [$item['key'] => $item['count']])
            ->take(10);

        // Tag dipisah menurut nadanya: yang membuat pelanggan puas dan yang perlu diperbaiki.
        $allTagCounts = $reviews->flatMap(fn (StoreReview $r) => $r->tags ?? [])->countBy();
        $tagGroup = fn (array $keys) => collect($keys)
            ->mapWithKeys(fn ($key) => [$key => (int) ($allTagCounts[$key] ?? 0)])
            ->filter(fn ($count) => $count > 0)
            ->map(fn ($count, $key) => ['key' => $key, 'count' => $count])
            ->sortBy([['count', 'desc'], ['key', 'asc']])
            ->mapWithKeys(fn ($item) => [$item['key'] => $item['count']]);

        // Per toko -- cuma relevan untuk isFullAccess (store-scoped user cuma lihat 1 toko sendiri).
        $byStore = $reviews->groupBy(fn (StoreReview $r) => $r->store?->name ?? 'Tanpa Toko')
            ->map(function ($group) {
                $count = $group->count();
                $pos = $group->where('sentiment', 'positive')->count();
                $neg = $group->where('sentiment', 'negative')->count();

                return [
                    'total' => $count,
                    'positive' => $pos,
                    'neutral' => $group->where('sentiment', 'neutral')->count(),
                    'negative' => $neg,
                    'unfollowed' => $group->where('sentiment', 'negative')->whereNull('followed_up_at')->count(),
                    'positiveRate' => $count > 0 ? $pos / $count * 100 : 0,
                    'net' => $count > 0 ? ($pos - $neg) / $count * 100 : 0,
                ];
            })
            ->sortByDesc('total');

        // Tren: per minggu untuk rentang sampai 60 hari, per bulan bila lebih panjang.
        $monthly = $from->diffInDays($to) + 1 > 60;
        $trend = $reviews
            ->groupBy(fn (StoreReview $r) => $monthly ? $r->created_at->format('Y-m') : $r->created_at->copy()->startOfWeek()->toDateString())
            ->sortKeys()
            ->map(function ($group, $key) use ($monthly) {
                $count = $group->count();
                $pos = $group->where('sentiment', 'positive')->count();
                $neg = $group->where('sentiment', 'negative')->count();

                return [
                    'label' => $monthly ? Carbon::parse($key . '-01')->format('M Y') : 'Minggu ' . Carbon::parse($key)->format('d M'),
                    'total' => $count,
                    'positive' => $pos,
                    'negative' => $neg,
                    'net' => $count > 0 ? ($pos - $neg) / $count * 100 : 0,
                ];
            })
            ->values();

        // Daftar kartu ulasan di layar: dapat disaring (sentimen, tindak lanjut) dan DIBATASI 100 terbaru -- sebelumnya blade me-render
        // SEMUA baris sekaligus. Export Excel/PDF ('reviews') TETAP lengkap dan tidak mengikuti saringan daftar.
        $sentiment = $this->data['sentiment'] ?? null;
        $followUp = $this->data['follow_up'] ?? null;
        $list = $reviews
            ->when($sentiment, fn ($c) => $c->where('sentiment', $sentiment))
            ->when($followUp === 'pending', fn ($c) => $c->where('sentiment', 'negative')->whereNull('followed_up_at'))
            ->when($followUp === 'done', fn ($c) => $c->whereNotNull('followed_up_at'))
            ->values();

        return [
            'from' => $from,
            'to' => $to,
            'reviews' => $reviews,
            'displayReviews' => $list->take(100),
            'displayReviewsTruncated' => $list->count() > 100,
            'listTotal' => $list->count(),
            'total' => $total,
            'positive' => $positive,
            'neutral' => $neutral,
            'negative' => $negative,
            'positiveRate' => $total > 0 ? $positive / $total * 100 : 0,
            // Skor bersih -100..100: (positif - negatif) / total. Di atas 0 = lebih banyak yang puas daripada yang tidak.
            'netScore' => $total > 0 ? ($positive - $negative) / $total * 100 : 0,
            'completedBookings' => $completedBookings,
            'responseRate' => $completedBookings > 0 ? min(100, $total / $completedBookings * 100) : null,
            'avgFollowUpHours' => $avgFollowUpHours,
            'tagCounts' => $tagCounts,
            'tagGroups' => ['positive' => $tagGroup(StoreReview::POSITIVE_TAGS), 'negative' => $tagGroup(StoreReview::NEGATIVE_TAGS)],
            'byStore' => $byStore,
            'trend' => $trend,
            'trendMonthly' => $monthly,
            'unfollowedNegativeCount' => $reviews->where('sentiment', 'negative')->whereNull('followed_up_at')->count(),
        ];
    }
}
