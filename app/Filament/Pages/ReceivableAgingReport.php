<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ReceivableResource;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Umur Piutang (Aging Receivable) per customer: sisa piutang aktif dikelompokkan menurut
 * keterlambatan dari jatuh tempo (audit Piutang Usaha 2026-09-29). Hanya membaca data;
 * non-full-access dibatasi ke toko sendiri.
 */
class ReceivableAgingReport extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    protected static ?string $navigationLabel = 'Umur Piutang';

    protected static ?string $title = 'Umur Piutang (Aging)';

    protected static ?int $navigationSort = 17;

    protected static string $view = 'filament.pages.receivable-aging';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea() && $user->hasMenuAccess(ReceivableResource::class);
    }

    /**
     * @return array{rows: \Illuminate\Support\Collection, totals: array<string, float>}
     */
    public function getAging(): array
    {
        $user = auth()->user();

        $query = DB::table('receivables')
            ->whereIn('status', ['unpaid', 'partial'])
            ->selectRaw("
                COALESCE(customer_name, '—') as customer,
                SUM(CASE WHEN due_date IS NULL OR due_date >= CURDATE() THEN amount - amount_paid ELSE 0 END) as current_amt,
                SUM(CASE WHEN due_date < CURDATE() AND DATEDIFF(CURDATE(), due_date) <= 30 THEN amount - amount_paid ELSE 0 END) as b1,
                SUM(CASE WHEN due_date < CURDATE() AND DATEDIFF(CURDATE(), due_date) BETWEEN 31 AND 60 THEN amount - amount_paid ELSE 0 END) as b2,
                SUM(CASE WHEN due_date < CURDATE() AND DATEDIFF(CURDATE(), due_date) BETWEEN 61 AND 90 THEN amount - amount_paid ELSE 0 END) as b3,
                SUM(CASE WHEN due_date < CURDATE() AND DATEDIFF(CURDATE(), due_date) > 90 THEN amount - amount_paid ELSE 0 END) as b4,
                SUM(amount - amount_paid) as total
            ")
            ->groupBy('customer_name')
            ->orderByDesc('total');

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        $rows = $query->get();

        $totals = [];
        foreach (['current_amt', 'b1', 'b2', 'b3', 'b4', 'total'] as $col) {
            $totals[$col] = (float) $rows->sum($col);
        }

        return ['rows' => $rows, 'totals' => $totals];
    }
}
