<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 1 akun COA yang dipin 1 user sebagai kartu KPI custom di Laporan
 * Keuangan — lihat migrasi create_finance_dashboard_widgets_table.
 */
class FinanceDashboardWidget extends Model
{
    protected $fillable = [
        'user_id',
        'chart_of_account_id',
        'sort_order',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }
}
