<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Izin pelanggan memakai foto pekerjaan untuk pemasaran (Marketing/Konten Fase 3); lihat migrasi create_marketing_consents_table.
 * Izin aktif = belum ditarik. Menarik izin menonaktifkan semua materi galeri yang bersumber dari booking itu.
 */
class MarketingConsent extends Model
{
    use LogsActivity;

    public const SCOPE_JOB_PHOTOS = 'foto_pekerjaan';

    protected $fillable = [
        'booking_id', 'customer_id', 'scope', 'granted_at', 'recorded_by_user_id',
        'withdrawn_at', 'withdrawn_by_user_id', 'withdrawn_note',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'withdrawn_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['booking_id', 'scope', 'granted_at', 'withdrawn_at', 'withdrawn_note'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('marketing_consent');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('withdrawn_at');
    }

    public function isActive(): bool
    {
        return $this->withdrawn_at === null;
    }

    /** Tarik izin: tercatat siapa dan kapan; materi galeri yang bersumber dari izin ini ikut dinonaktifkan. */
    public function withdraw(?User $by = null, ?string $note = null): void
    {
        if (! $this->isActive()) {
            return;
        }

        $this->update(['withdrawn_at' => now(), 'withdrawn_by_user_id' => $by?->id, 'withdrawn_note' => $note]);

        CaseStudy::where('marketing_consent_id', $this->id)->update(['is_active' => false]);
    }
}
