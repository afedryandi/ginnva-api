<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Catatan pembagian APD/perlengkapan kerja kepada seorang karyawan (Karyawan Fase 5, 2026-11-02). Jatuh tempo penggantian diisi otomatis
 * dari umur pakai jenisnya bila tidak diisi. Toko diambil dari toko karyawan saat dibuat.
 */
class PpeIssuance extends Model
{
    use HasStoreScope;
    use LogsActivity;

    protected $fillable = ['user_id', 'store_id', 'ppe_type_id', 'quantity', 'issued_on', 'replace_due_on', 'returned_on', 'notes', 'issued_by'];

    protected $casts = ['issued_on' => 'date', 'replace_due_on' => 'date', 'returned_on' => 'date', 'quantity' => 'integer'];

    protected static function booted(): void
    {
        static::creating(function (PpeIssuance $issuance) {
            if (empty($issuance->store_id) && $issuance->user_id) {
                $issuance->store_id = User::whereKey($issuance->user_id)->value('store_id');
            }

            if (empty($issuance->replace_due_on) && $issuance->issued_on) {
                $months = PpeType::whereKey($issuance->ppe_type_id)->value('replace_after_months');

                if ($months) {
                    $issuance->replace_due_on = Carbon::parse($issuance->issued_on)->addMonths((int) $months)->toDateString();
                }
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['user_id', 'ppe_type_id', 'quantity', 'issued_on', 'replace_due_on', 'returned_on'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('ppe_issuance');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(PpeType::class, 'ppe_type_id');
    }

    /** Masih dipegang karyawan dan sudah lewat jatuh tempo penggantian. */
    public function isOverdue(): bool
    {
        return $this->returned_on === null && $this->replace_due_on !== null && $this->replace_due_on->lte(today());
    }
}
