<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CustomerGalleryPhoto extends Model
{
    use LogsActivity;

    protected $fillable = [
        'customer_id',
        'image',
        'caption',
        'is_featured',
        'sort_order',
        'uploaded_by',
    ];

    protected $casts = [
        'is_featured' => 'boolean',
        'sort_order'  => 'integer',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['customer_id', 'caption', 'is_featured', 'sort_order'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('customer_gallery_photo')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Foto galeri customer #{$this->customer_id} ditambahkan",
                'updated' => "Foto galeri customer #{$this->customer_id} diubah",
                'deleted' => "Foto galeri customer #{$this->customer_id} dihapus",
                default   => "Foto galeri customer #{$this->customer_id} — {$eventName}",
            });
    }
}
