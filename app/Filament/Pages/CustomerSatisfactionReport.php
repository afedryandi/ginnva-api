<?php

namespace App\Filament\Pages;

use App\Exports\CustomerSatisfactionReportExport;
use App\Models\Store;
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
 * "Kepuasan Pelanggan" — diminta 2026-09-09, analog Majoo. Data
 * StoreReview (sentiment/tags/comment per booking) SUDAH ADA tapi
 * belum pernah diagregasi jadi laporan -- cuma resource daftar mentah.
 * sentiment enum: positive/neutral/negative (lihat migrasi).
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
                ->label('Cabang')
                ->placeholder('Semua cabang')
                ->options(fn () => Store::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->visible($isFullAccess)
                ->live(),
        ])->columns($isFullAccess ? 4 : 3)->statePath('data');
    }

    /** Link drill-down ke halaman detail ulasan (tombol "Tandai Ditindaklanjuti" ada di sana). */
    public function reviewUrl(int $id): string
    {
        return \App\Filament\Resources\StoreReviewResource::getUrl('view', ['record' => $id]);
    }

    /** Log ekspor (audit 2026-09-30), konsisten dengan laporan lain. */
    private function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => 'customer_satisfaction', 'format' => $format, 'from' => $this->from, 'to' => $this->to, 'store_id' => $this->storeId])
                ->log('Ekspor Kepuasan Pelanggan (' . $format . ')');
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
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();
        $user = auth()->user();
        $isFullAccess = $user?->isFullAccess() ?? false;
        $storeId = $isFullAccess ? $this->storeId : $user?->store_id;

        $reviews = StoreReview::query()
            ->with(['store:id,name', 'customer:id,name'])
            ->whereBetween('created_at', [$from, $to])
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->orderByDesc('created_at')
            ->get();

        $total = $reviews->count();
        $positive = $reviews->where('sentiment', 'positive')->count();
        $neutral = $reviews->where('sentiment', 'neutral')->count();
        $negative = $reviews->where('sentiment', 'negative')->count();

        // Tag paling sering muncul -- `tags` disimpan sebagai array
        // (json cast), gabungkan semua lalu hitung frekuensi.
        $tagCounts = $reviews->flatMap(fn (StoreReview $r) => $r->tags ?? [])
            ->countBy()
            ->sortDesc()
            ->take(10);

        // Per toko -- cuma relevan untuk isFullAccess (store-scoped
        // user cuma lihat 1 toko sendiri, tabel ini jadi trivial baginya).
        $byStore = $reviews->groupBy(fn (StoreReview $r) => $r->store?->name ?? 'Tanpa Toko')
            ->map(fn ($group) => [
                'total' => $group->count(),
                'positive' => $group->where('sentiment', 'positive')->count(),
                'negative' => $group->where('sentiment', 'negative')->count(),
            ])
            ->sortByDesc('total');

        return [
            'from' => $from,
            'to' => $to,
            'reviews' => $reviews,
            // Daftar kartu ulasan di layar DIBATASI 100 terbaru (audit 2026-09-30) --
            // sebelumnya blade me-render SEMUA baris sekaligus, bisa berat kalau toko
            // aktif punya ratusan ulasan sebulan. Export Excel/PDF ('reviews') TETAP
            // lengkap, cuma tampilan layar yang dipotong.
            'displayReviews' => $reviews->take(100),
            'displayReviewsTruncated' => $reviews->count() > 100,
            'total' => $total,
            'positive' => $positive,
            'neutral' => $neutral,
            'negative' => $negative,
            'positiveRate' => $total > 0 ? $positive / $total * 100 : 0,
            'tagCounts' => $tagCounts,
            'byStore' => $byStore,
            'unfollowedNegativeCount' => $reviews->where('sentiment', 'negative')->whereNull('followed_up_at')->count(),
        ];
    }
}
