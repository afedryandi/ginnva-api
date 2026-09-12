<?php

namespace App\Filament\Pages;

use App\Exports\CustomerSatisfactionReportExport;
use App\Models\StoreReview;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
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

        $this->form->fill([
            'from' => $this->from,
            'to' => $this->to,
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
            default => null,
        };
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),
        ])->columns(2)->statePath('data');
    }

    /**
     * "Ekspor Laporan" (audit 2026-09-12, temuan B) — pola sama laporan
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
                    new CustomerSatisfactionReportExport($this->getResult()),
                    'kepuasan-pelanggan-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
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

        $reviews = StoreReview::query()
            ->with(['store:id,name', 'customer:id,name'])
            ->whereBetween('created_at', [$from, $to])
            ->when(! $isFullAccess, fn ($q) => $q->where('store_id', $user?->store_id))
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
