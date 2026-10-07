<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Material extends Model
{
    // Audit trail (audit Materi Download 2026-09-29) -- SEBELUMNYA perubahan materi (file, nama,
    // kategori, status publik) tidak tercatat sama sekali.
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['material_category_id', 'name', 'file_type', 'is_active', 'sort_order'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('material');
    }

    protected $fillable = [
        'material_category_id',
        'name',
        'file',
        'file_type',
        'file_size',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * file_type & file_size dihitung dari file yang BENAR-BENAR tersimpan, bukan dari
     * afterStateUpdated() form: di sana yang tersedia baru path file sementara upload
     * (tipe kosong, ukuran tidak ketemu), jadi aplikasi selalu menampilkan ukuran "—".
     */
    protected static function booted(): void
    {
        static::saving(function (Material $material) {
            if (! $material->file || ($material->exists && ! $material->isDirty('file'))) {
                return;
            }

            $material->file_type = strtolower(pathinfo($material->file, PATHINFO_EXTENSION)) ?: null;

            $disk = Storage::disk('public');
            if ($disk->exists($material->file)) {
                $material->file_size = $disk->size($material->file);
            }
        });
    }

    public function category()
    {
        return $this->belongsTo(MaterialCategory::class, 'material_category_id');
    }

    public function getFileSizeFormattedAttribute(): string
    {
        if (! $this->file_size) return '—';

        $units = ['B', 'KB', 'MB', 'GB'];
        $size  = $this->file_size;
        $i     = 0;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 1) . ' ' . $units[$i];
    }
}
