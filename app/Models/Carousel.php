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
            ->logOnly(['title', 'subtitle', 'image', 'link_url', 'audience', 'is_active', 'sort_order'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('carousel');
    }

    protected $fillable = [
        'title',
        'subtitle',
        'image',
        'link_url',
        'audience',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
