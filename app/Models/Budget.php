<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Anggaran satu akun untuk satu bulan (Keuangan Fase 4, 2026-11-07), untuk seluruh perusahaan atau satu toko. Dibandingkan dengan realisasi
 * dari jurnal di laporan Anggaran vs Realisasi. Hanya peringatan: melebihi anggaran tidak memblokir transaksi.
 */
class Budget extends Model
{
    use HasStoreScope;
    use LogsActivity;

    protected $fillable = ['period_month', 'store_id', 'chart_of_account_id', 'amount', 'notes', 'created_by'];

    protected $casts = ['period_month' => 'date', 'amount' => 'decimal:2'];

    protected static function booted(): void
    {
        // Selalu disimpan sebagai tanggal 1 bulan itu.
        static::saving(function (Budget $budget) {
            $budget->period_month = \Illuminate\Support\Carbon::parse($budget->period_month)->startOfMonth()->toDateString();

            // Indeks unik database tidak menjaga lingkup perusahaan (store_id kosong dianggap berbeda-beda oleh MySQL), jadi dicek di sini.
            $duplicate = static::withoutGlobalScopes()
                ->where('period_month', $budget->period_month)
                ->where('chart_of_account_id', $budget->chart_of_account_id)
                ->when($budget->store_id, fn ($q, $store) => $q->where('store_id', $store), fn ($q) => $q->whereNull('store_id'))
                ->when($budget->exists, fn ($q) => $q->where('id', '!=', $budget->id))
                ->exists();

            if ($duplicate) {
                throw new \RuntimeException('Anggaran untuk akun, bulan, dan toko ini sudah ada.');
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['period_month', 'store_id', 'chart_of_account_id', 'amount'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('budget');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
