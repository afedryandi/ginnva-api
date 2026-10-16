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
            ->logOnly(['title', 'department', 'location', 'type', 'is_published', 'posted_at', 'valid_through', 'sort_order'])
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
        'posted_at',
        'valid_through',
        'is_published',
        'sort_order',
    ];

    protected $casts = [
        'requirements' => 'array',
        'is_published' => 'boolean',
        'posted_at'    => 'datetime',
        'valid_through' => 'date',
        'sort_order'   => 'integer',
    ];

    /**
     * Lowongan yang boleh tampil di publik: dipublikasikan dan belum melewati batas berlaku (Marketing/Konten Fase 4). Batas kosong =
     * tanpa batas. Hari terakhir masih berlaku.
     */
    public function scopeOpen($query)
    {
        return $query->where('is_published', true)
            ->where(fn ($q) => $q->whereNull('valid_through')->orWhereDate('valid_through', '>=', now()->toDateString()));
    }

    /** Status untuk panel: draft / tayang / berakhir. */
    public function listingStatus(): string
    {
        if (! $this->is_published) {
            return 'draft';
        }

        return $this->valid_through && $this->valid_through->lt(now()->startOfDay()) ? 'berakhir' : 'tayang';
    }
}
