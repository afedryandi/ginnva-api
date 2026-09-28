<?php

namespace App\Filament\Pages;

use App\Filament\Resources\PayableResource;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Umur Hutang (Aging Payable) per supplier: sisa tagihan aktif dikelompokkan
 * menurut keterlambatan dari jatuh tempo (audit Hutang Usaha 2026-09-29).
 * Hanya membaca data; non-full-access dibatasi ke toko sendiri.
 */
class PayableAgingReport extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    protected static ?string $navigationLabel = 'Umur Hutang';

    protected static ?string $title = 'Umur Hutang (Aging)';

    protected static ?int $navigationSort = 15;

    protected static string $view = 'filament.pages.payable-aging';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea() && $user->hasMenuAccess(PayableResource::class);
    }

    /**
     * @return array{rows: \Illuminate\Support\Collection, totals: array<string, float>}
     */
    public function getAging(): array
    {
        $user = auth()->user();

        $query = DB::table('payables')
            ->whereIn('status', ['unpaid', 'partial'])
            ->selectRaw("
                COALESCE(supplier_name, '—') as supplier,
                SUM(CASE WHEN due_date IS NULL OR due_date >= CURDATE() THEN amount - amount_paid ELSE 0 END) as current_amt,
                SUM(CASE WHEN due_date < CURDATE() AND DATEDIFF(CURDATE(), due_date) <= 30 THEN amount - amount_paid ELSE 0 END) as b1,
                SUM(CASE WHEN due_date < CURDATE() AND DATEDIFF(CURDATE(), due_date) BETWEEN 31 AND 60 THEN amount - amount_paid ELSE 0 END) as b2,
                SUM(CASE WHEN due_date < CURDATE() AND DATEDIFF(CURDATE(), due_date) BETWEEN 61 AND 90 THEN amount - amount_paid ELSE 0 END) as b3,
                SUM(CASE WHEN due_date < CURDATE() AND DATEDIFF(CURDATE(), due_date) > 90 THEN amount - amount_paid ELSE 0 END) as b4,
                SUM(amount - amount_paid) as total
            ")
            ->groupBy('supplier_name')
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
