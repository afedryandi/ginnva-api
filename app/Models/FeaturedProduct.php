<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class FeaturedProduct extends Model
{
    // Audit trail (audit Seri Produk 2026-09-29) -- SEBELUMNYA perubahan kartu (gambar, link,
    // aktif/nonaktif) tidak tercatat sama sekali, sama gap yang sudah diperbaiki di Carousel.
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'subtitle', 'image', 'content_image', 'link_url', 'is_active', 'sort_order'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('featured_product');
    }

    protected $fillable = [
        'title',
        'subtitle',
        'image',
        'content_image',
        'link_url',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
