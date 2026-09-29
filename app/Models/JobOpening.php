<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class JobOpening extends Model
{
    use HasFactory;

    // Audit trail (audit Lowongan Kerja 2026-09-29) -- SEBELUMNYA perubahan lowongan (judul,
    // deskripsi, kualifikasi, publish/unpublish) tidak tercatat sama sekali.
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'department', 'location', 'type', 'is_published', 'sort_order'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('job_opening');
    }

    protected $fillable = [
        'title',
        'department',
        'location',
        'type',
        'description',
        'requirements',
        'is_published',
        'sort_order',
    ];

    protected $casts = [
        'requirements' => 'array',
        'is_published' => 'boolean',
        'sort_order'   => 'integer',
    ];
}
