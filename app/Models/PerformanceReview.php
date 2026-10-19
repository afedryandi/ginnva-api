<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Penilaian kinerja berkala (Karyawan Fase 5, 2026-11-02): empat aspek skor 1-5 dan catatan pembinaan. Untuk pembinaan, BUKAN dasar
 * gaji atau potongan. Karyawan dapat membaca penilaian miliknya di aplikasi dan menyatakan sudah membaca. Satu penilaian per periode.
 */
class PerformanceReview extends Model
{
    use HasStoreScope;
    use LogsActivity;

    public const ASPECTS = [
        'score_quality' => 'Kualitas kerja',
        'score_discipline' => 'Kedisiplinan',
        'score_teamwork' => 'Kerja sama',
        'score_initiative' => 'Inisiatif',
    ];

    public const SCORES = [1 => '1 - Perlu perbaikan besar', 2 => '2 - Di bawah harapan', 3 => '3 - Sesuai harapan', 4 => '4 - Baik', 5 => '5 - Sangat baik'];

    protected $fillable = [
        'user_id', 'store_id', 'period_label', 'reviewed_on', 'reviewer_id',
        'score_quality', 'score_discipline', 'score_teamwork', 'score_initiative',
        'strengths', 'improvements', 'goals', 'acknowledged_at',
    ];

    protected $casts = ['reviewed_on' => 'date', 'acknowledged_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (PerformanceReview $review) {
            if (empty($review->store_id) && $review->user_id) {
                $review->store_id = User::whereKey($review->user_id)->value('store_id');
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['user_id', 'period_label', 'reviewed_on', 'score_quality', 'score_discipline', 'score_teamwork', 'score_initiative'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('performance_review');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function average(): float
    {
        return round(collect(array_keys(self::ASPECTS))->sum(fn (string $aspect) => (int) $this->{$aspect}) / count(self::ASPECTS), 2);
    }
}
