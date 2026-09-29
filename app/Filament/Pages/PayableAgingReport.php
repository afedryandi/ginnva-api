<?php

namespace App\Filament\Pages;

use App\Exports\PayableAgingExport;
use App\Filament\Resources\PayableResource;
use App\Models\Store;
use App\Services\FinancialStatementService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Umur Hutang (Aging Payable) per supplier: sisa tagihan aktif dikelompokkan
 * menurut keterlambatan dari jatuh tempo (audit Hutang Usaha 2026-09-29).
 * Non-full-access otomatis dibatasi ke toko sendiri; full-access bisa memilih toko
 * atau "Pusat / Tanpa Toko" (audit Umur Hutang 2026-09-29, sama pola laporan Keuangan lain).
 */
class PayableAgingReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- band 100-199 "Piutang & Utang".
    protected static ?string $navigationGroup = 'Piutang & Utang';

    protected static ?string $navigationLabel = 'Umur Hutang';

    protected static ?string $title = 'Umur Hutang (Aging)';

    protected static ?int $navigationSort = 103;

    protected static string $view = 'filament.pages.payable-aging';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea() && $user->hasMenuAccess(PayableResource::class);
    }

    private function isRestricted(): bool
    {
        return ! (auth()->user()?->isFullAccess() ?? false);
    }

    public function mount(): void
    {
        $this->form->fill([
            'store_id' => $this->isRestricted() ? auth()->user()?->store_id : null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('store_id')
                    ->label('Toko')
                    ->options(fn () => [FinancialStatementService::COMPANY_WIDE => 'Pusat / Tanpa Toko'] + Store::pluck('name', 'id')->all())
                    ->placeholder('Semua Toko')
                    ->searchable()
                    ->live()
                    ->disabled(fn () => $this->isRestricted() && auth()->user()?->store_id !== null),
            ])
            ->statePath('data')
            ->columns(3);
    }

    private function storeId(): ?int
    {
        $user = auth()->user();

        if ($this->isRestricted() && $user?->store_id !== null) {
            return (int) $user->store_id;
        }

        return isset($this->data['store_id']) && $this->data['store_id'] !== '' ? (int) $this->data['store_id'] : null;
    }

    public function storeLabel(): string
    {
        $id = $this->storeId();

        return match (true) {
            $id === null => 'Semua Toko',
            $id === FinancialStatementService::COMPANY_WIDE => 'Pusat / Tanpa Toko',
            default => Store::whereKey($id)->value('name') ?? 'Toko #' . $id,
        };
    }

    private function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => 'payable_aging', 'format' => $format, 'store_id' => $this->storeId()])
                ->log('Ekspor Umur Hutang (' . $format . ')');
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

                    return Excel::download(new PayableAgingExport($this->getAging(), $this->storeLabel()), 'umur-hutang-' . now()->format('Ymd-His') . '.xlsx');
                }),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $this->logExport('pdf');

                    $pdf = Pdf::loadView('pdf.payable_aging_report', ['aging' => $this->getAging(), 'storeLabel' => $this->storeLabel()])->setPaper('a4', 'landscape');

                    return response()->streamDownload(fn () => print($pdf->output()), 'umur-hutang-' . now()->format('Ymd-His') . '.pdf');
                }),
        ];
    }

    /**
     * @return array{rows: \Illuminate\Support\Collection, totals: array<string, float>, supplier_count: int, overdue_count: int, over_90_pct: float}
     */
    public function getAging(): array
    {
        // Dikelompokkan per SUPPLIER MASTER (supplier_id), bukan teks supplier_name -- sebelumnya
        // supplier yang sama bisa terpecah jadi beberapa baris kalau ejaan/kapitalisasi nama lama
        // beda dari master saat ini (audit Umur Hutang 2026-09-29). Tagihan tanpa supplier_id
        // (data sebelum master Supplier ada) tetap dikelompokkan per nama sebagai fallback.
        $query = DB::table('payables')
            ->whereIn('payables.status', ['unpaid', 'partial'])
            ->leftJoin('suppliers', 'suppliers.id', '=', 'payables.supplier_id')
            ->selectRaw("
                MIN(payables.supplier_id) as supplier_id,
                COALESCE(MIN(suppliers.name), MIN(payables.supplier_name), '—') as supplier,
                SUM(CASE WHEN payables.due_date IS NULL OR payables.due_date >= CURDATE() THEN payables.amount - payables.amount_paid ELSE 0 END) as current_amt,
                SUM(CASE WHEN payables.due_date < CURDATE() AND DATEDIFF(CURDATE(), payables.due_date) <= 30 THEN payables.amount - payables.amount_paid ELSE 0 END) as b1,
                SUM(CASE WHEN payables.due_date < CURDATE() AND DATEDIFF(CURDATE(), payables.due_date) BETWEEN 31 AND 60 THEN payables.amount - payables.amount_paid ELSE 0 END) as b2,
                SUM(CASE WHEN payables.due_date < CURDATE() AND DATEDIFF(CURDATE(), payables.due_date) BETWEEN 61 AND 90 THEN payables.amount - payables.amount_paid ELSE 0 END) as b3,
                SUM(CASE WHEN payables.due_date < CURDATE() AND DATEDIFF(CURDATE(), payables.due_date) > 90 THEN payables.amount - payables.amount_paid ELSE 0 END) as b4,
                SUM(payables.amount - payables.amount_paid) as total
            ")
            ->groupByRaw("COALESCE(payables.supplier_id, CONCAT('name:', payables.supplier_name))")
            ->orderByDesc('total');

        $storeId = $this->storeId();
        if ($storeId === FinancialStatementService::COMPANY_WIDE) {
            $query->whereNull('payables.store_id');
        } elseif ($storeId !== null) {
            $query->where('payables.store_id', $storeId);
        }

        $rows = $query->get();

        $totals = [];
        foreach (['current_amt', 'b1', 'b2', 'b3', 'b4', 'total'] as $col) {
            $totals[$col] = (float) $rows->sum($col);
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
            'supplier_count' => $rows->count(),
            'overdue_count' => $rows->filter(fn ($r) => ((float) $r->b1 + (float) $r->b2 + (float) $r->b3 + (float) $r->b4) > 0.005)->count(),
            'over_90_pct' => $totals['total'] > 0.005 ? round($totals['b4'] / $totals['total'] * 100, 1) : 0.0,
        ];
    }

    /** Link drill-down ke daftar Hutang Usaha 1 supplier -- filter akun kalau tertaut master, cari nama kalau belum. */
    public function payableUrl(?int $supplierId, string $supplierName): string
    {
        if ($supplierId) {
            return PayableResource::getUrl('index', ['tableFilters' => ['supplier_id' => ['value' => $supplierId]]]);
        }

        return PayableResource::getUrl('index') . '?tableSearch=' . urlencode($supplierName);
    }
}
