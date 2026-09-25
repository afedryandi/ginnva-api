<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class BlockedDate extends Model
{
    // Global Scope store-id (audit framework 2026-09-14, "Isolasi data
    // multi-tenant") — lihat App\Models\Scopes\StoreScope. Pola manual
    // yang sudah ada di BlockedDateResource::getEloquentQuery() SENGAJA
    // DIBIARKAN sebagai defense-in-depth.
    use HasStoreScope;

    // BUG DIPERBAIKI 2026-09-25 (audit Tanggal Tidak Tersedia) --
    // SEBELUMNYA tidak ada jejak audit sama sekali di model ini, beda
    // dari StoreCapacityOverride/Technician yang sudah dapat trait ini.
    // Menghapus baris blocked-date otomatis "membuka" tanggal itu lagi
    // untuk booking baru (Store::isClosedOn() cuma cek baris yang MASIH
    // ada) -- dampaknya langsung ke kapasitas booking, jadi siapa/kapan
    // baris ini dibuat/dihapus perlu tercatat sama seriusnya.
    use LogsActivity;

    protected $fillable = ['store_id', 'date', 'reason'];

    protected $casts = ['date' => 'date'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['store_id', 'date', 'reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('blocked_date')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Tanggal {$this->date?->format('d M Y')} diblokir",
                'updated' => "Blokir tanggal {$this->date?->format('d M Y')} diubah",
                'deleted' => "Blokir tanggal {$this->date?->format('d M Y')} dihapus (tanggal kembali tersedia)",
                default   => "Blokir tanggal — {$eventName}",
            });
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
