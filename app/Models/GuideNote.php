<?php

namespace App\Models;

use App\Support\GuideCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Catatan perusahaan di bawah satu panduan (Panduan Fase 4): aturan toko, batas, atau kebijakan yang melengkapi langkah di sistem. Isinya
 * diubah pemilik di panel tanpa rilis dan tampil di bawah panduan terkait, di panel maupun aplikasi staf. Tercatat di Histori Aktivitas.
 */
class GuideNote extends Model
{
    use LogsActivity;

    protected $fillable = ['guide_id', 'body', 'updated_by'];

    protected static function booted(): void
    {
        // Pengubah terakhir dicatat otomatis, tidak bergantung pada formulir.
        static::saving(function (GuideNote $note) {
            if (auth()->check()) {
                $note->updated_by = auth()->id();
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['guide_id', 'body'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('guide_note');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Judul panduan yang dicatatkan; id yang sudah tidak ada di katalog tampil apa adanya. */
    public function guideTitle(): string
    {
        $guide = collect(GuideCatalog::all())->firstWhere('id', $this->guide_id);

        return $guide['title'] ?? $this->guide_id;
    }
}
