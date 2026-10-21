<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Templat pesan untuk menu Kirim Notifikasi (judul, isi, kategori, dan deep link yang dipakai ulang). */
class NotificationTemplate extends Model
{
    use LogsActivity;

    protected $fillable = ['name', 'audience', 'category', 'title', 'body', 'data', 'created_by'];

    protected $casts = ['data' => 'array'];

    /** Histori Aktivitas Fase 3: isi pesan yang dipakai ulang untuk pelanggan/partner, jadi perubahannya perlu jejak. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'audience', 'category', 'title', 'body'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('notification_template')
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => "Templat notifikasi \"{$this->name}\" dibuat",
                'updated' => "Templat notifikasi \"{$this->name}\" diubah",
                'deleted' => "Templat notifikasi \"{$this->name}\" dihapus",
                default => "Templat notifikasi \"{$this->name}\" - {$event}",
            });
    }
}
