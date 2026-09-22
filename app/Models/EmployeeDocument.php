<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Dokumen personalia karyawan (audit Majoo f54) — lihat migrasi
 * create_employee_documents_table. `file_path` menunjuk ke disk
 * PRIVATE (local), dibaca lewat aksi "Download" terautentikasi, bukan
 * URL publik.
 */
class EmployeeDocument extends Model
{
    use LogsActivity;

    public const TYPE_LABELS = [
        'ktp' => 'KTP',
        'npwp' => 'NPWP',
        'ijazah' => 'Ijazah',
        'kontrak_kerja' => 'Kontrak Kerja',
        'sertifikat' => 'Sertifikat/Pelatihan',
        'lainnya' => 'Lainnya',
    ];

    protected $fillable = [
        'user_id',
        'type',
        'file_path',
        'original_filename',
        'notes',
        'uploaded_by',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'original_filename'])
            ->dontSubmitEmptyLogs()
            ->useLogName('employee_document')
            ->setDescriptionForEvent(fn (string $eventName) => 'Dokumen "' . (self::TYPE_LABELS[$this->type] ?? $this->type) . "\" untuk {$this->user?->name} {$eventName}");
    }
}
