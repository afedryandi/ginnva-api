<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Carousel extends Model
{
    // Audit trail (audit Banner/Carousel 2026-09-29) -- SEBELUMNYA perubahan banner (ganti
    // gambar, ubah link, matikan/aktifkan) tidak tercatat sama sekali, beda dari kebanyakan model
    // konten lain yang sudah disapu audit trail-nya.
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'subtitle', 'image', 'alt_text', 'link_url', 'audience', 'is_active', 'starts_at', 'ends_at', 'sort_order'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('carousel');
    }

    protected $fillable = [
        'title',
        'subtitle',
        'image',
        'alt_text',
        'link_url',
        'audience',
        'is_active',
        'starts_at',
        'ends_at',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /**
     * Banner yang boleh tayang sekarang: aktif dan di dalam jendela jadwalnya (Marketing/Konten Fase 2). Jadwal kosong = tanpa batas,
     * jadi banner lama tetap tayang seperti sebelumnya.
     */
    public function scopeLive($query)
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    /** Status tayang untuk panel: nonaktif / terjadwal / tayang / berakhir. */
    public function airingStatus(): string
    {
        if (! $this->is_active) {
            return 'nonaktif';
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return 'terjadwal';
        }

        if ($this->ends_at && $this->ends_at->isPast()) {
            return 'berakhir';
        }

        return 'tayang';
    }
}
