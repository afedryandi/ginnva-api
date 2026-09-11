<?php

namespace App\Filament\Pages;

use App\Exports\PointReportExport;
use App\Models\Booking;
use App\Models\PartnerPointTransaction;
use App\Models\PointTransaction;
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
 * "Laporan Poin" — diminta 2026-09-09. SEMPAT extends PromoLoyaltyReport
 * (warisan konten Voucher/Reward gabungan), TAPI setelah user tunjukkan
 * screenshot Laporan Poin Majoo yang SEBENARNYA (rincian harian: Poin
 * Didapat/Ditukar/Dibatalkan + nilai Rp per transaksi), ternyata beda
 * cukup jauh dari PromoLoyaltyReport -- dibuat ULANG jadi standalone
 * (bukan extends lagi) dengan data poin sungguhan.
 *
 * KETERBATASAN YANG DIKONFIRMASI dari struktur data asli (bukan tebakan):
 * - `PointTransaction.type` cuma enum 'earn'/'spend' -- TIDAK ADA tipe
 *   'batal'/'cancel' sama sekali, jadi "Poin Dibatalkan" Majoo TIDAK
 *   BISA ditampilkan (bukan Rp 0/0, memang tidak ada mekanismenya).
 * - PointTransaction TIDAK punya kolom nilai Rupiah langsung -- "Poin
 *   Didapat (Rp)" DITURUNKAN dari transaction_amount booking yang jadi
 *   referensinya (reference_type='booking'), CUMA valid untuk baris
 *   yang referensinya booking (reference_type lain seperti 'warranty'
 *   tidak punya nilai Rp yang relevan, ditandai '-').
 * - "Nilai Tukar (Rp)" (redemption reward) TIDAK BISA dihitung -- Reward
 *   cuma punya points_cost, tidak ada nilai Rupiah tersimpan (sama
 *   limitasi yang sudah didokumentasikan di SalesSummaryReport soal
 *   "Reward Poin (nilai Rp)").
 */
class PointReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?string $navigationGroup = 'Laporan Promo & Loyalti';

    protected static ?string $navigationLabel = 'Laporan Poin';

    protected static ?string $title = 'Laporan Poin';

    // 201 -- band grup 'Laporan Promo & Loyalti' (lihat catatan sistem
    // band di ProductSalesReport.php).
    protected static ?int $navigationSort = 201;

    protected static string $view = 'filament.pages.point-report';

    public ?array $data = [];

    // #[Url] (audit 2026-09-11, temuan D) — pola sama laporan Penjualan
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
                    new PointReportExport($this->getResult()),
                    'laporan-poin-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.point_report', ['result' => $result])->setPaper('a4', 'landscape');
                    $filename = 'laporan-poin-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();

        $customerTx = PointTransaction::query()
            ->whereBetween('created_at', [$from, $to])
            ->get(['type', 'points', 'reference_type', 'reference_id', 'created_at']);

        $partnerTx = PartnerPointTransaction::query()
            ->whereBetween('created_at', [$from, $to])
            ->get(['type', 'points', 'reference_type', 'reference_id', 'created_at']);

        // Nilai Rp "Poin Didapat" -- CUMA valid untuk earn yang
        // reference_type='booking', diambil dari transaction_amount
        // booking itu (nilai transaksi yang memicu poin, BUKAN nilai
        // poinnya sendiri -- poin di Ginnva tidak dikonversi ke Rp).
        $bookingIds = $customerTx->merge($partnerTx)
            ->where('type', 'earn')
            ->where('reference_type', 'booking')
            ->pluck('reference_id')
            ->unique();
        $bookingAmounts = Booking::whereIn('id', $bookingIds)->pluck('transaction_amount', 'id');

        $rows = [];
        $cursor = $from->copy()->startOfDay();
        while ($cursor->lte($to)) {
            $rows[$cursor->toDateString()] = [
                'label' => $cursor->format('d M Y'),
                'earned' => 0, 'earnCount' => 0, 'earnRp' => 0.0,
                'spent' => 0, 'spentCount' => 0,
            ];
            $cursor->addDay();
        }

        foreach ($customerTx->merge($partnerTx) as $tx) {
            $key = $tx->created_at->toDateString();
            if (! isset($rows[$key])) continue;

            if ($tx->type === 'earn') {
                $rows[$key]['earned'] += $tx->points;
                $rows[$key]['earnCount']++;
                if ($tx->reference_type === 'booking') {
                    $rows[$key]['earnRp'] += (float) ($bookingAmounts[$tx->reference_id] ?? 0);
                }
            } elseif ($tx->type === 'spend') {
                $rows[$key]['spent'] += $tx->points;
                $rows[$key]['spentCount']++;
            }
        }

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'totalEarned' => array_sum(array_column($rows, 'earned')),
            'totalSpent' => array_sum(array_column($rows, 'spent')),
        ];
    }
}
