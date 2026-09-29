<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CaseStudy extends Model
{
    // Audit trail (audit Galeri Pemasangan 2026-09-29) -- SEBELUMNYA perubahan galeri (foto,
    // judul, kendaraan/produk terkait, aktif/nonaktif) tidak tercatat sama sekali, beda dari
    // CustomerGalleryPhoto (fitur serupa) yang sudah punya ini.
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['vehicle_id', 'film_product_id', 'title', 'short_title', 'sort_order', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('case_study');
    }

    protected $fillable = [
        'vehicle_id',
        'film_product_id',
        'title',
        'short_title',
        'image',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function filmProduct()
    {
        return $this->belongsTo(FilmProduct::class);
    }
}